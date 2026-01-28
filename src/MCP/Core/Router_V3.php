<?php
/**
 * Router V3 - Central request routing for MCP V3
 *
 * Handles the routing logic: V3 native → V2 translation → packs → unknown
 * Generates and propagates request_id, enforces rate limits, logs audit.
 *
 * @package MCP_No_Headless
 * @since 3.0.0
 */

namespace MCP_No_Headless\MCP\Core;

use MCP_No_Headless\MCP\Core\Tool_Response;
use MCP_No_Headless\MCP\Core\Tools\Ping;
use MCP_No_Headless\MCP\Core\Tools\Find;
use MCP_No_Headless\MCP\Core\Tools\Me;
use MCP_No_Headless\MCP\Core\Tools\Save;
use MCP_No_Headless\MCP\Core\Tools\Run;
use MCP_No_Headless\MCP\Core\Tools\Assist;
use MCP_No_Headless\MCP\Core\Services\Migration_Service;

class Router_V3 {

    const VERSION = '3.0.0';

    // Rate limiting defaults
    const RATE_LIMIT_REQUESTS = 60;    // Per minute
    const RATE_LIMIT_WINDOW = 60;      // Seconds

    /**
     * Core V3 tools mapping
     */
    private static array $core_tools = [
        'ml_ping' => Ping::class,
        'ml_find' => Find::class,
        'ml_me' => Me::class,
        'ml_save' => Save::class,
        'ml_run' => Run::class,
        'ml_image' => Image::class,
        'ml_assist' => Assist::class,
    ];

    /**
     * Registered pack tools
     */
    private static array $pack_tools = [];

    /**
     * Route a tool call
     *
     * @param string $tool_name Tool to call
     * @param array $params Tool parameters
     * @param int|null $user_id User making the call
     * @return array Standardized response
     */
    public static function route(string $tool_name, array $params = [], ?int $user_id = null): array {
        // Generate and set request ID at the start
        $request_id = Tool_Response::generate_request_id();
        Tool_Response::set_request_id($request_id);

        // Get user ID
        $user_id = $user_id ?? get_current_user_id();

        // Check authentication
        if (!$user_id && $tool_name !== 'ml_ping') {
            $response = Tool_Response::auth_error();
            Tool_Response::log_error($response);
            return $response;
        }

        // Check rate limit
        $rate_check = self::check_rate_limit($user_id);
        if ($rate_check !== true) {
            $response = Tool_Response::rate_limit($rate_check['retry_after'], $rate_check['limit']);
            Tool_Response::log_error($response, $user_id);
            return $response;
        }

        // Log the request start
        self::log_request_start($tool_name, $params, $user_id, $request_id);

        try {
            // Route to appropriate handler
            $response = self::dispatch($tool_name, $params, $user_id);
        } catch (\Throwable $e) {
            $response = Tool_Response::internal_error(
                "Erreur lors de l'exécution de {$tool_name}: " . $e->getMessage(),
                $e
            );
            Tool_Response::log_error($response, $user_id);
        }

        // Log the request end
        self::log_request_end($tool_name, $response, $user_id, $request_id);

        // Increment rate limit counter
        self::increment_rate_counter($user_id);

        return $response;
    }

    /**
     * Dispatch to the appropriate tool handler
     */
    private static function dispatch(string $tool_name, array $params, int $user_id): array {
        // 1. Try V3 Core tools first
        if (isset(self::$core_tools[$tool_name])) {
            $class = self::$core_tools[$tool_name];
            return $class::execute($params, $user_id);
        }

        // 2. Try registered pack tools
        if (isset(self::$pack_tools[$tool_name])) {
            $handler = self::$pack_tools[$tool_name];
            return self::execute_pack_tool($handler, $params, $user_id);
        }

        // 3. Try V2 translation (backward compatibility)
        if (class_exists(Migration_Service::class)) {
            $v2_result = Migration_Service::handle_v2_call($tool_name, $params, $user_id);
            if ($v2_result !== null) {
                return Tool_Response::wrap_legacy($v2_result);
            }
        }

        // 4. Unknown tool
        $available = array_merge(
            array_keys(self::$core_tools),
            array_keys(self::$pack_tools)
        );
        return Tool_Response::unknown_tool($tool_name, $available);
    }

    /**
     * Execute a pack tool
     */
    private static function execute_pack_tool(array $handler, array $params, int $user_id): array {
        // Check if pack is active
        if (!self::is_pack_active($handler['pack'])) {
            return Tool_Response::error(
                Tool_Response::ERROR_PERMISSION,
                "Pack '{$handler['pack']}' non activé.",
                ['suggestion' => 'Activez le pack dans les paramètres MaryLink.']
            );
        }

        // Check pack-specific permissions
        if (!self::check_pack_permission($handler['pack'], $user_id)) {
            return Tool_Response::permission_error('execute', $handler['pack']);
        }

        // Execute
        $class = $handler['class'];
        if (class_exists($class) && method_exists($class, 'execute')) {
            return $class::execute($params, $user_id);
        }

        return Tool_Response::internal_error("Handler introuvable pour pack tool.");
    }

    /**
     * Register a pack tool
     */
    public static function register_pack_tool(string $tool_name, string $pack_name, string $class): void {
        self::$pack_tools[$tool_name] = [
            'pack' => $pack_name,
            'class' => $class,
        ];
    }

    /**
     * Register multiple pack tools
     */
    public static function register_pack(string $pack_name, array $tools): void {
        foreach ($tools as $tool_name => $class) {
            self::register_pack_tool($tool_name, $pack_name, $class);
        }
    }

    /**
     * Check if a pack is active
     */
    private static function is_pack_active(string $pack_name): bool {
        $active_packs = get_option('mcp_active_packs', []);
        return in_array($pack_name, $active_packs, true);
    }

    /**
     * Check pack permission for user
     */
    private static function check_pack_permission(string $pack_name, int $user_id): bool {
        // Get pack configuration
        $pack_config = get_option("mcp_pack_{$pack_name}_config", []);

        // Check if restricted to certain roles
        if (!empty($pack_config['allowed_roles'])) {
            $user = get_userdata($user_id);
            if (!$user) {
                return false;
            }
            $has_role = array_intersect($user->roles, $pack_config['allowed_roles']);
            if (empty($has_role)) {
                return false;
            }
        }

        // Custom permission check via filter
        return apply_filters('mcp_pack_permission', true, $pack_name, $user_id);
    }

    /**
     * Check rate limit
     *
     * @param int $user_id User ID
     * @return bool|array True if OK, or array with retry_after and limit
     */
    private static function check_rate_limit(int $user_id) {
        if (!$user_id) {
            return true; // Anonymous gets one free call (ping)
        }

        $key = "mcp_rate_{$user_id}";
        $current = (int) get_transient($key);
        $limit = self::get_rate_limit($user_id);

        if ($current >= $limit) {
            $ttl = self::get_transient_ttl($key);
            return [
                'retry_after' => $ttl > 0 ? $ttl : self::RATE_LIMIT_WINDOW,
                'limit' => $limit,
            ];
        }

        return true;
    }

    /**
     * Increment rate limit counter
     */
    private static function increment_rate_counter(int $user_id): void {
        if (!$user_id) {
            return;
        }

        $key = "mcp_rate_{$user_id}";
        $current = (int) get_transient($key);

        if ($current === 0) {
            set_transient($key, 1, self::RATE_LIMIT_WINDOW);
        } else {
            // Increment without resetting TTL
            $ttl = self::get_transient_ttl($key);
            if ($ttl > 0) {
                set_transient($key, $current + 1, $ttl);
            }
        }
    }

    /**
     * Get remaining TTL for a transient
     */
    private static function get_transient_ttl(string $key): int {
        global $wpdb;

        $transient_timeout = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            "_transient_timeout_{$key}"
        ));

        if (!$transient_timeout) {
            return 0;
        }

        $remaining = (int) $transient_timeout - time();
        return max(0, $remaining);
    }

    /**
     * Get rate limit for user
     */
    private static function get_rate_limit(int $user_id): int {
        // Check user-specific limit
        $user_limit = get_user_meta($user_id, 'mcp_rate_limit', true);
        if ($user_limit) {
            return (int) $user_limit;
        }

        // Check role-based limit
        $user = get_userdata($user_id);
        if ($user) {
            if (in_array('administrator', $user->roles, true)) {
                return self::RATE_LIMIT_REQUESTS * 10; // 10x for admins
            }
            if (in_array('mcp_premium', $user->roles, true)) {
                return self::RATE_LIMIT_REQUESTS * 3; // 3x for premium
            }
        }

        return self::RATE_LIMIT_REQUESTS;
    }

    /**
     * Log request start
     */
    private static function log_request_start(string $tool_name, array $params, int $user_id, string $request_id): void {
        do_action('mcp_request_start', [
            'request_id' => $request_id,
            'tool' => $tool_name,
            'params' => self::sanitize_params_for_log($params),
            'user_id' => $user_id,
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
    }

    /**
     * Log request end
     */
    private static function log_request_end(string $tool_name, array $response, int $user_id, string $request_id): void {
        do_action('mcp_request_end', [
            'request_id' => $request_id,
            'tool' => $tool_name,
            'success' => $response['success'] ?? false,
            'user_id' => $user_id,
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'duration_ms' => null, // Could add timing if needed
        ]);
    }

    /**
     * Sanitize params for logging (remove sensitive data)
     */
    private static function sanitize_params_for_log(array $params): array {
        $sensitive_keys = ['password', 'token', 'api_key', 'secret', 'key'];
        $sanitized = [];

        foreach ($params as $key => $value) {
            if (in_array(strtolower($key), $sensitive_keys, true)) {
                $sanitized[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $sanitized[$key] = self::sanitize_params_for_log($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Get list of all available tools
     */
    public static function get_available_tools(): array {
        $tools = [];

        // Core tools (always available)
        foreach (array_keys(self::$core_tools) as $name) {
            $tools[] = [
                'name' => $name,
                'strate' => 'core',
                'available' => true,
            ];
        }

        // Pack tools
        foreach (self::$pack_tools as $name => $handler) {
            $tools[] = [
                'name' => $name,
                'strate' => 'pack',
                'pack' => $handler['pack'],
                'available' => self::is_pack_active($handler['pack']),
            ];
        }

        return $tools;
    }

    /**
     * Get packs status
     */
    public static function get_packs_status(): array {
        $all_packs = get_option('mcp_registered_packs', []);
        $active_packs = get_option('mcp_active_packs', []);

        return [
            'available' => $all_packs,
            'active' => $active_packs,
        ];
    }

    /**
     * Reset for testing
     */
    public static function reset(): void {
        self::$pack_tools = [];
        Tool_Response::reset();
    }
}
