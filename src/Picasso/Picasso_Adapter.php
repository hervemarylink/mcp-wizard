<?php
/**
 * Picasso Adapter - Wrapper for Picasso backend calls
 *
 * Centralizes all Picasso-specific function calls so that:
 * - Tools remain stable even if Picasso changes
 * - Fallback to WP/meta when Picasso not available
 * - Single point of integration
 *
 * @package MCP_No_Headless
 */

namespace MCP_No_Headless\Picasso;

use MCP_No_Headless\Schema\Publication_Schema;

class Picasso_Adapter {

    /**
     * Check if Picasso is available
     */
    public static function is_available(): bool {
        return class_exists('Picasso_Backend\\Permission\\User');
    }

    /**
     * Check if publication post type exists
     */
    public static function has_publications(): bool {
        return post_type_exists('publication');
    }

    /**
     * Check if space post type exists
     */
    public static function has_spaces(): bool {
        return post_type_exists('space');
    }

    // ==========================================
    // PERMISSIONS
    // ==========================================

    /**
     * Check if user can access a space
     *
     * @param int $user_id User ID
     * @param int $space_id Space ID
     * @return bool
     */
    public static function can_access_space(int $user_id, int $space_id): bool {
        if (!self::is_available()) {
            // Fallback: check if space exists and is published
            $space = get_post($space_id);
            return $space && $space->post_type === 'space' && $space->post_status === 'publish';
        }

        // Use Picasso permission system
        if (class_exists('Picasso_Backend\\Permission\\User')) {
            $permission = new \Picasso_Backend\Permission\User($user_id);
            return $permission->can_access_space($space_id);
        }

        return false;
    }

    /**
     * Check if user can access a publication
     *
     * @param int $user_id User ID
     * @param int $publication_id Publication ID
     * @return bool
     */
    public static function can_access_publication(int $user_id, int $publication_id): bool {
        if (!self::is_available()) {
            // Fallback: check if publication exists and is published
            $pub = get_post($publication_id);
            return $pub && $pub->post_type === 'publication' && $pub->post_status === 'publish';
        }

        if (class_exists('Picasso_Backend\\Permission\\User')) {
            $permission = new \Picasso_Backend\Permission\User($user_id);
            return $permission->can_view($publication_id);
        }

        return false;
    }

    /**
     * Check if user can edit a publication
     *
     * @param int $user_id User ID
     * @param int $publication_id Publication ID
     * @return bool
     */
    public static function can_edit_publication(int $user_id, int $publication_id): bool {
        if (!self::is_available()) {
            // Fallback: only author can edit
            $pub = get_post($publication_id);
            return $pub && (int) $pub->post_author === $user_id;
        }

        if (class_exists('Picasso_Backend\\Permission\\User')) {
            $permission = new \Picasso_Backend\Permission\User($user_id);
            return $permission->can_edit($publication_id);
        }

        return false;
    }

    /**
     * Check if user can create in a space
     *
     * @param int $user_id User ID
     * @param int $space_id Space ID
     * @return bool
     */
    public static function can_create_in_space(int $user_id, int $space_id): bool {
        if (!self::is_available()) {
            return self::can_access_space($user_id, $space_id);
        }

        if (class_exists('Picasso_Backend\\Permission\\User')) {
            $permission = new \Picasso_Backend\Permission\User($user_id);
            return $permission->can_create_in_space($space_id);
        }

        return false;
    }

    /**
     * Check if user can comment on publication
     *
     * @param int $user_id User ID
     * @param int $publication_id Publication ID
     * @param string $visibility public|private
     * @return bool
     */
    public static function can_comment(int $user_id, int $publication_id, string $visibility = 'public'): bool {
        // Must be able to access publication first
        if (!self::can_access_publication($user_id, $publication_id)) {
            return false;
        }

        if (!self::is_available()) {
            return $visibility === 'public'; // Fallback: only public comments
        }

        if (class_exists('Picasso_Backend\\Permission\\User')) {
            $permission = new \Picasso_Backend\Permission\User($user_id);
            if ($visibility === 'private') {
                return $permission->can_comment_private($publication_id);
            }
            return $permission->can_comment($publication_id);
        }

        return false;
    }

    /**
     * Check if user can move publication to step
     *
     * @param int $user_id User ID
     * @param int $publication_id Publication ID
     * @param string $step_name Target step name
     * @return bool
     */
    public static function can_move_to_step(int $user_id, int $publication_id, string $step_name): bool {
        if (!self::is_available()) {
            return self::can_edit_publication($user_id, $publication_id);
        }

        if (class_exists('Picasso_Backend\\Permission\\User')) {
            $permission = new \Picasso_Backend\Permission\User($user_id);
            return $permission->can_move_to_step($publication_id, $step_name);
        }

        return false;
    }

    /**
     * Check if user can use a tool
     *
     * @param int $user_id User ID
     * @param int $tool_id Tool (publication) ID
     * @return bool
     */
    public static function can_use_tool(int $user_id, int $tool_id): bool {
        // Tools are publications, check access
        return self::can_access_publication($user_id, $tool_id);
    }

    /**
     * Check if user can view ratings on publication
     *
     * @param int $user_id User ID
     * @param int $publication_id Publication ID
     * @return bool
     */
    public static function can_view_ratings(int $user_id, int $publication_id): bool {
        // Must access publication first
        if (!self::can_access_publication($user_id, $publication_id)) {
            return false;
        }

        // Check space settings for ratings visibility
        $space_id = self::get_publication_space($publication_id);
        if (!$space_id) {
            return true; // No space, ratings visible
        }

        $hide_ratings = get_post_meta($space_id, '_ml_hide_ratings', true);
        return empty($hide_ratings);
    }

    /**
     * Get summarized permissions for user on space/step
     *
     * @param int $user_id User ID
     * @param int $space_id Space ID
     * @param string|null $step_name Optional step name
     * @return array Permissions array
     */
    public static function get_permissions_summary(int $user_id, int $space_id, ?string $step_name = null): array {
        $permissions = [
            'can_access' => self::can_access_space($user_id, $space_id),
            'can_create' => self::can_create_in_space($user_id, $space_id),
            'can_comment_public' => false,
            'can_comment_private' => false,
        ];

        if (!$permissions['can_access']) {
            return $permissions;
        }

        // Check comment permissions (would need a publication in that space)
        // For now, base on space access
        $permissions['can_comment_public'] = $permissions['can_access'];

        if (self::is_available() && class_exists('Picasso_Backend\\Permission\\User')) {
            $permission = new \Picasso_Backend\Permission\User($user_id);
            // Get step-specific permissions if Picasso supports it
            if (method_exists($permission, 'get_step_permissions') && $step_name) {
                $step_perms = $permission->get_step_permissions($space_id, $step_name);
                if (is_array($step_perms)) {
                    $permissions = array_merge($permissions, $step_perms);
                }
            }
        }

        return $permissions;
    }

    // ==========================================
    // QUERIES
    // ==========================================

    /**
     * Get spaces accessible by user
     *
     * @param int $user_id User ID
     * @param array $filters Filters (search, etc.)
     * @return array Space IDs
     */
    public static function get_accessible_space_ids(int $user_id, array $filters = []): array {
        if (!self::has_spaces()) {
            return [];
        }

        if (self::is_available() && class_exists('Picasso_Backend\\Permission\\User')) {
            $permission = new \Picasso_Backend\Permission\User($user_id);
            if (method_exists($permission, 'get_accessible_spaces')) {
                return $permission->get_accessible_spaces($filters);
            }
        }

        // Fallback: all published spaces
        $args = [
            'post_type' => 'space',
            'post_status' => 'publish',
            'fields' => 'ids',
            'posts_per_page' => -1,
        ];

        if (!empty($filters['search'])) {
            $args['s'] = $filters['search'];
        }

        $query = new \WP_Query($args);
        return $query->posts;
    }

    /**
     * Get space for a publication
     *
     * @param int $publication_id Publication ID
     * @return int|null Space ID or null
     */
    public static function get_publication_space(int $publication_id): ?int {
        // Delegate to Publication_Schema for unified lookup
        return Publication_Schema::get_space_id($publication_id);
    }

    /**
     * Get step for a publication
     *
     * @param int $publication_id Publication ID
     * @return string|null Step name or null
     */
    public static function get_publication_step(int $publication_id): ?string {
        // Delegate to Publication_Schema for unified lookup
        return Publication_Schema::get_step($publication_id);
    }

    /**
     * Get steps for a space
     *
     * @param int $space_id Space ID
     * @return array Steps with name, label, order
     */
    public static function get_space_steps(int $space_id): array {
        // Picasso canonical: _space_steps (from picasso-backend-dev Helper\Space)
        $steps = get_post_meta($space_id, '_space_steps', true);

        // Legacy fallback: _ml_workflow_steps
        if (!is_array($steps) || empty($steps)) {
            $steps = get_post_meta($space_id, '_ml_workflow_steps', true);
        }

        if (is_array($steps) && !empty($steps)) {
            $order = 0;
            return array_map(function ($step) use (&$order) {
                if (is_string($step)) {
                    return [
                        'slug' => $step,
                        'name' => $step,
                        'label' => ucfirst($step),
                        'order' => $order++,
                    ];
                }
                return [
                    'slug' => $step['slug'] ?? $step['name'] ?? "step_{$order}",
                    'name' => $step['slug'] ?? $step['name'] ?? "step_{$order}",
                    'label' => $step['name'] ?? $step['label'] ?? "Step {$order}",
                    'order' => $order++,
                ];
            }, $steps);
        }

        // No steps defined = no step-driven workflow
        return [];
    }

    /**
     * Get first step slug for a space (for default assignment)
     *
     * @param int $space_id Space ID
     * @return string|null First step slug or null
     */
    public static function get_first_step_slug(int $space_id): ?string {
        $steps = self::get_space_steps($space_id);
        if (empty($steps)) {
            return null;
        }
        // Sort by order and return first
        usort($steps, fn($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));
        return $steps[0]['slug'] ?? $steps[0]['name'] ?? null;
    }

    /**
     * Get authors for a publication
     *
     * @param int $publication_id Publication ID
     * @return array Authors with id, name, avatar
     */
    public static function get_publication_authors(int $publication_id): array {
        $authors = [];

        // Primary author
        $post = get_post($publication_id);
        if ($post) {
            $primary = get_userdata($post->post_author);
            if ($primary) {
                $authors[] = [
                    'id' => $primary->ID,
                    'name' => $primary->display_name,
                    'avatar' => get_avatar_url($primary->ID, ['size' => 48]),
                    'primary' => true,
                ];
            }
        }

        // Co-authors (if Picasso supports it)
        $co_authors = get_post_meta($publication_id, '_ml_co_authors', true);
        if (is_array($co_authors)) {
            foreach ($co_authors as $co_author_id) {
                $user = get_userdata($co_author_id);
                if ($user) {
                    $authors[] = [
                        'id' => $user->ID,
                        'name' => $user->display_name,
                        'avatar' => get_avatar_url($user->ID, ['size' => 48]),
                        'primary' => false,
                    ];
                }
            }
        }

        return $authors;
    }

    /**
     * Get dependencies for a publication
     *
     * @param int $publication_id Publication ID
     * @return array [dependencies, dependents]
     */
    public static function get_publication_dependencies(int $publication_id): array {
        $dependencies = [];
        $dependents = [];

        // Get linked publications (this publication uses)
        $linked = get_post_meta($publication_id, '_ml_linked_publications', true);
        if (is_array($linked)) {
            $dependencies = array_map('intval', $linked);
        }

        // Tool contents
        $tool_contents = get_post_meta($publication_id, '_ml_tool_contents', true);
        if (is_array($tool_contents)) {
            $dependencies = array_merge($dependencies, array_map('intval', $tool_contents));
        }

        // Find publications that link to this one (dependents)
        global $wpdb;
        $dependents_query = $wpdb->get_col($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
             WHERE (meta_key = '_ml_linked_publications' OR meta_key = '_ml_tool_contents')
             AND meta_value LIKE %s",
            '%"' . $publication_id . '"%'
        ));

        if (!empty($dependents_query)) {
            $dependents = array_map('intval', $dependents_query);
        }

        return [
            'dependencies' => array_unique($dependencies),
            'dependents' => array_unique($dependents),
        ];
    }

    /**
     * Get tool instruction template
     *
     * @param int $tool_id Tool (publication) ID
     * @return string|null Instruction template
     */
    public static function get_tool_instruction(int $tool_id): ?string {
        // Try meta
        $instruction = get_post_meta($tool_id, '_ml_instruction', true);
        if ($instruction) {
            return $instruction;
        }

        // Try content with marker
        $post = get_post($tool_id);
        if ($post) {
            // Extract instruction block if present
            if (preg_match('/<!-- instruction -->(.*?)<!-- \/instruction -->/s', $post->post_content, $matches)) {
                return trim($matches[1]);
            }
            // Use full content as instruction
            return $post->post_content;
        }

        return null;
    }

    /**
     * Get linked styles for a tool
     *
     * @param int $tool_id Tool ID
     * @return array Linked style IDs
     */
    public static function get_tool_linked_styles(int $tool_id): array {
        $styles = get_post_meta($tool_id, '_ml_linked_styles', true);
        return is_array($styles) ? array_map('intval', $styles) : [];
    }

    /**
     * Get linked contents for a tool
     *
     * @param int $tool_id Tool ID
     * @return array Linked content IDs
     */
    public static function get_tool_linked_contents(int $tool_id): array {
        $contents = get_post_meta($tool_id, '_ml_tool_contents', true);
        return is_array($contents) ? array_map('intval', $contents) : [];
    }
}
