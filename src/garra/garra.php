<?php
/**
 * Garra — The Core Agent Engine
 * ==============================
 * Manages the think → act → observe loop.
 *
 * Responsibilities:
 *  1. Auto-discover and load skills from the /skills directory.
 *  2. Resolve and instantiate the correct LLM driver.
 *  3. Run the agent loop: call LLM → execute tool (if requested) → repeat.
 *  4. Return a final text answer or a structured error.
 *
 * The engine is intentionally stateless per-request. Persistence across
 * requests is handled externally via the storage/ folder (see index.php).
 */
if (!defined('GARRA_EXEC')) exit;

require_once __DIR__ . '/drivers/LLMDriver.php';

// ─── Global config accessor ───────────────────────────────────────────────────
// Skills call garra_get_config() to access credentials without re-loading
// config.php. Populated once by the Garra constructor.
$_GARRA_CONFIG = [];
function garra_get_config(): array { global $_GARRA_CONFIG; return $_GARRA_CONFIG; }
function garra_set_config(array $c): void { global $_GARRA_CONFIG; $_GARRA_CONFIG = $c; }

class Garra
{
    private array  $config;
    private array  $history   = [];
    private array  $skills    = [];   // ['name' => ['definition' => [...], 'file' => '...']]
    private LLMDriver $driver;

    public function __construct(array $config)
    {
        $this->config = $config;
        garra_set_config($config);
        $this->driver = $this->resolveDriver();
        $this->discoverSkills();
    }

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Run the agent against a goal string.
     * Optionally seed with existing conversation history (for multi-turn sessions).
     *
     * @param  string $goal     The user's request.
     * @param  array  $history  Prior conversation messages (Garra internal format).
     * @return array            ['success' => bool, 'response' => string, 'history' => array]
     */
    public function run(string $goal, array $history = []): array
    {
        $this->history = $history;
        $this->history[] = ['role' => 'user', 'content' => $goal];

        $maxIterations = (int)($this->config['settings']['max_iterations'] ?? 5);
        $tools         = $this->driver->formatTools($this->getToolDefinitions());
        $storageDir    = rtrim($this->config['settings']['storage_dir'] ?? '', '/');

        garra_log("Goal started: " . mb_substr($goal, 0, 80), 'info', $storageDir);

        for ($i = 0; $i < $maxIterations; $i++) {
            try {
                $response = $this->driver->chat($this->history, $tools);
            } catch (RuntimeException $e) {
                garra_log("LLM error: " . $e->getMessage(), 'err', $storageDir);
                return $this->errorResult('LLM communication failed: ' . $e->getMessage());
            }

            // ------------------------------------------------------------------
            // Case 1: LLM wants to call a tool
            // ------------------------------------------------------------------
            if (!empty($response['tool_calls'])) {

                // Record the assistant's tool-call turn in history
                $this->history[] = [
                    'role'           => 'assistant_tool_call',
                    'content'        => null,
                    'tool_calls_raw' => $response['tool_calls_raw'] ?? [],
                ];

                // Execute each requested tool and record results
                foreach ($response['tool_calls'] as $call) {
                    garra_log("Tool call: " . $call['name'] . '(' . mb_substr(json_encode($call['arguments']), 0, 60) . ')', 'tool', $storageDir);
                    $result = $this->executeSkill($call['name'], $call['arguments']);
                    garra_log("Tool result: " . $call['name'] . ' → ' . mb_substr($result, 0, 80), 'ok', $storageDir);

                    $this->history[] = [
                        'role'         => 'tool',
                        'tool_call_id' => $call['id'],
                        'name'         => $call['name'],
                        'content'      => $result,
                    ];
                }

                continue;
            }

            // ------------------------------------------------------------------
            // Case 2: LLM returned a final text answer
            // ------------------------------------------------------------------
            $this->history[] = [
                'role'    => 'assistant',
                'content' => $response['content'],
            ];

            garra_log("Goal done: " . mb_substr($response['content'] ?? '', 0, 80), 'ok', $storageDir);

            return [
                'success'  => true,
                'response' => $response['content'],
                'history'  => $this->history,
            ];
        }

        garra_log("Goal hit iteration limit: " . mb_substr($goal, 0, 60), 'warn', $storageDir);

        return $this->errorResult(
            "Reached the maximum of {$maxIterations} iterations without a final answer.",
            $this->history
        );
    }

    /**
     * Return the list of discovered skill names (useful for debugging endpoints).
     */
    public function getSkillNames(): array
    {
        return array_keys($this->skills);
    }

    // =========================================================================
    // Driver resolution
    // =========================================================================

    private function resolveDriver(): LLMDriver
    {
        $provider   = strtolower($this->config['provider'] ?? 'openai');
        $driverFile = __DIR__ . '/drivers/' . ucfirst($provider) . 'Driver.php';

        if (!file_exists($driverFile)) {
            throw new RuntimeException(
                "No driver found for provider '{$provider}'. " .
                "Expected file: {$driverFile}"
            );
        }

        require_once $driverFile;

        $class = ucfirst($provider) . 'Driver';
        return new $class($this->config);
    }

    // =========================================================================
    // Skill auto-discovery
    // =========================================================================

    /**
     * Scan the skills directory and load each valid skill file.
     *
     * A valid skill file must define two functions namespaced with the skill
     * filename (snake_case):
     *   - {skill}_definition() → array   (tool schema)
     *   - {skill}_execute(array $args)    (the actual logic)
     *
     * Example for skills/weather.php:
     *   function weather_definition(): array { ... }
     *   function weather_execute(array $args): string { ... }
     */
    private function discoverSkills(): void
    {
        $dir = rtrim($this->config['settings']['skills_dir'], '/');

        if (!is_dir($dir)) {
            return; // No skills directory — run tool-free
        }

        foreach (glob($dir . '/*.php') as $file) {
            $name = basename($file, '.php'); // e.g. "weather"

            // Validate the name is a safe identifier
            if (!preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
                continue;
            }

            require_once $file;

            $defFn  = $name . '_definition';
            $execFn = $name . '_execute';

            if (!function_exists($defFn) || !function_exists($execFn)) {
                // Skill file is malformed — skip silently (logged below if storage is on)
                error_log("GarraPHP: Skill '{$name}' missing {$defFn}() or {$execFn}().");
                continue;
            }

            $definition = $defFn();

            // Basic schema validation
            if (empty($definition['name']) || empty($definition['description'])) {
                error_log("GarraPHP: Skill '{$name}' definition is missing 'name' or 'description'.");
                continue;
            }

            $this->skills[$definition['name']] = [
                'definition' => $definition,
                'execute_fn' => $execFn,
            ];
        }
    }

    /**
     * Return just the definition portion of each skill for the LLM.
     */
    private function getToolDefinitions(): array
    {
        return array_map(fn($s) => $s['definition'], array_values($this->skills));
    }

    // =========================================================================
    // Skill execution
    // =========================================================================

    private function executeSkill(string $name, array $arguments): string
    {
        if (!isset($this->skills[$name])) {
            return json_encode([
                'error' => "Unknown skill '{$name}'. Available: " .
                           implode(', ', array_keys($this->skills)),
            ]);
        }

        try {
            $fn     = $this->skills[$name]['execute_fn'];
            $result = $fn($arguments);

            // Skills may return a string or an array; normalise to JSON string
            return is_string($result) ? $result : json_encode($result);

        } catch (Throwable $e) {
            error_log("GarraPHP: Skill '{$name}' threw: " . $e->getMessage());
            return json_encode(['error' => 'Skill execution failed: ' . $e->getMessage()]);
        }
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function errorResult(string $message, array $history = []): array
    {
        return [
            'success'  => false,
            'response' => $message,
            'history'  => $history ?: $this->history,
        ];
    }
}

// ── Global log writer ─────────────────────────────────────────────────────
// Writes a line to storage/activity.log (max 500 lines, trimmed automatically).
// type: info | ok | err | warn | tool
function garra_log(string $message, string $type = 'info', string $storageDir = ''): void
{
    if (!$storageDir) return;

    $logFile = rtrim($storageDir, '/') . '/activity.log';
    $line    = date('Y-m-d H:i:s') . ' [' . strtoupper($type) . '] ' . $message;

    file_put_contents($logFile, $line . "\n", FILE_APPEND | LOCK_EX);

    // Trim to last 500 lines
    if (file_exists($logFile) && filesize($logFile) > 40000) {
        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (count($lines) > 500) {
            file_put_contents($logFile, implode("\n", array_slice($lines, -500)) . "\n", LOCK_EX);
        }
    }
}
