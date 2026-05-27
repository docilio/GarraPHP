<?php
/**
 * Skill: database
 * ===============
 * Generic MySQL/MariaDB read and write access.
 * Supports any schema including WordPress.
 *
 * WordPress auto-detection: if 'wp_config_path' is set in config.php,
 * credentials are read directly from wp-config.php — no duplication needed.
 *
 * Security:
 *   - All queries use PDO prepared statements (no raw interpolation).
 *   - Write operations (INSERT/UPDATE/DELETE) are blocked when config readonly=true.
 *   - Table names are validated against an allowlist derived from the live schema.
 *   - The agent cannot DROP, TRUNCATE, ALTER, or execute multi-statement queries.
 *
 * Actions:
 *   query    — run a SELECT and return rows
 *   insert   — insert a row into a table
 *   update   — update rows matching a condition
 *   tables   — list available tables
 *   describe — return column info for a table
 */
if (!defined('GARRA_EXEC')) exit;

// ---------------------------------------------------------------------------
// Definition
// ---------------------------------------------------------------------------

function database_definition(): array
{
    return [
        'name'        => 'database',
        'description' => 'Read from or write to a MySQL/MariaDB database, including WordPress databases. Use to query posts, users, orders, or any custom table. Can list tables, describe structure, select rows, insert records, and update data. Always uses parameterised queries.',
        'parameters'  => [
            'type'       => 'object',
            'properties' => [
                'action' => [
                    'type'        => 'string',
                    'enum'        => ['query', 'insert', 'update', 'tables', 'describe'],
                    'description' => 'Operation to perform.',
                ],
                'table' => [
                    'type'        => 'string',
                    'description' => 'Table name. Required for insert, update, describe.',
                ],
                'sql' => [
                    'type'        => 'string',
                    'description' => 'SELECT statement. Required for action=query. No subqueries that modify data.',
                ],
                'params' => [
                    'type'        => 'array',
                    'items'       => ['type' => 'string'],
                    'description' => 'Positional parameters for the SQL query (replaces ? placeholders).',
                ],
                'data' => [
                    'type'        => 'object',
                    'description' => 'Key-value pairs for insert or update. Keys are column names.',
                ],
                'where' => [
                    'type'        => 'object',
                    'description' => 'Key-value conditions for update (WHERE col = val AND ...). Required for update.',
                ],
                'limit' => [
                    'type'        => 'integer',
                    'description' => 'Max rows to return for query (default 50, max 500).',
                ],
            ],
            'required' => ['action'],
        ],
    ];
}

// ---------------------------------------------------------------------------
// Execution
// ---------------------------------------------------------------------------

function database_execute(array $args): array
{
    $action = $args['action'] ?? '';

    try {
        $pdo    = database_connect();
        $config = database_load_config();

        switch ($action) {
            case 'tables':   return database_tables($pdo);
            case 'describe': return database_describe($pdo, $args);
            case 'query':    return database_query($pdo, $args);
            case 'insert':   return database_insert($pdo, $args, $config);
            case 'update':   return database_update($pdo, $args, $config);
            default:         return ['error' => "Unknown action '{$action}'."];
        }
    } catch (PDOException $e) {
        return ['error' => 'Database error: ' . $e->getMessage()];
    } catch (RuntimeException $e) {
        return ['error' => $e->getMessage()];
    }
}

// ---------------------------------------------------------------------------
// Actions
// ---------------------------------------------------------------------------

function database_tables(PDO $pdo): array
{
    $stmt = $pdo->query('SHOW TABLES');
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    return ['tables' => $tables, 'count' => count($tables)];
}

function database_describe(PDO $pdo, array $args): array
{
    $table = database_validate_table($pdo, $args['table'] ?? '');
    $stmt  = $pdo->query("DESCRIBE `{$table}`");
    return ['table' => $table, 'columns' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
}

function database_query(PDO $pdo, array $args): array
{
    $sql    = trim($args['sql'] ?? '');
    $params = $args['params'] ?? [];
    $limit  = min((int)($args['limit'] ?? 50), 500);

    if (!$sql) throw new RuntimeException('sql is required for action=query.');

    // Only allow SELECT statements
    if (!preg_match('/^\s*SELECT\s/i', $sql)) {
        throw new RuntimeException('Only SELECT statements are allowed in action=query. Use insert/update for writes.');
    }

    // Block dangerous keywords even inside SELECT
    $blocked = ['INTO OUTFILE', 'INTO DUMPFILE', 'LOAD_FILE', 'SLEEP(', 'BENCHMARK('];
    foreach ($blocked as $b) {
        if (stripos($sql, $b) !== false) {
            throw new RuntimeException("Blocked keyword detected: {$b}");
        }
    }

    // Append LIMIT if not already present
    if (!preg_match('/\bLIMIT\b/i', $sql)) {
        $sql .= " LIMIT {$limit}";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($params));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return ['count' => count($rows), 'rows' => $rows];
}

function database_insert(PDO $pdo, array $args, array $config): array
{
    if ($config['readonly'] ?? false) {
        throw new RuntimeException('Database is configured as readonly. Set readonly=false in config.php to allow writes.');
    }

    $table = database_validate_table($pdo, $args['table'] ?? '');
    $data  = $args['data'] ?? [];

    if (empty($data)) throw new RuntimeException('data is required for action=insert.');

    database_validate_columns($pdo, $table, array_keys($data));

    $cols        = implode('`, `', array_keys($data));
    $placeholders = implode(', ', array_fill(0, count($data), '?'));
    $sql          = "INSERT INTO `{$table}` (`{$cols}`) VALUES ({$placeholders})";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($data));

    return [
        'success'       => true,
        'inserted_id'   => $pdo->lastInsertId(),
        'affected_rows' => $stmt->rowCount(),
    ];
}

function database_update(PDO $pdo, array $args, array $config): array
{
    if ($config['readonly'] ?? false) {
        throw new RuntimeException('Database is configured as readonly. Set readonly=false in config.php to allow writes.');
    }

    $table = database_validate_table($pdo, $args['table'] ?? '');
    $data  = $args['data']  ?? [];
    $where = $args['where'] ?? [];

    if (empty($data))  throw new RuntimeException('data is required for action=update.');
    if (empty($where)) throw new RuntimeException('where is required for action=update to prevent accidental full-table updates.');

    database_validate_columns($pdo, $table, array_merge(array_keys($data), array_keys($where)));

    $setClauses   = implode(', ', array_map(fn($k) => "`{$k}` = ?", array_keys($data)));
    $whereClauses = implode(' AND ', array_map(fn($k) => "`{$k}` = ?", array_keys($where)));
    $sql          = "UPDATE `{$table}` SET {$setClauses} WHERE {$whereClauses}";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge(array_values($data), array_values($where)));

    return [
        'success'       => true,
        'affected_rows' => $stmt->rowCount(),
    ];
}

// ---------------------------------------------------------------------------
// Connection & helpers
// ---------------------------------------------------------------------------

function database_connect(): PDO
{
    $config = database_load_config();

    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        $config['host'],
        $config['name'],
        $config['charset'] ?? 'utf8mb4'
    );

    return new PDO($dsn, $config['user'], $config['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
}

function database_load_config(): array
{
    static $cfg = null;
    if ($cfg !== null) return $cfg;

    $file = __DIR__ . '/../config.php';
    if (!file_exists($file)) throw new RuntimeException('config.php not found.');

    $full = require $file;
    $cfg  = $full['database'] ?? [];

    // WordPress auto-detection
    $wpPath = $cfg['wp_config_path'] ?? null;
    if ($wpPath && file_exists($wpPath)) {
        $cfg = array_merge($cfg, database_parse_wp_config($wpPath));
    }

    foreach (['host', 'name', 'user', 'pass'] as $key) {
        if (empty($cfg[$key])) {
            throw new RuntimeException("Database config missing '{$key}'. Set it in config.php or provide wp_config_path.");
        }
    }

    return $cfg;
}

/**
 * Extract DB credentials from a WordPress wp-config.php without executing it.
 * Uses regex on the raw file content — safe, no eval.
 */
function database_parse_wp_config(string $path): array
{
    $content = file_get_contents($path);
    $map     = [
        'name' => 'DB_NAME',
        'user' => 'DB_USER',
        'pass' => 'DB_PASSWORD',
        'host' => 'DB_HOST',
    ];
    $result  = [];

    foreach ($map as $key => $constant) {
        if (preg_match("/define\s*\(\s*['\"]" . $constant . "['\"]\s*,\s*['\"]([^'\"]+)['\"]/", $content, $m)) {
            $result[$key] = $m[1];
        }
    }

    // Table prefix
    if (preg_match("/\\\$table_prefix\s*=\s*['\"]([^'\"]+)['\"]/", $content, $m)) {
        $result['table_prefix'] = $m[1];
    }

    return $result;
}

function database_validate_table(PDO $pdo, string $table): string
{
    if (!$table) throw new RuntimeException('table name is required.');

    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array($table, $tables, true)) {
        throw new RuntimeException("Table '{$table}' does not exist. Use action=tables to list available tables.");
    }

    return $table;
}

function database_validate_columns(PDO $pdo, string $table, array $columns): void
{
    $stmt = $pdo->query("DESCRIBE `{$table}`");
    $valid = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');

    foreach ($columns as $col) {
        if (!in_array($col, $valid, true)) {
            throw new RuntimeException("Column '{$col}' does not exist in table '{$table}'.");
        }
    }
}
