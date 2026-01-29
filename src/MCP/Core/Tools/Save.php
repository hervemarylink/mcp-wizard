<?php
/**
 * ml_save - Publication persistence tool
 *
 * Creates and updates publications with full metadata support.
 * Handles visibility, space assignment, and activity feed integration.
 *
 * @package MCP_No_Headless
 * @since 3.0.0
 */

namespace MCP_No_Headless\MCP\Core\Tools;

use MCP_No_Headless\Services\LLM_Runtime;

use MCP_No_Headless\MCP\Core\Tool_Response;
use MCP_No_Headless\MCP\Core\Services\Permission_Service;
use MCP_No_Headless\Schema\Publication_Schema;

class Save {

    const TOOL_NAME = 'ml_save';
    const VERSION = '3.2.32';

    const MODE_CREATE = 'create';
    const MODE_UPDATE = 'update';
    const MODE_AUTO = 'auto'; // Create if no ID, update if ID provided

    const STATUS_PUBLISH = 'publish';
    const STATUS_DRAFT = 'draft';
    const STATUS_PENDING = 'pending';
    const STATUS_TRASH = 'trash'; // Soft-delete (WordPress trash)

    const VALID_STATUSES = [
        self::STATUS_PUBLISH,
        self::STATUS_DRAFT,
        self::STATUS_PENDING,
        self::STATUS_TRASH,
    ];

    const VISIBILITY_PUBLIC = 'public';
    const VISIBILITY_SPACE = 'space';
    const VISIBILITY_PRIVATE = 'private';

    const VALID_VISIBILITIES = [
        self::VISIBILITY_PUBLIC,
        self::VISIBILITY_SPACE,
        self::VISIBILITY_PRIVATE,
    ];

    // Allowed publication labels (publication_label taxonomy) — whitelist to prevent term pollution
    private static function get_core_labels(): array {
        return ['prompt', 'tool', 'contenu', 'style', 'client', 'projet'];
    }

    // Labels that MUST NOT be used even if they exist (legacy pollution)
    private static function get_blocked_labels(): array {
        return ['data', 'doc'];
    }

    /**
     * Parse allowed labels for a BuddyPress space (group).
     * Stored in groupmeta key: ml_allowed_labels (preferred) or ml_space_allowed_labels (legacy).
     * Value can be array, JSON array string, or comma-separated string.
     * Returns null when policy is "open" (no restriction).
     */
    private static function get_space_allowed_labels(?int $space_id): ?array {
        if (!$space_id) { return null; }

        $raw = null;

        if (function_exists('groups_get_groupmeta')) {
            $raw = groups_get_groupmeta($space_id, 'ml_allowed_labels', true);
            if (empty($raw)) {
                $raw = groups_get_groupmeta($space_id, 'ml_space_allowed_labels', true);
            }
        } else {
            // Fallback for installs without BuddyPress groupmeta
            $raw = get_option('ml_space_allowed_labels_' . (int) $space_id);
        }

        $vals = [];
        if (is_array($raw)) {
            $vals = $raw;
        } elseif (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $vals = $decoded;
            } else {
                $vals = preg_split('/\s*,\s*/', $raw);
            }
        }

        $out = [];
        foreach (($vals ?: []) as $v) {
            $slug = sanitize_text_field((string) $v);
            $slug = strtolower(trim($slug));
            if ($slug === '') { continue; }
            $out[] = $slug;
        }

        $out = array_values(array_unique($out));
        if (empty($out)) { return null; } // open policy
        return $out;
    }

    /**
     * Filter labels to enforce blacklist + (optional) per-space allowlist.
     */
    private static function filter_labels_for_space(array $slugs, ?int $space_id): array {
        $blocked = self::get_blocked_labels();

        $slugs = array_values(array_filter($slugs, function($s) use ($blocked) {
            $s = strtolower(trim((string) $s));
            return $s !== '' && !in_array($s, $blocked, true);
        }));

        $allowed = self::get_space_allowed_labels($space_id);
        if ($allowed === null) {
            return $slugs;
        }

        $core = self::get_core_labels();
        $allow = array_values(array_unique(array_merge($core, $allowed)));

        return array_values(array_filter($slugs, function($s) use ($allow) {
            $s = strtolower(trim((string) $s));
            return in_array($s, $allow, true);
        }));
    }


    // Valid publication types (maps to publication_label taxonomy)
    // NOTE: aliases are normalized via TYPE_ALIASES before validation.
    const VALID_TYPES = [
        'tool',
        'prompt',
        'style',
        'contenu',
        'client',
        'projet',
    ];

    // Type normalization (aliases)
    const TYPE_ALIASES = [
        // canonicalize synonyms to 'contenu'
        'publication' => 'contenu',
        'data' => 'contenu',
        'content' => 'contenu',
        'doc' => 'contenu',
    ];

    /**
     * Execute save operation
     *
     * @param array $args Tool arguments
     * @param int $user_id Current user ID
     * @return array Save result
     */
    public static function execute(array $args, int $user_id): array {
        $start_time = microtime(true);

        if ($user_id <= 0) {
            return Tool_Response::auth_error('Authentification requise pour sauvegarder');
        }

        // Parse arguments
        $mode = $args['mode'] ?? self::MODE_AUTO;
        $publication_id = isset($args['publication_id']) ? (int) $args['publication_id'] : null;
        if ($publication_id === null && isset($args['id'])) {
            $publication_id = (int) $args['id'];
        }
        $title = trim($args['title'] ?? '');
        $content = $args['content'] ?? '';
        $space_id = isset($args['space_id']) ? (int) $args['space_id'] : null;

        // Default to user's personal space if not specified
        if ($space_id === null || $space_id === 0) {
            $space_id = (int) get_user_meta($user_id, 'ml_default_space', true) ?: null;
        }
        $visibility = $args['visibility'] ?? self::VISIBILITY_PUBLIC;
        $tool_id = isset($args['tool_id']) ? (int) $args['tool_id'] : null;
        $tags = array_key_exists('tags', $args) ? ($args['tags'] ?? []) : null;
        $metadata = $args['metadata'] ?? [];
        $type = isset($args['type']) ? self::normalize_type($args['type']) : null;
        $status = $args['status'] ?? self::STATUS_PENDING;

        // LLM / backward-safe: if someone sends type='draft', interpret it as status=draft (and default type=data)
        if (is_string($args['type'] ?? null) && strtolower((string) $args['type']) === self::STATUS_DRAFT) {
            if (!isset($args['status'])) {
                $status = self::STATUS_DRAFT;
            }
            $type = 'contenu';
        }

        $labels = array_key_exists('labels', $args) ? ($args['labels'] ?? []) : null;

        // Determine actual mode
        if ($mode === self::MODE_AUTO) {
            $mode = $publication_id ? self::MODE_UPDATE : self::MODE_CREATE;
        }

        // Validate status
        if (!in_array($status, self::VALID_STATUSES)) {
            return Tool_Response::validation_error(
                "Statut invalide: $status",
                ['status' => "Valeurs valides: " . implode(', ', self::VALID_STATUSES)]
            );
        }

        // Validate visibility
        if (!in_array($visibility, self::VALID_VISIBILITIES)) {
            return Tool_Response::validation_error(
                "Visibilité invalide: $visibility",
                ['visibility' => "Valeurs valides: " . implode(', ', self::VALID_VISIBILITIES)]
            );
        }

        // Validate type (whitelist). IMPORTANT: prevents WP from auto-creating unknown taxonomy terms.
        if ($type !== null) {
            $validated_type = self::validate_type($type);
            if ($validated_type === null) {
                return Tool_Response::validation_error(
                    "Type invalide: $type",
                    ["type" => "Valeurs autorisées: " . implode(", ", self::get_core_labels())]
                );
            }
            $type = $validated_type;
        }

        // Validate labels if explicitly provided (whitelist)
        if ($labels !== null) {
            try {
                $labels = self::validate_labels($labels, $effective_space_id);

        // Require at least one label for CREATE mode - use space default if not provided
        if ($mode === self::MODE_CREATE) {
            $has_label = !empty($type) || !empty($labels);
            if (!$has_label && $space_id) {
                // Try to use space default label
                $default_label = self::get_space_default_label($space_id);
                if ($default_label) {
                    $type = $default_label;
                    $has_label = true;
                }
            }
            if (!$has_label) {
                return Tool_Response::validation_error(
                    'Label obligatoire',
                    [
                        'type' => "Spécifiez un type: " . implode(', ', self::get_core_labels()),
                        'labels' => "Ou spécifiez labels[]: prompt, tool, contenu, style, client, projet"
                    ]
                );
            }
        }
            } catch (\InvalidArgumentException $e) {
                return Tool_Response::validation_error(
                    $e->getMessage(),
                    ["labels" => "Valeurs autorisées: " . implode(", ", self::get_core_labels())]
                );
            }
        }
// Validate content (only for CREATE - UPDATE can modify just status/metadata)
        if ($mode === self::MODE_CREATE && empty($content) && empty($title)) {
            return Tool_Response::validation_error(
                'Titre ou contenu requis',
                ['title' => 'Au moins un des deux est obligatoire', 'content' => 'Au moins un des deux est obligatoire']
            );
        }

        // Validate space access if space_id provided
        if ($space_id && !self::can_post_to_space($user_id, $space_id)) {
            return Tool_Response::permission_error('publier', "espace #$space_id");
        }


        // Effective space id for label governance (for updates when space_id not provided)
        $effective_space_id = $space_id;
        if (!$effective_space_id && $publication_id) {
            if (class_exists(\MCP_No_Headless\Schema\Publication_Schema::class)) {
                $effective_space_id = \MCP_No_Headless\Schema\Publication_Schema::get_space_id($publication_id);
            }
            if (!$effective_space_id) {
                $effective_space_id = (int) get_post_meta($publication_id, '_ml_space_id', true);
            }
        }


        // Deletion (soft): if updating with status=trash, move to WordPress trash and stop here.
        if ($mode === self::MODE_UPDATE && $status === self::STATUS_TRASH) {
            if (!$publication_id) {
                return Tool_Response::validation_error('ID requis pour supprimer', ['id' => 'Fournir id ou publication_id']);
            }
            return self::trash_publication($user_id, $publication_id);
        }

        // Execute save
        $result = match ($mode) {
            self::MODE_CREATE => self::create_publication($user_id, $title, $content, $space_id, $visibility, $tool_id, $tags, $metadata, $type, $status, $labels),
            self::MODE_UPDATE => self::update_publication($user_id, $publication_id, $title, $content, $space_id, $visibility, $tool_id, $tags, $metadata, $type, $status, $labels),
            default => self::error('invalid_mode', "Invalid mode: $mode"),
        };

        if (!$result['success']) {
            return $result;
        }

        $latency_ms = round((microtime(true) - $start_time) * 1000);
        $result['latency_ms'] = $latency_ms;

        return $result;
    }

    /**
     * Create new publication
     */
    private static function create_publication(
        int $user_id,
        string $title,
        string $content,
        ?int $space_id,
        string $visibility,
        ?int $tool_id,
        ?array $tags,
        array $metadata,
        ?string $type,
        string $status,
        ?array $labels
    ): array {
        // Generate title if not provided
        if (empty($title)) {
            $title = self::generate_title($content);
        }

        // Create post
        $post_data = [
            'post_title' => sanitize_text_field($title),
            'post_content' => wp_kses_post($content),
            'post_status' => $status,
            'post_type' => 'publication',
            'post_author' => $user_id,
        ];

        $post_id = wp_insert_post($post_data, true);

        if (is_wp_error($post_id)) {
            return self::error('create_failed', $post_id->get_error_message());
        }

        // Save metadata
        self::save_publication_meta($post_id, $space_id, $visibility, $tool_id, $tags, $metadata, $type, $labels);

        // Create BuddyPress activity
        $activity_id = self::create_activity($user_id, $post_id, $title, $content, $space_id);

        // Track tool usage
        if ($tool_id) {
            self::track_tool_usage($tool_id, $user_id);
        }

        return [
            'success' => true,
            'mode' => 'created',
            'publication' => [
                'id' => $post_id,
                'title' => $title,
                'type' => \MCP_No_Headless\Schema\Publication_Schema::get_type($post_id) ?: ($type ?: 'contenu'),
                'types' => \MCP_No_Headless\Schema\Publication_Schema::get_types($post_id),
                'status' => get_post_status($post_id),
                'space_id' => \MCP_No_Headless\Schema\Publication_Schema::get_space_id($post_id),
                'visibility' => get_post_meta($post_id, '_ml_visibility', true) ?: $visibility,
                'labels' => \MCP_No_Headless\Schema\Publication_Schema::get_types($post_id),
                'tags' => wp_get_post_terms($post_id, \MCP_No_Headless\Schema\Publication_Schema::TAX_TAG, ['fields' => 'names']),
                'activity_id' => $activity_id,
                'url' => get_permalink($post_id),
                'step' => Publication_Schema::get_step($post_id),
                'created_at' => current_time('c'),
            ],
        ];
    }

    /**
     * Update existing publication
     */
    private static function update_publication(
        int $user_id,
        ?int $publication_id,
        string $title,
        string $content,
        ?int $space_id,
        string $visibility,
        ?int $tool_id,
        ?array $tags,
        array $metadata,
        ?string $type,
        string $status,
        ?array $labels
    ): array {
        if (!$publication_id) {
            return self::error('missing_id', 'publication_id required for update mode');
        }

        // Check post exists and user can edit
        $post = get_post($publication_id);
        if (!$post || $post->post_type !== 'publication') {
            return self::error('not_found', "Publication #$publication_id not found");
        }

        if (!self::can_edit_publication($user_id, $post)) {
            return self::error('access_denied', 'You cannot edit this publication');
        }

        // Build update data
        $post_data = [
            'ID' => $publication_id,
        ];

        if (!empty($title)) {
            $post_data['post_title'] = sanitize_text_field($title);
        }

        if (!empty($content)) {
            $post_data['post_content'] = wp_kses_post($content);
        }

        // Always update status when provided
        $post_data['post_status'] = $status;

        $result = wp_update_post($post_data, true);

        if (is_wp_error($result)) {
            return self::error('update_failed', $result->get_error_message());
        }

        // Update metadata
        self::save_publication_meta($publication_id, $space_id, $visibility, $tool_id, $tags, $metadata, $type, $labels);

        // Update activity if exists
        $activity_id = get_post_meta($publication_id, '_ml_activity_id', true);
        if ($activity_id && function_exists('bp_activity_update_meta')) {
            bp_activity_update_meta($activity_id, 'ml_updated', current_time('mysql'));
        }

        return [
            'success' => true,
            'mode' => 'updated',
            'publication' => [
                'id' => $publication_id,
                'title' => get_post_field('post_title', $publication_id, 'raw'),
                'type' => \MCP_No_Headless\Schema\Publication_Schema::get_type($publication_id) ?: ($type ?: 'contenu'),
                'types' => \MCP_No_Headless\Schema\Publication_Schema::get_types($publication_id),
                'status' => get_post_status($publication_id),
                'space_id' => \MCP_No_Headless\Schema\Publication_Schema::get_space_id($publication_id),
                'visibility' => get_post_meta($publication_id, '_ml_visibility', true) ?: $visibility,
                'labels' => \MCP_No_Headless\Schema\Publication_Schema::get_types($publication_id),
                'tags' => wp_get_post_terms($publication_id, \MCP_No_Headless\Schema\Publication_Schema::TAX_TAG, ['fields' => 'names']),
                'url' => get_permalink($publication_id),
                'updated_at' => current_time('c'),
            ],
        ];
    }

    /**
     * Save publication metadata
     */
    private static function save_publication_meta(
        int $post_id,
        ?int $space_id,
        string $visibility,
        ?int $tool_id,
        ?array $tags,
        array $metadata,
        ?string $type,
        ?array $labels = null
    ): void {
        if ($space_id !== null) {
            if (class_exists(Publication_Schema::class)) {
                Publication_Schema::set_space_id($post_id, $space_id);
            } else {
                update_post_meta($post_id, '_ml_space_id', $space_id);
            }
        }

        update_post_meta($post_id, '_ml_visibility', $visibility);

        if ($tool_id) {
            update_post_meta($post_id, '_ml_tool_id', $tool_id);
        }

        // Type - set publication_label taxonomy
        if ($type !== null) {
            // Ensure term exists (create if not)
            self::ensure_core_label_term($type);

            // Preserve existing labels unless caller explicitly provided labels
            if ($labels === null) {
                $existing = wp_get_post_terms($post_id, 'publication_label', ['fields' => 'slugs']);
                if (is_wp_error($existing)) { $existing = []; }
                $existing = self::filter_labels_for_space($existing ?: [], $space_id);
                $merged = array_values(array_unique(array_merge($existing ?: [], [$type])));
                $merged = self::filter_labels_for_space($merged, $space_id);
                wp_set_post_terms($post_id, $merged, 'publication_label');
            } else {
                // labels block will merge type + labels and set taxonomy
                // (no-op here)
            }

            // Also store as meta for quick access
            update_post_meta($post_id, '_ml_publication_type', $type);
        }

// Labels - additional labels via taxonomy (whitelist)
        if ($labels !== null) {
            // At this point $labels is already validated/normalized.
            $all_terms = $type ? array_merge([$type], $labels) : $labels;
            $all_terms = array_values(array_unique($all_terms));

            // Enforce blacklist + per-space allowlist
            $all_terms = self::filter_labels_for_space($all_terms, $space_id);

            // Ensure only allowed terms exist (create only if term is allowed but missing)
            $term_ids = [];
            foreach ($all_terms as $slug) {
                self::ensure_core_label_term($slug);
                $exists = term_exists($slug, 'publication_label');
                if (is_array($exists) && !empty($exists['term_id'])) {
                    $term_ids[] = (int) $exists['term_id'];
                }
            }

            // Set using term IDs to avoid accidental auto-creation
            if (!empty($term_ids)) {
                wp_set_post_terms($post_id, $term_ids, 'publication_label');
            }
        }

        // Tags - only allow existing terms (prevent tag pollution)
        if ($tags !== null) {
            $sanitized_tags = array_map('sanitize_text_field', $tags);
            $valid_tags = [];
            foreach ($sanitized_tags as $tag) {
                if (term_exists($tag, \MCP_No_Headless\Schema\Publication_Schema::TAX_TAG)) {
                    $valid_tags[] = $tag;
                }
            }
            if (!empty($valid_tags)) {
                wp_set_post_terms($post_id, $valid_tags, \MCP_No_Headless\Schema\Publication_Schema::TAX_TAG);
            }
        }

        // Picasso default fields (match picasso-backend-dev form submission defaults)
        // Only initialize on new publications (when meta does not exist yet)
        $picasso_defaults = [
            '_publication_co_authors'    => [],      // empty array
            '_banner_image'              => 0,       // no banner
            '_publication_images'        => [],      // empty array
            '_publication_videos'        => [],      // empty array
            '_publication_docs'          => [],      // empty array
            '_publication_youtube_video' => '',      // empty string
        ];
        foreach ($picasso_defaults as $meta_key => $default_value) {
            if (metadata_exists('post', $post_id, $meta_key) === false) {
                update_post_meta($post_id, $meta_key, $default_value);
            }
        }

        // _publication_label: store term_id in meta (Picasso quick-access pattern)
        $label_terms = wp_get_post_terms($post_id, 'publication_label', ['fields' => 'ids']);
        if (!is_wp_error($label_terms) && !empty($label_terms)) {
            update_post_meta($post_id, '_publication_label', (int) $label_terms[0]);
        }

        // Default workflow step: first step from space, fallback to 'submit'
        if (class_exists(Publication_Schema::class)) {
            $current_step = Publication_Schema::get_step($post_id);
            if (empty($current_step)) {
                $effective_space = $space_id ?: Publication_Schema::get_space_id($post_id);
                $first_step = null;
                if ($effective_space && class_exists(\MCP_No_Headless\Picasso\Picasso_Adapter::class)) {
                    $first_step = \MCP_No_Headless\Picasso\Picasso_Adapter::get_first_step_slug((int) $effective_space);
                }
                Publication_Schema::set_step($post_id, $first_step ?: 'submit');
            }
        }

        // Additional metadata
        if (!empty($metadata)) {
            $allowed_meta = ['source_url', 'language', 'word_count', 'ai_model', 'generation_params'];
            foreach ($metadata as $key => $value) {
                if (in_array($key, $allowed_meta)) {
                    update_post_meta($post_id, '_ml_' . $key, sanitize_text_field($value));
                }
            }
        }

        // Auto-calculate word count
        $content = get_post_field('post_content', $post_id);
        if ($content) {
            update_post_meta($post_id, '_ml_word_count', str_word_count(strip_tags($content)));
        }
    }

    /**
     * Create BuddyPress activity
     */
    private static function create_activity(
        int $user_id,
        int $post_id,
        string $title,
        string $content,
        ?int $space_id
    ): ?int {
        if (!function_exists('bp_activity_add')) {
            return null;
        }

        $user_name = bp_core_get_user_displayname($user_id);
        $post_url = get_permalink($post_id);

        $activity_args = [
            'user_id' => $user_id,
            'component' => $space_id ? 'groups' : 'activity',
            'type' => 'ml_publication',
            'action' => sprintf(
                '%s a publié <a href="%s">%s</a>',
                $user_name,
                esc_url($post_url),
                esc_html($title)
            ),
            'content' => wp_trim_words($content, 50),
            'primary_link' => $post_url,
            'item_id' => $space_id ?: 0,
            'secondary_item_id' => $post_id,
        ];

        $activity_id = bp_activity_add($activity_args);

        if ($activity_id) {
            update_post_meta($post_id, '_ml_activity_id', $activity_id);
        }

        return $activity_id ?: null;
    }

    /**
     * Normalize type value (apply aliases)
     */
    private static function normalize_type(string $type): string {
        $type = strtolower(trim($type));
        return self::TYPE_ALIASES[$type] ?? $type;
    }


    /**
     * Validate a canonical type (after normalization). Returns canonical type or null if invalid.
     */
    private static function validate_type(?string $type): ?string {
        if ($type === null || $type === '') { return null; }
        $type = self::normalize_type($type);
        if (!in_array($type, self::get_core_labels(), true)) {
            return null;
        }
        return $type;
    }

    /**
     * Validate and normalize labels (publication_label taxonomy). Returns list of canonical slugs.
     * Invalid labels are rejected to prevent auto-creation of taxonomy terms.
     */
    private static function validate_labels($labels, ?int $space_id = null): array {
        if ($labels === null) { return []; }
        if (!is_array($labels)) { return []; }

        $core = self::get_core_labels();
        $blocked = self::get_blocked_labels();

        $allowed_in_space = self::get_space_allowed_labels($space_id);
        $space_allow = $allowed_in_space ? array_values(array_unique(array_merge($core, $allowed_in_space))) : null;

        $out = [];

        foreach ($labels as $l) {
            if ($l === null) { continue; }

            $raw = sanitize_text_field((string) $l);
            $raw = strtolower(trim($raw));
            if ($raw === '') { continue; }

            // Blacklist: refuse data/doc even if those terms exist
            if (in_array($raw, $blocked, true)) {
                throw new \InvalidArgumentException("Label interdit: $raw");
            }

            // Normalize synonyms (but keep blacklist enforced above)
            $slug = self::normalize_type($raw);
            $slug = strtolower(trim($slug));

            // Per-space allowlist (if configured)
            if ($space_allow !== null && !in_array($slug, $space_allow, true)) {
                throw new \InvalidArgumentException("Label non autorisé dans l'espace #$space_id: $slug");
            }

            // Always allow core
            if (in_array($slug, $core, true)) {
                $out[] = $slug;
                continue;
            }

            // Semi-open: allow only if already exists (created by admin/backoffice)
            if (self::label_exists($slug)) {
                $out[] = $slug;
                continue;
            }

            // Reject unknown labels (no term pollution)
            throw new \InvalidArgumentException("Label inconnu: $slug (doit exister déjà ou être core)");
        }

        return array_values(array_unique($out));
    }

    /**
     * Ensure an allowed label term exists in publication_label taxonomy.
     */
    private static function label_exists(string $slug): bool {
        return (bool) term_exists($slug, 'publication_label');
    }

    /**
     * Ensure a CORE label term exists in publication_label taxonomy.
     * Never creates non-core labels (prevents LLM term pollution).
     */
    private static function ensure_core_label_term(string $slug): void {
        if (!in_array($slug, self::get_core_labels(), true)) { return; }
        if (!term_exists($slug, 'publication_label')) {
            wp_insert_term(ucfirst($slug), 'publication_label', ['slug' => $slug]);
        }
    }

    /**
     * Generate title from content
     */
    private static function generate_title(string $content): string {
        $text = strip_tags($content);
        $text = trim($text);

        if (empty($text)) {
            return 'Publication sans titre';
        }

        // Take first line or first 60 chars
        $lines = explode("\n", $text);
        $first_line = trim($lines[0]);

        if (strlen($first_line) <= 60) {
            return $first_line;
        }

        return wp_trim_words($first_line, 8, '...');
    }

    /**
     * Generate excerpt (summary) from content using AI
     * Falls back to first paragraph if AI unavailable
     */
    private static function generate_excerpt(string $content): string {
        $text = strip_tags($content);
        $text = trim($text);

        if (empty($text)) {
            return '';
        }

        // Try AI summary if content is long enough
        if (mb_strlen($text) > 100 && LLM_Runtime::is_available()) {
            try {
                $truncated = mb_substr($text, 0, 2000);
                $result = LLM_Runtime::execute(
                    "Résume ce texte en 1 à 2 phrases concises (max 180 caractères). Réponds UNIQUEMENT avec le résumé, sans guillemets ni préfixe :\n\n" . $truncated,
                    [
                        'max_tokens' => 100,
                        'temperature' => 0.3,
                    ]
                );
                if (!empty($result['ok']) && !empty($result['text'])) {
                    $summary = trim($result['text']);
                    // Remove surrounding quotes if any
                    $summary = trim($summary, '"\'');
                    if (mb_strlen($summary) > 10 && mb_strlen($summary) <= 300) {
                        return $summary;
                    }
                }
            } catch (\Throwable $e) {
                // Fall through to manual excerpt
            }
        }

        // Fallback: first real paragraph (skip markdown headers)
        $paragraphs = preg_split('/\n\s*\n/', $text);
        foreach ($paragraphs as $para) {
            $para = trim($para);
            if (empty($para) || preg_match('/^#+\s/', $para)) {
                continue;
            }
            if (mb_strlen($para) <= 200) {
                return $para;
            }
            return wp_trim_words($para, 30, '...');
        }

        return '';
    }

    /**
     * Check if user can post to space
     */
    /**
     * Get default label for a space
     */
    private static function get_space_default_label(?int $space_id): ?string {
        if (!$space_id) return null;
        $default_id = get_post_meta($space_id, '_space_default_label', true);
        if (!$default_id) return null;
        $term = get_term($default_id, 'publication_label');
        return ($term && !is_wp_error($term)) ? $term->slug : null;
    }

    private static function can_post_to_space(int $user_id, int $space_id): bool {
        // Use Permission_Service for centralized check
        if (class_exists(Permission_Service::class)) {
            return Permission_Service::can_post_to_space($user_id, $space_id);
        }

        // Fallback: legacy logic
        if (!function_exists('groups_get_group')) {
            return true; // No BuddyPress, allow
        }

        $group = groups_get_group($space_id);
        if (!$group || !$group->id) {
            return false;
        }

        // Admin can always post
        if (user_can($user_id, 'manage_options')) {
            return true;
        }

        // Check membership
        if (!groups_is_user_member($user_id, $space_id)) {
            // Check if public group allows non-member posting
            if ($group->status !== 'public') {
                return false;
            }
        }

        // Check if user is banned
        if (function_exists('groups_is_user_banned') && groups_is_user_banned($user_id, $space_id)) {
            return false;
        }

        return true;
    }

    /**
     * Check if user can edit publication
     */
    private static function can_edit_publication(int $user_id, $post): bool {
        // Use Permission_Service for centralized permission check
        if (class_exists(Permission_Service::class)) {
            $result = Permission_Service::check(
                $user_id,
                Permission_Service::ACTION_UPDATE,
                Permission_Service::RESOURCE_PUBLICATION,
                $post->ID
            );

            if (!$result['allowed']) {
                Permission_Service::log_denial($user_id, 'update', 'publication', $post->ID, $result['reason']);
            }

            return $result['allowed'];
        }

        // Fallback: legacy logic
        // Author can always edit
        if ((int) $post->post_author === $user_id) {
            return true;
        }

        // Admin can edit
        if (user_can($user_id, 'manage_options')) {
            return true;
        }

        // Space admin can edit posts in their space
        $space_id = get_post_meta($post->ID, '_ml_space_id', true);
        if ($space_id && function_exists('groups_is_user_admin')) {
            if (groups_is_user_admin($user_id, $space_id)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Track tool usage for statistics
     */
    private static function track_tool_usage(int $tool_id, int $user_id): void {
        // Increment tool usage count
        $count = (int) get_post_meta($tool_id, '_ml_tool_usage_count', true);
        update_post_meta($tool_id, '_ml_tool_usage_count', $count + 1);

        // Add to user's recent tools
        $recent = get_user_meta($user_id, 'ml_recent_tools', true) ?: [];
        array_unshift($recent, [
            'tool_id' => $tool_id,
            'used_at' => current_time('mysql'),
        ]);
        $recent = array_slice($recent, 0, 20); // Keep last 20
        update_user_meta($user_id, 'ml_recent_tools', $recent);
    }

    /**
     * Return error response (delegates to Tool_Response)
     */
    private static function error(string $code, string $message): array {
        return Tool_Response::error($code, $message);
    }

    /**
     * Soft-delete a publication (move to WordPress trash).
     * This is reversible via wp_untrash_post in WP admin.
     */
    private static function trash_publication(int $user_id, int $publication_id): array {
        // Permission
        if (!Permission_Service::can_delete($user_id, Permission_Service::RESOURCE_PUBLICATION, $publication_id)) {
            return Tool_Response::permission_error('supprimer', "publication #$publication_id");
        }

        $post = get_post($publication_id);
        if (!$post || $post->post_type !== 'publication') {
            return Tool_Response::not_found_error("publication #$publication_id introuvable");
        }

        $res = wp_trash_post($publication_id);
        if (!$res) {
            return self::error('trash_failed', "Impossible de déplacer la publication #$publication_id à la corbeille");
        }

        return [
            'success' => true,
            'mode' => 'trashed',
            'message' => 'Publication déplacée à la corbeille',
            'publication' => [
                'id' => $publication_id,
                'title' => get_post_field('post_title', $publication_id, 'raw'),
                'status' => 'trash',
                'type' => \MCP_No_Headless\Schema\Publication_Schema::get_type($publication_id) ?: 'content',
                'space_id' => (int) (get_post_meta($publication_id, '_ml_space_id', true) ?: (get_post($publication_id)->post_parent ?? 0)),
            ],
        ];
    }

}
