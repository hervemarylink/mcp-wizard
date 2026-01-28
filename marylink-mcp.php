<?php
/**
 * Plugin Name: MaryLink MCP
 * Plugin URI: https://marylink.io
 * Description: MCP Server pour WordPress. Expose les outils MaryLink via REST API SSE pour Claude et autres clients MCP.
 * Version:           3.2.29
 * Author: MaryLink
 * Author URI: https://marylink.io
 * Text Domain: marylink-mcp
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 *
 * Previously known as: mcp-wizard, mcp-no-headless
 * Unified naming since v2.0.0 (2025-01-20)
 */

namespace MaryLink_MCP;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Load version from version.json
$version_file = __DIR__ . '/version.json';
$version_data = file_exists($version_file) ? json_decode(file_get_contents($version_file), true) : [];
$plugin_version = $version_data['version'] ?? '2.0.0';
$plugin_build = $version_data['build'] ?? 'dev';

// Plugin constants (new unified naming)
define('MLMCP_VERSION', $plugin_version);
define('MLMCP_BUILD', $plugin_build);
define('MLMCP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MLMCP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('MLMCP_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Legacy constants for backward compatibility
define('MCPNH_VERSION', MLMCP_VERSION);
define('MCPNH_PLUGIN_DIR', MLMCP_PLUGIN_DIR);
define('MCPNH_PLUGIN_URL', MLMCP_PLUGIN_URL);
define('MCPNH_PLUGIN_BASENAME', MLMCP_PLUGIN_BASENAME);

/**
 * Autoloader for plugin classes
 * Supports both new namespace (MaryLink_MCP) and legacy (MCP_No_Headless)
 */
spl_autoload_register(function ($class) {
    // New namespace
    $new_prefix = 'MaryLink_MCP\\';
    // Legacy namespace
    $legacy_prefix = 'MCP_No_Headless\\';

    $prefix = null;
    if (strpos($class, $new_prefix) === 0) {
        $prefix = $new_prefix;
    } elseif (strpos($class, $legacy_prefix) === 0) {
        $prefix = $legacy_prefix;
    }

    if ($prefix === null) {
        return;
    }

    $relative_class = substr($class, strlen($prefix));
    $path = MLMCP_PLUGIN_DIR . 'src/' . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($path)) {
        require_once $path;
    }
});

// Note: Classes remain in MCP_No_Headless namespace for backward compatibility
// Full migration to MaryLink_MCP namespace will be done in v3.0

// Load Packs bootstrap (v3.0.5+)
$packs_bootstrap = MLMCP_PLUGIN_DIR . 'src/MCP/Packs/bootstrap.php';
if (file_exists($packs_bootstrap)) {
    require_once $packs_bootstrap;
}

/**
 * Get plugin version info
 */
function mlmcp_get_version_info(): array {
    return [
        'version' => MLMCP_VERSION,
        'build' => MLMCP_BUILD,
        'name' => 'MaryLink MCP',
        'slug' => 'marylink-mcp',
    ];
}

/**
 * Check plugin dependencies (none required for standalone mode)
 */
function mlmcp_check_dependencies(): bool {
    return true;
}

// Legacy function alias
function mcpnh_check_dependencies(): bool {
    return mlmcp_check_dependencies();
}

/**
 * Initialize the plugin
 */
function mlmcp_init(): void {
    load_plugin_textdomain('marylink-mcp', false, dirname(MLMCP_PLUGIN_BASENAME) . '/languages');

    // Use the legacy namespace classes (they exist in src/)
    new \MCP_No_Headless\User\Token_Manager();
    new \MCP_No_Headless\User\Profile_Tab();
    new \MCP_No_Headless\MCP\Tools_Registry();

    // Register scoring cron
    \MCP_No_Headless\Services\Scoring_Service::register_cron();

    // Register audit log cron
    \MCP_No_Headless\Ops\Audit_Logger::register_cron();

    // Setup error handlers for MCP requests
    if (defined('DOING_MCP_REQUEST') && DOING_MCP_REQUEST) {
        \MCP_No_Headless\Ops\Error_Handler::setup_handlers();
    }

    // Initialize admin page
    if (is_admin()) {
        \MCP_No_Headless\Admin\Admin_Page::init();
    }

    if (class_exists('Meow_MWAI_Core')) {
        new \MCP_No_Headless\Integration\AI_Engine_Bridge();

    }

    // Force MCP user to Hervé (93) via rest_pre_dispatch
    // Note: mwai_allow_mcp filter doesn't work for noauth URL routes
    // rest_pre_dispatch fires after permission_callback, before route handler
    add_filter('rest_pre_dispatch', function($result, $server, $request) {
        $route = $request->get_route();
        if (strpos($route, '/mcp/') !== false) {
            $current = get_current_user_id();
            if ($current > 0 && $current !== 93) {
                $user = get_user_by('id', 93);
                if ($user) {
                    wp_set_current_user(93, $user->user_login);
                }
            }
        }
        return $result;
    }, 10, 3);

    // Register Picasso integration hook for Auto-Improve governance
    mlmcp_register_picasso_hooks();

    // Initialize Metrics Collector (v2.2.0+)
    if (class_exists(\MCP_No_Headless\Services\Metrics_Collector::class)) {
        \MCP_No_Headless\Services\Metrics_Collector::init();
    }
}
add_action('plugins_loaded', __NAMESPACE__ . '\mlmcp_init', 20);

// Legacy function alias
function mcpnh_init(): void {
    mlmcp_init();
}

/**
 * Register Picasso integration hooks for Auto-Improve
 */
function mlmcp_register_picasso_hooks(): void {
    add_filter('ml_auto_improve_is_approved_publication', function($is_approved, $publication_id, $user_id) {
        if (class_exists('Picasso_Backend\\Utils\\Publication')) {
            $step = get_post_meta($publication_id, '_picasso_workflow_step', true);

            $locked_steps = apply_filters('picasso_locked_workflow_steps', [
                'approved',
                'published',
                'locked',
                'archived',
                'validated',
            ]);

            if (in_array($step, $locked_steps, true)) {
                return true;
            }
        }

        return $is_approved;
    }, 10, 3);
}

// Legacy function alias
function mcpnh_register_picasso_hooks(): void {
    mlmcp_register_picasso_hooks();
}

/**
 * Register REST API routes
 */
function mlmcp_register_rest_routes(): void {
    \MCP_No_Headless\Ops\REST_Controller::register_routes();
    \MCP_No_Headless\MCP\Http\MCP_Controller::register_routes();
}
add_action('rest_api_init', __NAMESPACE__ . '\mlmcp_register_rest_routes');

// Legacy function alias
function mcpnh_register_rest_routes(): void {
    mlmcp_register_rest_routes();
}

/**
 * Activation hook
 */
function mlmcp_activate(): void {
    \MCP_No_Headless\Ops\Audit_Logger::create_table();
    \MCP_No_Headless\User\Mission_Token_Manager::create_table();
    \MCP_No_Headless\Services\Scoring_Service::register_cron();
    \MCP_No_Headless\Ops\Audit_Logger::register_cron();

    // Create Feedback table (v2.2.0+)
    if (class_exists(\MCP_No_Headless\Services\Feedback_Service::class)
        && method_exists(\MCP_No_Headless\Services\Feedback_Service::class, 'create_table')) {
        \MCP_No_Headless\Services\Feedback_Service::create_table();
    }

    // Create Embeddings table (v2.2.0+)
    if (class_exists(\MCP_No_Headless\Embeddings\Embedding_Service::class)
        && method_exists(\MCP_No_Headless\Embeddings\Embedding_Service::class, 'create_table')) {
        \MCP_No_Headless\Embeddings\Embedding_Service::create_table();
    }

    flush_rewrite_rules();
}
register_activation_hook(__FILE__, __NAMESPACE__ . '\mlmcp_activate');

// Legacy function alias
function mcpnh_activate(): void {
    mlmcp_activate();
}

/**
 * Deactivation hook
 */
function mlmcp_deactivate(): void {
    \MCP_No_Headless\Services\Scoring_Service::unregister_cron();
    \MCP_No_Headless\Ops\Audit_Logger::unregister_cron();

    flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, __NAMESPACE__ . '\mlmcp_deactivate');

// Legacy function alias
function mcpnh_deactivate(): void {
    mlmcp_deactivate();
}

// BuddyBoss endpoint exclusion
add_filter('bb_exclude_endpoints_from_restriction', function($endpoints, $current) {
    $endpoints[] = '/mcp/v1/mcp';
    $endpoints[] = '/mcp/v1/sse';
    $endpoints[] = '/mcp/v1/discover';
    $endpoints[] = '/mcp/v1/messages';
    $endpoints[] = '/mcp/v1/tools';
    return $endpoints;
}, 10, 2);
