<?php
/**
 * Plugin Name:  GarraPHP Agent
 * Plugin URI:   https://garra.3nhance.com
 * Description:  Embed the GarraPHP AI agent in any post or page via shortcode, and expose it via the WordPress REST API.
 * Version:      1.0.0
 * Author:       GarraPHP
 * License:      MIT
 *
 * ─── Usage ──────────────────────────────────────────────────────────────────
 *
 * Shortcode in any post/page:
 *   [garra]                          — full chat widget
 *   [garra placeholder="Ask me..."]  — custom placeholder
 *   [garra session="fixed-id"]       — shared session for all visitors
 *
 * WP REST API (for server-side or JS calls):
 *   POST /wp-json/garra/v1/run
 *   Headers: X-WP-Nonce: <nonce>  (or X-Garra-Key if you prefer)
 *   Body:    { "goal": "...", "session_id": "..." }
 *
 * ─── Configuration ──────────────────────────────────────────────────────────
 * Settings > GarraPHP in the WP admin.
 */

if (!defined('ABSPATH')) exit;

// ─── Admin settings ───────────────────────────────────────────────────────

add_action('admin_menu', function () {
    add_options_page('GarraPHP', 'GarraPHP', 'manage_options', 'garraphp', 'garraphp_settings_page');
});

add_action('admin_init', function () {
    register_setting('garraphp', 'garraphp_endpoint', ['sanitize_callback' => 'esc_url_raw']);
    register_setting('garraphp', 'garraphp_api_key',  ['sanitize_callback' => 'sanitize_text_field']);
    register_setting('garraphp', 'garraphp_timeout',  ['sanitize_callback' => 'absint']);
});

function garraphp_settings_page(): void
{
    ?>
    <div class="wrap">
    <h1>GarraPHP Agent Settings</h1>
    <form method="post" action="options.php">
        <?php settings_fields('garraphp'); ?>
        <table class="form-table">
            <tr>
                <th>Agent Endpoint URL</th>
                <td>
                    <input type="url" name="garraphp_endpoint"
                           value="<?= esc_attr(get_option('garraphp_endpoint', '')) ?>"
                           class="regular-text" placeholder="https://garra.yourdomain.com/index.php">
                    <p class="description">Full URL to your GarraPHP index.php.</p>
                </td>
            </tr>
            <tr>
                <th>API Key (X-Garra-Key)</th>
                <td>
                    <input type="password" name="garraphp_api_key"
                           value="<?= esc_attr(get_option('garraphp_api_key', '')) ?>"
                           class="regular-text" placeholder="Leave blank if auth is disabled">
                </td>
            </tr>
            <tr>
                <th>Request Timeout (seconds)</th>
                <td>
                    <input type="number" name="garraphp_timeout"
                           value="<?= esc_attr(get_option('garraphp_timeout', 30)) ?>"
                           min="5" max="120" style="width:80px">
                </td>
            </tr>
        </table>
        <?php submit_button(); ?>
    </form>
    </div>
    <?php
}

// ─── Core: call GarraPHP ──────────────────────────────────────────────────

function garraphp_run(string $goal, string $sessionId = ''): array
{
    $endpoint = get_option('garraphp_endpoint', '');
    $apiKey   = get_option('garraphp_api_key', '');
    $timeout  = (int)get_option('garraphp_timeout', 30);

    if (!$endpoint) {
        return ['success' => false, 'response' => 'GarraPHP endpoint not configured. Go to Settings > GarraPHP.'];
    }

    $body = ['goal' => $goal];
    if ($sessionId) $body['session_id'] = $sessionId;

    $headers = ['Content-Type' => 'application/json'];
    if ($apiKey) $headers['X-Garra-Key'] = $apiKey;

    $response = wp_remote_post($endpoint, [
        'timeout' => $timeout,
        'headers' => $headers,
        'body'    => wp_json_encode($body),
    ]);

    if (is_wp_error($response)) {
        return ['success' => false, 'response' => 'Connection error: ' . $response->get_error_message()];
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);

    return [
        'success'  => ($data['status'] ?? '') === 'success',
        'response' => $data['response'] ?? 'No response from agent.',
    ];
}

// ─── REST API endpoint ────────────────────────────────────────────────────

add_action('rest_api_init', function () {
    register_rest_route('garra/v1', '/run', [
        'methods'             => 'POST',
        'callback'            => 'garraphp_rest_run',
        'permission_callback' => 'garraphp_rest_permission',
        'args'                => [
            'goal'       => ['required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
            'session_id' => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
        ],
    ]);

    // Ping endpoint — useful for verifying the plugin is active
    register_rest_route('garra/v1', '/ping', [
        'methods'             => 'GET',
        'callback'            => fn() => new WP_REST_Response(['status' => 'ok', 'plugin' => 'GarraPHP'], 200),
        'permission_callback' => '__return_true',
    ]);
});

function garraphp_rest_permission(WP_REST_Request $request): bool|WP_Error
{
    // Allow logged-in users with a valid nonce, OR valid X-Garra-Key header
    if (current_user_can('read') && check_ajax_referer('wp_rest', false, false)) {
        return true;
    }

    // Alternatively accept the plugin's own API key for server-to-server calls
    $key        = $request->get_header('X-Garra-Key') ?? '';
    $configured = get_option('garraphp_api_key', '');
    if ($configured && $key && hash_equals($configured, $key)) {
        return true;
    }

    return new WP_Error('rest_forbidden', 'Authentication required.', ['status' => 401]);
}

function garraphp_rest_run(WP_REST_Request $request): WP_REST_Response
{
    $goal      = $request->get_param('goal');
    $sessionId = $request->get_param('session_id') ?? '';
    $result    = garraphp_run($goal, $sessionId);

    return new WP_REST_Response([
        'status'   => $result['success'] ? 'success' : 'error',
        'response' => $result['response'],
    ], $result['success'] ? 200 : 500);
}

// ─── Shortcode ────────────────────────────────────────────────────────────

add_shortcode('garra', 'garraphp_shortcode');

function garraphp_shortcode(array $atts): string
{
    $atts = shortcode_atts([
        'placeholder' => 'Ask me anything…',
        'session'     => '',         // fixed session ID for shared context
        'height'      => '420px',    // widget height
        'label'       => 'Agent',
    ], $atts, 'garra');

    // Per-visitor session: combine fixed ID with user identifier
    $sessionId = $atts['session']
        ? sanitize_text_field($atts['session'])
        : 'wp_' . substr(md5(wp_get_session_token() ?: ($_COOKIE['wordpress_test_cookie'] ?? uniqid())), 0, 12);

    $nonce   = wp_create_nonce('wp_rest');
    $restUrl = esc_url(rest_url('garra/v1/run'));
    $uid     = 'garra_' . wp_generate_password(6, false);

    ob_start(); ?>
    <div id="<?= esc_attr($uid) ?>" class="garra-widget" style="display:flex;flex-direction:column;height:<?= esc_attr($atts['height']) ?>;border:1px solid #ddd;border-radius:6px;overflow:hidden;font-family:sans-serif;">

      <!-- Chat history -->
      <div class="garra-log" style="flex:1;overflow-y:auto;padding:14px;background:#fafafa;display:flex;flex-direction:column;gap:10px;">
        <div style="color:#aaa;font-size:12px;text-align:center;">Start a conversation below ↓</div>
      </div>

      <!-- Input bar -->
      <div style="display:flex;border-top:1px solid #eee;background:#fff;">
        <textarea class="garra-input"
                  placeholder="<?= esc_attr($atts['placeholder']) ?>"
                  style="flex:1;border:none;outline:none;resize:none;padding:10px 12px;font-size:14px;font-family:inherit;height:54px;line-height:1.5;"
        ></textarea>
        <button class="garra-send"
                style="background:#f5a623;border:none;color:#000;font-weight:700;padding:0 18px;cursor:pointer;font-size:13px;letter-spacing:.05em;">
          Send
        </button>
      </div>

    </div>

    <script>
    (function () {
      var uid        = <?= json_encode($uid) ?>;
      var restUrl    = <?= json_encode($restUrl) ?>;
      var nonce      = <?= json_encode($nonce) ?>;
      var sessionId  = <?= json_encode($sessionId) ?>;
      var agentLabel = <?= json_encode($atts['label']) ?>;
      var busy       = false;

      var root  = document.getElementById(uid);
      var log   = root.querySelector('.garra-log');
      var input = root.querySelector('.garra-input');
      var btn   = root.querySelector('.garra-send');

      function addMsg(role, text) {
        var isUser = role === 'user';
        var div = document.createElement('div');
        div.style.cssText = 'max-width:80%;padding:9px 13px;border-radius:14px;font-size:14px;line-height:1.5;white-space:pre-wrap;word-break:break-word;'
          + (isUser ? 'align-self:flex-end;background:#f5a623;color:#000;border-bottom-right-radius:4px;'
                    : 'align-self:flex-start;background:#fff;border:1px solid #e0e0e0;color:#222;border-bottom-left-radius:4px;');
        div.textContent = text;
        log.appendChild(div);
        log.scrollTop = log.scrollHeight;
        return div;
      }

      function setLoading(state) {
        busy = state;
        btn.disabled = state;
        btn.textContent = state ? '…' : 'Send';
        input.disabled = state;
      }

      async function send() {
        var goal = input.value.trim();
        if (!goal || busy) return;
        addMsg('user', goal);
        input.value = '';
        setLoading(true);
        var thinking = addMsg('agent', '⋯ thinking');

        try {
          var res = await fetch(restUrl, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-WP-Nonce': nonce,
            },
            body: JSON.stringify({ goal: goal, session_id: sessionId }),
          });
          var data = await res.json();
          thinking.textContent = data.response || '(no response)';
          if (data.status !== 'success') {
            thinking.style.color = '#c00';
          }
        } catch (err) {
          thinking.textContent = 'Error: ' + err.message;
          thinking.style.color = '#c00';
        }

        setLoading(false);
      }

      btn.addEventListener('click', send);
      input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); }
      });
    })();
    </script>
    <?php
    return ob_get_clean();
}

// ─── WP-Cron integration (optional) ──────────────────────────────────────
// If you'd rather use WP-Cron than a server cron job, uncomment this block.
// It polls the GarraPHP cron endpoint every 5 minutes from within WordPress.

/*
add_filter('cron_schedules', function ($schedules) {
    $schedules['every5min'] = ['interval' => 300, 'display' => 'Every 5 Minutes'];
    return $schedules;
});

add_action('init', function () {
    if (!wp_next_scheduled('garraphp_cron_hook')) {
        wp_schedule_event(time(), 'every5min', 'garraphp_cron_hook');
    }
});

add_action('garraphp_cron_hook', function () {
    $endpoint = get_option('garraphp_endpoint', '');
    $secret   = get_option('garraphp_cron_secret', '');
    if ($endpoint && $secret) {
        $cronUrl = preg_replace('/index\.php$/', 'cron.php', $endpoint) . '?secret=' . urlencode($secret);
        wp_remote_get($cronUrl, ['timeout' => 55, 'blocking' => true]);
    }
});
*/
