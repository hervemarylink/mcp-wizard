<?php
/**
 * Tool Catalog V3 - 6 Core Tools aligned with natural questions
 *
 * Architecture:
 * - Strate 1 (Core): 6 outils universels, stables, testés multi-LLM
 * - Strate 2 (Packs): Outils avancés activables
 * - Strate 3 (Custom): Fonctions sur-mesure par client
 *
 * @package MCP_No_Headless
 * @since 3.0.0
 */

namespace MCP_No_Headless\MCP\Core;

class Tool_Catalog_V3 {

    /**
     * Version
     */
    const VERSION = '3.2.9';

    /**
     * Strate constants
     */
    const STRATE_CORE = 'core';
    const STRATE_PACK = 'pack';
    const STRATE_CUSTOM = 'custom';

    /**
     * Legacy tool mappings (V2 name => V3 equivalent)
     */
    const LEGACY_MAPPINGS = [
        // Search/Read -> ml_find
        'ml_publication_get' => ['tool' => 'ml_find', 'transform' => 'publication_to_find'],
        'ml_publications_list' => ['tool' => 'ml_find', 'transform' => 'publications_to_find'],
        'ml_search' => ['tool' => 'ml_find', 'transform' => 'search_to_find'],
        'ml_search_advanced' => ['tool' => 'ml_find', 'transform' => 'search_to_find'],

        // Context -> ml_me
        'ml_spaces_list' => ['tool' => 'ml_me', 'transform' => 'spaces_to_me'],
        'ml_get_my_context' => ['tool' => 'ml_me', 'transform' => 'context_to_me'],
        'ml_favorites_list' => ['tool' => 'ml_me', 'transform' => 'favorites_to_me'],

        // Execute -> ml_run
        'ml_apply_tool' => ['tool' => 'ml_run', 'transform' => 'apply_to_run'],
        'ml_context_bundle_build' => ['tool' => 'ml_run', 'transform' => 'bundle_to_run'],

        // Create/Update -> ml_save
        'ml_publication_create' => ['tool' => 'ml_save', 'transform' => 'create_to_save'],
        'ml_publication_update' => ['tool' => 'ml_save', 'transform' => 'update_to_save'],

        // Recommend -> ml_assist
        'ml_recommend' => ['tool' => 'ml_assist', 'transform' => 'recommend_to_assist'],
        'ml_assist_prepare' => ['tool' => 'ml_assist', 'transform' => 'assist_to_assist'],

        // Feedback -> ml_me
        'ml_feedback' => ['tool' => 'ml_me', 'transform' => 'feedback_to_me'],
        'ml_rate' => ['tool' => 'ml_me', 'transform' => 'rate_to_me'],
    ];

    /**
     * Get all 6 Core tool definitions
     *
     * @return array
     */
    public static function get_core_tools(): array {
        return [
            self::tool_ml_assist(),
            self::tool_ml_find(),
            self::tool_ml_run(),
            self::tool_ml_save(),
            self::tool_ml_me(),
            self::tool_ml_ping(),
            self::tool_ml_image(),
        ];
    }

    /**
     * Build tool catalog based on context
     *
     * @param array $ctx Context: user_id, strate, packs_active
     * @return array Tool definitions for MCP
     */
    public static function build(array $ctx = []): array {
        $strate = $ctx['strate'] ?? self::STRATE_CORE;
        $packs_active = $ctx['packs_active'] ?? [];

        $tools = self::get_core_tools();

        // Strate 2: Add active pack tools
        if ($strate !== self::STRATE_CORE && !empty($packs_active)) {
            foreach ($packs_active as $pack) {
                $pack_tools = self::get_pack_tools($pack);
                $tools = array_merge($tools, $pack_tools);
            }
        }

        // Strate 3: Add custom tools for client
        if ($strate === self::STRATE_CUSTOM && isset($ctx['client_id'])) {
            $custom_tools = self::get_custom_tools($ctx['client_id']);
            $tools = array_merge($tools, $custom_tools);
        }

        return array_map([self::class, 'format_for_mcp'], $tools);
    }

    /**
     * ml_assist - Orchestrateur intelligent
     * "Aide-moi à faire..."
     */
    private static function tool_ml_assist(): array {
        return [
            'name' => 'ml_assist',
            'strate' => self::STRATE_CORE,
            'description' => <<<'DESC'
■ UTILISER QUAND : L'utilisateur demande de l'aide, cherche un outil adapté, ou veut une suggestion.
■ NE PAS UTILISER SI : L'utilisateur connaît déjà l'outil exact à utiliser (→ ml_run).

Orchestrateur intelligent qui analyse le contexte, détecte l'intention, et propose le meilleur outil.

**Modes disponibles :**
- **suggest** (défaut) : propose l'outil le plus adapté
- **apply** : exécute directement l'outil suggéré
- **create** : assemble un nouvel outil (voir mécanique ci-dessous)

---

## 🔧 MECANIQUE action=create (assemblage d'outil)

Quand on crée un outil Marylink, suivre **OBLIGATOIREMENT** cette séquence :

### 1️⃣ Créer les **CONTENUS de référence**
- Frameworks méthodologiques
- Templates réutilisables
- Données de référence
→ **Type: content, status: publish**

### 2️⃣ Identifier/Créer le **STYLE d'écriture**
- Chercher un style existant : `ml_find type=style`
- Ou créer un nouveau style
→ **Type: style, status: publish**

### 3️⃣ Créer l'**OUTIL** (prompt)
Le prompt **DOIT** inclure :
- Section "Ressources de référence" avec **URLs des contenus**
- Section "Style d'écriture" avec **URL du style**

**Structure type :**
```
# [Nom de l'outil]
[Description du rôle de l'IA]

## Ressources de référence
- [Framework] : https://[instance].marylink.net/publication/[slug]/
- [Template] : https://[instance].marylink.net/publication/[slug]/

## Style d'écriture
Adopte ce style : https://[instance].marylink.net/publication/[slug-style]/

## Instructions
[Instructions détaillées utilisant les ressources]
```

⚠️ **Un outil sans contenus de référence = juste un prompt simple, pas un vrai outil Marylink.**
DESC,
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'context' => [
                        'type' => 'string',
                        'description' => 'Texte/demande de l\'utilisateur à analyser (REQUIS)',
                    ],
                    'action' => [
                        'type' => 'string',
                        'enum' => ['suggest', 'apply', 'create', 'labels', 'job'],
                        'description' => 'suggest: propose un outil | apply: exécute | create: assemble',
                    ],
                    'tool_id' => [
                        'type' => 'integer',
                        'description' => 'ID de l\'outil si action=apply',
                    ],
                    'space_id' => [
                        'type' => 'integer',
                        'description' => 'Filtrer la recherche à un espace',
                    ],
                ],
                'required' => ['context'],
            ],
            'annotations' => ['readOnlyHint' => true],
        ];
    }

    /**
     * ml_find - Recherche et lecture
     * "Cherche..." ou "Montre-moi..."
     */
    private static function tool_ml_find(): array {
        return [
            'name' => 'ml_find',
            'strate' => self::STRATE_CORE,
            'description' => <<<'DESC'
■ UTILISER QUAND : L'utilisateur veut chercher ou lire du contenu.
■ NE PAS UTILISER SI : L'utilisateur veut exécuter un outil (→ ml_run).

Outil unifié pour chercher ET lire des publications.
- Mode recherche: query="lettre relance"
- Mode lecture: id=456
DESC,
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'description' => 'Termes de recherche (OU id)',
                    ],
                    'id' => [
                        'type' => 'integer',
                        'description' => 'ID publication à lire directement (OU query)',
                    ],
                    'type' => [
                        'type' => 'string',
                        'enum' => ['content','tool','prompt','style','client','projet','publication'],
                        'description' => 'Filtrer par type',
                    ],
                    'space_id' => [
                        'type' => 'integer',
                        'description' => 'Filtrer par espace',
                    ],
                    'format' => [
                        'type' => 'string',
                        'enum' => ['full', 'summary', 'raw'],
                        'description' => 'Format de sortie (défaut: full)',
                    ],
'include' => [
    'type' => 'array',
    'items' => [
        'type' => 'string',
        'enum' => ['content','metadata','comments','reviews'],
    ],
    'description' => "Expansion optionnelle: content, metadata, comments, reviews.
Note: en mode lecture par id (ml_find id=...), si 'include' est omis, metadata+reviews sont auto-ajoutés pour éviter que les agents oublient les avis.",
],
'sort' => [
    'type' => 'string',
    'enum' => ['best','best_rated','top_rated','trending','most_commented','most_liked','most_favorited'],
    'description' => 'Tri: best (hybride), best_rated (par note), top_rated (uniquement notés, strict), trending, most_*',
],
'offset' => [
    'type' => 'integer',
    'description' => 'Décalage pagination (défaut: 0)',
],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Nombre max résultats (défaut: 10, max: 50)',
                    ],
                    'period' => [
                        'type' => 'string',
                        'enum' => ['7d', '30d', '90d', '1y', 'all'],
                        'description' => 'Filtrer par période',
                    ],
                ],
            ],
            'annotations' => ['readOnlyHint' => true],
        ];
    }

    /**
     * ml_run - Exécution polyvalente
     * "Fais ça" ou "Applique ce prompt"
     */
    private static function tool_ml_run(): array {
        return [
            'name' => 'ml_run',
            'strate' => self::STRATE_CORE,
            'description' => <<<'DESC'
■ UTILISER QUAND : L'utilisateur veut exécuter un prompt/outil sur du contenu.
■ NE PAS UTILISER SI : L'utilisateur cherche juste de l'info (→ ml_find).

Exécution POLYVALENTE couvrant 95% des cas:
1. Texte brut: tool_id + input
2. Publication: tool_id + source_id
3. Multi-docs: tool_id + source_ids (max 5 sync, 100 async)
4. Chaînage: tool_id + then (applique résultat au 2ème)
5. Avec contexte: with_context=true injecte auto les entités métier détectées
6. Avec sauvegarde: save_to=space_id ou "draft"

**Modes d'exécution :**
- **sync** (défaut): appelle l'API IA et retourne le résultat
- **async**: lance un job en arrière-plan, retourne job_id
- **delegate**: ⭐ NOUVEAU - retourne le prompt assemblé pour que Claude l'exécute nativement

💡 **mode=delegate** évite l'appel API externe : Claude traite directement le prompt retourné.
DESC,
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'tool_id' => [
                        'type' => 'integer',
                        'description' => 'ID du prompt/outil à exécuter (REQUIS)',
                    ],
                    'input' => [
                        'type' => 'string',
                        'description' => 'Texte brut à traiter',
                    ],
                    'source_id' => [
                        'type' => 'integer',
                        'description' => 'ID publication à traiter (alternative à input)',
                    ],
                    'source_ids' => [
                        'type' => 'array',
                        'items' => ['type' => 'integer'],
                        'description' => 'IDs publications pour traitement multi-docs (max 5 sync)',
                    ],
                    'then' => [
                        'type' => 'integer',
                        'description' => 'ID prompt à chaîner (appliqué sur résultat)',
                    ],
                    'with_context' => [
                        'type' => 'boolean',
                        'description' => 'Injecter contexte métier auto-détecté (défaut: true)',
                    ],
                    'style' => [
                        'type' => 'string',
                        'description' => 'Variante de style (formel, direct, premium...)',
                    ],
                    'language' => [
                        'type' => 'string',
                        'description' => 'Langue de sortie (fr, en, es...)',
                    ],
                    'output_format' => [
                        'type' => 'string',
                        'enum' => ['text', 'markdown', 'html', 'json'],
                        'description' => 'Format de sortie (docx prévu dans une version future)',
                    ],
                    'save_to' => [
                        'type' => ['integer', 'string'],
                        'description' => 'Sauvegarder: space_id (int) ou "draft"',
                    ],
                    'mode' => [
                        'type' => 'string',
                        'enum' => ['sync', 'async', 'delegate'],
                        'description' => 'sync: exécute via API IA | async: job | delegate: retourne prompt pour Claude',
                    ],
                    'async' => [
                        'type' => 'boolean',
                        'description' => '[DEPRECATED: utiliser mode=async] Mode asynchrone pour tâches longues',
                    ],
                    'webhook_url' => [
                        'type' => 'string',
                        'description' => 'URL callback quand job async terminé',
                    ],
                ],
                'required' => ['tool_id'],
            ],
            'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false],
        ];
    }

    /**
     * ml_save - Sauvegarde
     * "Enregistre..."
     */
    private static function tool_ml_save(): array {
        return [
            'name' => 'ml_save',
            'strate' => self::STRATE_CORE,
            'description' => <<<'DESC'
■ UTILISER QUAND : L'utilisateur veut créer ou modifier une publication.
■ NE PAS UTILISER SI : Le résultat d'un ml_run doit être sauvé (utiliser save_to dans ml_run).

Crée ou modifie une publication. Si id fourni = modification.
DESC,
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'content' => [
                        'type' => 'string',
                        'description' => 'Contenu à sauvegarder (Markdown supporté) (REQUIS)',
                    ],
                    'title' => [
                        'type' => 'string',
                        'description' => 'Titre (auto-généré si absent)',
                    ],
                    'id' => [
                        'type' => 'integer',
                        'description' => 'ID existant = modification',
                    ],
                    'space_id' => [
                        'type' => 'integer',
                        'description' => 'Espace cible (défaut: espace personnel)',
                    ],
                    'type' => [
                        'type' => 'string',
                        'enum' => ['content','tool','prompt','style','client','projet','publication'],
                        'description' => 'Type de contenu',
                    ],
                    'status' => [
                        'type' => 'string',
                        'enum' => ['draft', 'publish', 'pending', 'trash'],
                        'description' => 'Statut (défaut: draft). "trash" = suppression (corbeille)',
                    ],
                    'tags' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Tags à appliquer',
                    ],
                    'labels' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Labels à appliquer',
                    ],
                ],
                'required' => ['content'],
            ],
            'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false],
        ];
    }

    /**
     * ml_me - Contexte utilisateur complet
     * "Qui suis-je?" "Mes clients?"
     */
    private static function tool_ml_me(): array {
        return [
            'name' => 'ml_me',
            'strate' => self::STRATE_CORE,
            'description' => <<<'DESC'
■ UTILISER QUAND : L'utilisateur demande ses infos, espaces, clients, stats, ou veut donner du feedback.
■ NE PAS UTILISER SI : L'utilisateur cherche du contenu public (→ ml_find).

Cockpit utilisateur unifié:
- profile: Mon profil et préférences
- spaces: Mes espaces accessibles
- context: Mon contexte métier (clients, projets, produits)
- feedback: Envoyer un feedback 👍/👎
- audit: Mon historique d'actions
- stats: Mes statistiques d'usage
- jobs: Mes jobs asynchrones en cours
- quotas: Mes quotas et limites
DESC,
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'action' => [
                        'type' => 'string',
                        'enum' => ['profile', 'spaces', 'context', 'feedback', 'audit', 'stats', 'jobs', 'job', 'quotas', 'labels'],
                        'description' => 'Action (défaut: profile)',
                    ],
                    // For action=spaces
                    'filter' => [
                        'type' => 'string',
                        'enum' => ['all', 'subscribed', 'owned', 'recent'],
                        'description' => 'Filtre espaces',
                    ],
                    // For action=context
                    'type' => [
                        'type' => 'string',
                        'enum' => ['content','tool','prompt','style','client','projet','publication'],
                        'description' => 'Type de contexte',
                    ],
                    'name' => [
                        'type' => 'string',
                        'description' => 'Nom pour lecture/création de fiche contexte',
                    ],
                    'content' => [
                        'type' => 'string',
                        'description' => 'Contenu pour création de fiche contexte',
                    ],
                    // For action=feedback
                    'execution_id' => [
                        'type' => 'string',
                        'description' => 'ID de l\'exécution à noter',
                    ],
                    'feedback_type' => [
                        'type' => 'string',
                        'enum' => ['positive', 'negative'],
                        'description' => 'Type de feedback',
                    ],
                    'comment' => [
                        'type' => 'string',
                        'description' => 'Commentaire optionnel',
                    ],
                    'feedback_text' => [
                        'type' => 'string',
                        'description' => 'Alias de comment (recommandé pour compat API): texte de feedback',
                    ],
                    // For action=audit/stats
                    'period' => [
                        'type' => 'string',
                        'enum' => ['1h', '24h', '7d', '30d', '90d'],
                        'description' => 'Période pour audit/stats',
                    ],
                    // For action=job
                    'id' => [
                        'type' => 'string',
                        'description' => 'ID du job async à consulter',
                    ],
                ],
            ],
            'annotations' => ['readOnlyHint' => true],
        ];
    }

    /**
     * ml_ping - Diagnostic
     * "Ça marche?"
     */
    private static function tool_ml_ping(): array {
        return [
            'name' => 'ml_ping',
            'strate' => self::STRATE_CORE,
            'description' => <<<'DESC'
■ UTILISER QUAND : Vérifier que le serveur MCP fonctionne, diagnostic de connexion.
■ NE PAS UTILISER SI : Tout fonctionne normalement.

Retourne l'état du serveur, version, latence, et statut d'authentification.
Aucun paramètre requis.
DESC,
            'inputSchema' => [
                'type' => 'object',
                'properties' => [],
            ],
            'annotations' => ['readOnlyHint' => true],
        ];
    }


    private static function tool_ml_image(): array {
        return [
            'name' => 'ml_image',
            'strate' => self::STRATE_CORE,
            'description' => <<<'DESC'
■ UTILISER QUAND : Attacher une image générée par Claude à une publication existante.
■ NE PAS UTILISER SI : La publication n'existe pas encore (utiliser ml_save d'abord).

Reçoit une image en base64 et l'attache comme image mise en avant (featured image) de la publication.

Workflow typique :
1. Créer la publication avec ml_save
2. Générer une image illustrative
3. Appeler ml_image avec publication_id et l'image en base64

Formats acceptés : PNG, JPEG, WebP.
DESC,
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'publication_id' => [
                        'type' => 'integer',
                        'description' => 'ID de la publication à illustrer',
                    ],
                    'image_base64' => [
                        'type' => 'string',
                        'description' => 'Image encodée en base64 (avec ou sans préfixe data:image/...;base64,)',
                    ],
                    'alt' => [
                        'type' => 'string',
                        'description' => 'Texte alternatif de l\'image (accessibilité)',
                    ],
                    'caption' => [
                        'type' => 'string',
                        'description' => 'Légende de l\'image',
                    ],
                ],
                'required' => ['publication_id', 'image_base64'],
            ],
        ];
    }
    /**
     * Get pack tools (Strate 2)
     *
     * @param string $pack Pack name
     * @return array
     */
    public static function get_pack_tools(string $pack): array {
        $packs = [
            'productivity' => ['ml_bulk', 'ml_chain', 'ml_schedule'],
            'quality' => ['ml_compare', 'ml_rate', 'ml_improve', 'ml_validate'],
            'collaboration' => ['ml_comment', 'ml_assign', 'ml_notify', 'ml_share'],
            'analytics' => ['ml_stats_adv', 'ml_usage', 'ml_leaderboard', 'ml_export_data'],
            'integration' => ['ml_webhook', 'ml_import', 'ml_export', 'ml_sync'],
        ];

        // TODO: Implement pack tool definitions
        return [];
    }

    /**
     * Get custom tools for a client (Strate 3)
     *
     * @param string $client_id
     * @return array
     */
    public static function get_custom_tools(string $client_id): array {
        // TODO: Load from Custom/{ClientID}/config.json
        return [];
    }

    /**
     * Format tool for MCP protocol
     *
     * @param array $tool
     * @return array
     */
    private static function format_for_mcp(array $tool): array {
        return [
            'name' => $tool['name'],
            'description' => $tool['description'],
            'inputSchema' => $tool['inputSchema'] ?? ['type' => 'object', 'properties' => []],
            'annotations' => $tool['annotations'] ?? [],
        ];
    }

    /**
     * Check if a tool name is a legacy V2 tool
     *
     * @param string $name
     * @return bool
     */
    public static function is_legacy_tool(string $name): bool {
        return isset(self::LEGACY_MAPPINGS[$name]);
    }

    /**
     * Get the V3 tool for a legacy V2 tool name
     *
     * @param string $legacy_name
     * @return array|null ['tool' => string, 'transform' => string]
     */
    public static function get_legacy_mapping(string $legacy_name): ?array {
        return self::LEGACY_MAPPINGS[$legacy_name] ?? null;
    }

    /**
     * Get tool counts by strate
     *
     * @return array
     */
    public static function get_counts(): array {
        return [
            'core' => count(self::get_core_tools()),
            'total' => count(self::get_core_tools()),
        ];
    }

    /**
     * Get all V3 tool names
     *
     * @return array
     */
    public static function get_tool_names(): array {
        return ['ml_assist', 'ml_find', 'ml_run', 'ml_save', 'ml_me', 'ml_ping'];
    }
}
