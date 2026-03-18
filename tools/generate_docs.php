<?php
declare(strict_types=1);

$root = dirname( __DIR__ );
$docsRoot = $root . '/docs';
$modulesRoot = $root . '/includes/modules';
$srcModulesRoot = $root . '/src/Metis/Modules';

function metis_docs_mkdir( string $path ): void {
    if ( file_exists( $path ) && ! is_dir( $path ) ) {
        unlink( $path );
    }
    if ( ! is_dir( $path ) ) {
        mkdir( $path, 0775, true );
    }
}

function metis_docs_write( string $path, string $content ): void {
    metis_docs_mkdir( dirname( $path ) );
    file_put_contents( $path, $content );
}

function metis_docs_json( string $path, array $payload ): void {
    metis_docs_write( $path, json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL );
}

function metis_docs_slug_title( string $slug ): string {
    return ucwords( str_replace( [ '_', '-' ], ' ', $slug ) );
}

function metis_docs_table_map( string $path ): array {
    $raw = file_get_contents( $path );
    preg_match_all( "/'([^']+)'\\s*=>\\s*'([^']+)'/", (string) $raw, $matches, PREG_SET_ORDER );
    $map = [];
    foreach ( $matches as $match ) {
        $map[ $match[1] ] = $match[2];
    }
    return $map;
}

function metis_docs_manifests( string $modulesRoot ): array {
    $manifests = [];
    foreach ( glob( $modulesRoot . '/*/*.json' ) ?: [] as $path ) {
        $decoded = json_decode( (string) file_get_contents( $path ), true );
        if ( ! is_array( $decoded ) ) {
            continue;
        }
        $decoded['_path'] = $path;
        $manifests[ (string) $decoded['slug'] ] = $decoded;
    }
    ksort( $manifests );
    return $manifests;
}

function metis_docs_actions_from_file( string $path ): array {
    if ( ! is_file( $path ) ) {
        return [];
    }
    preg_match_all( "/wp_ajax_([a-z0-9_]+)/i", (string) file_get_contents( $path ), $matches );
    $actions = array_values( array_unique( array_map( 'strval', $matches[1] ?? [] ) ) );
    sort( $actions );
    return $actions;
}

function metis_docs_tables_used( array $paths ): array {
    $tables = [];
    foreach ( $paths as $path ) {
        if ( ! is_file( $path ) ) {
            continue;
        }
        preg_match_all( "/Metis_Tables::get\\(\\s*'([^']+)'\\s*\\)/", (string) file_get_contents( $path ), $matches );
        foreach ( $matches[1] ?? [] as $table ) {
            $tables[] = (string) $table;
        }
    }
    $tables = array_values( array_unique( $tables ) );
    sort( $tables );
    return $tables;
}

function metis_docs_schema_from_file( string $path, array $tableMap ): array {
    if ( ! is_file( $path ) ) {
        return [];
    }

    $raw = (string) file_get_contents( $path );
    preg_match_all( "/\\$(\\w+)_table\\s*=\\s*\\\\?Metis_Tables::get\\(\\s*'([^']+)'\\s*\\)/", $raw, $varMatches, PREG_SET_ORDER );
    $vars = [];
    foreach ( $varMatches as $match ) {
        $vars[ $match[1] . '_table' ] = $match[2];
    }

    preg_match_all( "/CREATE TABLE(?: IF NOT EXISTS)?\\s+\\{\\$(\\w+)\\}\\s*\\((.*?)\\)\\s*(?:\\{\\$[a-z_]+\\}|\\$[a-z_]+)?;/si", $raw, $createMatches, PREG_SET_ORDER );
    $tables = [];

    foreach ( $createMatches as $match ) {
        $var = (string) $match[1];
        $key = $vars[ $var ] ?? $var;
        $body = trim( (string) $match[2] );
        $fields = [];
        $indexes = [];

        foreach ( preg_split( "/\\R/", $body ) ?: [] as $line ) {
            $line = trim( trim( $line ), ',' );
            if ( $line === '' ) {
                continue;
            }
            if ( preg_match( '/^(PRIMARY KEY|UNIQUE KEY|KEY)\\s+(.+)$/i', $line ) ) {
                $indexes[] = $line;
                continue;
            }
            if ( preg_match( '/^([a-zA-Z0-9_]+)\\s+(.+)$/', $line, $fieldMatch ) ) {
                $fields[] = [
                    'name' => $fieldMatch[1],
                    'definition' => $fieldMatch[2],
                ];
            }
        }

        $tables[ $key ] = [
            'name' => $tableMap[ $key ] ?? $key,
            'fields' => $fields,
            'indexes' => $indexes,
        ];
    }

    return $tables;
}

function metis_docs_find_module_source( string $srcModulesRoot, string $slug ): array {
    $title = str_replace( ' ', '', metis_docs_slug_title( $slug ) );
    $paths = glob( $srcModulesRoot . '/' . $title . '/*.php' ) ?: [];
    if ( $paths === [] && is_file( $srcModulesRoot . '/' . $title . 'Module.php' ) ) {
        $paths[] = $srcModulesRoot . '/' . $title . 'Module.php';
    }
    if ( $paths === [] ) {
        foreach ( glob( $srcModulesRoot . '/*.php' ) ?: [] as $path ) {
            if ( stripos( basename( $path ), str_replace( ' ', '', metis_docs_slug_title( $slug ) ) ) !== false ) {
                $paths[] = $path;
            }
        }
    }
    sort( $paths );
    return $paths;
}

function metis_docs_help_index( array $manifests ): array {
    $index = [
        'help.mode' => [
            'title' => 'Help Mode',
            'description' => 'Turn on contextual help to highlight tagged interface elements and open in-app guidance.',
            'learn_more' => '/docs/developer/help-system.md',
            'steps' => [
                'Use the Help button in the portal header or press Shift+H.',
                'Hover highlighted items to preview their purpose.',
                'Click a highlighted item to open the detailed help panel.',
            ],
        ],
        'help.search' => [
            'title' => 'Help Search',
            'description' => 'Search topics, documentation pages, and walkthroughs from a single modal.',
            'learn_more' => '/docs/developer/help-system.md',
            'steps' => [
                'Open Search Help from the header.',
                'Search by workflow, module, or administrative task.',
                'Launch a topic or walkthrough directly from results.',
            ],
        ],
    ];

    foreach ( $manifests as $slug => $manifest ) {
        $views = (array) ( $manifest['views'] ?? [] );
        $moduleTitle = (string) ( $manifest['label'] ?? metis_docs_slug_title( $slug ) );
        $moduleDescription = (string) ( $manifest['description'] ?? '' );
        $docPath = '/docs/modules/' . $slug . '.md';

        foreach ( $views as $view => $template ) {
            $topicId = $slug . '.' . $view;
            $index[ $topicId ] = [
                'title' => $moduleTitle . ' ' . metis_docs_slug_title( (string) $view ),
                'description' => $moduleDescription !== '' ? $moduleDescription : 'Reference for the ' . $moduleTitle . ' ' . $view . ' screen.',
                'learn_more' => $docPath,
                'steps' => [
                    'Open the ' . $moduleTitle . ' module from the sidebar.',
                    'Use page controls to review, filter, or update records.',
                    'Open the full documentation page for related routes, APIs, and tables.',
                ],
            ];
        }
    }

    ksort( $index );
    return $index;
}

function metis_docs_walkthroughs(): array {
    return [
        'create_deposit' => [
            'title' => 'Creating a Deposit',
            'description' => 'Walk through the donations deposit workflow.',
            'module' => 'donations',
            'topic' => 'donations.deposits',
            'trigger' => 'manual',
            'steps' => [
                [ 'target' => '[data-help="donations.dashboard"]', 'message' => 'Open the Donations module from the sidebar.', 'advance' => 'click' ],
                [ 'target' => '[data-help="donations.deposits"]', 'message' => 'Switch to the Deposits screen.', 'advance' => 'click' ],
                [ 'target' => '#create-deposit, #metis-create-deposit, [data-help="donations.deposits"] .mw-btn', 'message' => 'Use the create controls to start a new deposit record.', 'advance' => 'click' ],
            ],
        ],
        'manage_contacts' => [
            'title' => 'Managing Contacts',
            'description' => 'Guide for reviewing and updating contact profiles.',
            'module' => 'contacts',
            'topic' => 'contacts.dashboard',
            'trigger' => 'first_login',
            'steps' => [
                [ 'target' => '[data-help="contacts.dashboard"]', 'message' => 'Open Contacts from the sidebar.', 'advance' => 'click' ],
                [ 'target' => '.mw-main [data-metis-topic="contacts.dashboard"]', 'message' => 'Use filters and list controls to locate a contact.', 'advance' => 'next' ],
            ],
        ],
        'newsletter_campaign' => [
            'title' => 'Launching a Newsletter Campaign',
            'description' => 'Guide for creating and sending a newsletter campaign.',
            'module' => 'newsletter',
            'topic' => 'newsletter.campaigns',
            'trigger' => 'manual',
            'steps' => [
                [ 'target' => '[data-help="newsletter.dashboard"]', 'message' => 'Open Newsletter from the sidebar.', 'advance' => 'click' ],
                [ 'target' => '[data-help="newsletter.campaigns"]', 'message' => 'Go to Campaigns.', 'advance' => 'click' ],
                [ 'target' => '.mw-main [data-metis-topic="newsletter.campaigns"] .mw-btn', 'message' => 'Use the campaign actions to draft, test, and queue a send.', 'advance' => 'click' ],
            ],
        ],
        'finance_reconciliation' => [
            'title' => 'Reconciling Accounts',
            'description' => 'Guide for reviewing ledger balances against statements.',
            'module' => 'finance',
            'topic' => 'finance.reconciliations',
            'trigger' => 'manual',
            'steps' => [
                [ 'target' => '[data-help="finance.dashboard"]', 'message' => 'Open Finance from the sidebar.', 'advance' => 'click' ],
                [ 'target' => '[data-help="finance.reconciliations"]', 'message' => 'Open Reconcile.', 'advance' => 'click' ],
                [ 'target' => '.mw-main [data-metis-topic="finance.reconciliations"]', 'message' => 'Review statement balance, timing items, and follow-up notes before saving the reconciliation.', 'advance' => 'next' ],
            ],
        ],
    ];
}

metis_docs_mkdir( $docsRoot );
foreach ( [ 'overview', 'installation', 'user-guide', 'admin-guide', 'modules', 'database', 'api', 'developer', 'security', 'operations' ] as $section ) {
    metis_docs_mkdir( $docsRoot . '/' . $section );
}

$tableMap = metis_docs_table_map( $root . '/includes/core/tables.php' );
$manifests = metis_docs_manifests( $modulesRoot );

$schema = [];
foreach ( [
    $root . '/includes/core/db.php',
    $root . '/includes/modules/drive/legacy.php',
    $srcModulesRoot . '/Board/SchemaManager.php',
    $srcModulesRoot . '/Contacts/SchemaManager.php',
    $srcModulesRoot . '/Finance/SchemaManager.php',
    $srcModulesRoot . '/Forms/SchemaManager.php',
    $srcModulesRoot . '/Newsletter/SchemaManager.php',
    $srcModulesRoot . '/People/SchemaManager.php',
    $srcModulesRoot . '/GrandyStashSchemaManager.php',
    $srcModulesRoot . '/Calendar/SyncStore.php',
] as $schemaPath ) {
    $schema = array_replace( $schema, metis_docs_schema_from_file( $schemaPath, $tableMap ) );
}
krsort( $schema );

$readme = "# Metis Documentation\n\n";
$readme .= "Generated from the repository structure, manifests, schema managers, and AJAX handlers.\n\n";
$readme .= "## Sections\n\n";
$readme .= "- [System Overview](./overview/system-overview.md)\n";
$readme .= "- [Installation](./installation/setup.md)\n";
$readme .= "- [User Guide](./user-guide/navigation-and-workflows.md)\n";
$readme .= "- [Admin Guide](./admin-guide/administration.md)\n";
$readme .= "- [Modules](./modules/)\n";
$readme .= "- [Database](./database/schema.md)\n";
$readme .= "- [API](./api/endpoints.md)\n";
$readme .= "- [Developer Guide](./developer/developer-guide.md)\n";
$readme .= "- [Security](./security/security-model.md)\n";
$readme .= "- [Operations](./operations/operations.md)\n";
metis_docs_write( $docsRoot . '/README.md', $readme );

$overview = "# System Overview\n\n";
$overview .= "Metis is a modular portal for operations, donor management, finance, communications, governance, people administration, calendar, and drive workflows. The repository is organized around a layered request flow of `router -> core services -> UI services -> modules`.\n\n";
$overview .= "## Major Capabilities\n\n";
foreach ( $manifests as $slug => $manifest ) {
    $overview .= "- **" . ( $manifest['label'] ?? metis_docs_slug_title( $slug ) ) . "**: " . ( $manifest['description'] ?? 'Module capability area.' ) . "\n";
}
$overview .= "\n## Architecture\n\n";
$overview .= "- **Router**: `includes/core/router.php` and `src/Metis/Http` normalize requests, enforce middleware, and dispatch portal, AJAX, webhook, and cron traffic.\n";
$overview .= "- **Core services**: `includes/core/service_registry.php` registers shared settings, DB, auth, router, backup, release, help, and walkthrough services.\n";
$overview .= "- **UI services**: `assets/core.js` provides the shared UI runtime; module assets extend it without replacing the base layer.\n";
$overview .= "- **Modules**: `includes/modules/*/*.json` define menus, views, permissions, assets, and extension hooks. PHP templates and module services implement behavior.\n";
metis_docs_write( $docsRoot . '/overview/system-overview.md', $overview );

$install = "# Installation\n\n";
$install .= "## Server Requirements\n\n";
$install .= "- PHP 8.1+ with JSON, mbstring, OpenSSL, PDO/MySQLi, and file upload support.\n";
$install .= "- MariaDB or MySQL compatible with the schema created through `dbDelta` installers.\n";
$install .= "- Writable access for logs and storage paths used by Metis.\n";
$install .= "\n## Setup\n\n";
$install .= "1. Place the repository in the web root or plugin/application directory expected by your WordPress or standalone runtime.\n";
$install .= "2. Configure `config/database.php` and environment-specific settings before first boot.\n";
$install .= "3. Ensure `includes/core/db.php` runs so core tables and module schema managers can create their tables.\n";
$install .= "4. Open the portal, authenticate, and complete initial settings for branding, API keys, workspace integration, help, and scheduling.\n";
$install .= "\n## First Boot\n\n";
$install .= "- The core bootstrap loads autoloading, service registration, routing, security boundaries, and module manifests.\n";
$install .= "- Modules boot from `includes/modules/*` and may run schema installers from their namespaced services.\n";
$install .= "- Initial admin work should include settings review, backup configuration, cron secret setup, and help system verification.\n";
metis_docs_write( $docsRoot . '/installation/setup.md', $install );

$userGuide = "# User Guide\n\n";
$userGuide .= "## Navigating the Interface\n\n";
$userGuide .= "- Use the sidebar to move between modules and sub-views.\n";
$userGuide .= "- Use breadcrumbs on detail screens to move back to parent lists.\n";
$userGuide .= "- Use the Help toggle or `Shift+H` to show contextual guidance.\n";
$userGuide .= "\n## Managing Records\n\n";
$userGuide .= "- Core list screens expose search, filtering, detail pages, and AJAX-backed updates.\n";
$userGuide .= "- Inline edits, modals, and guided walkthroughs all rely on the shared Metis UI runtime.\n";
$userGuide .= "\n## Reports\n\n";
$userGuide .= "- Finance, donations, and portal dashboards aggregate activity instead of issuing repetitive row-by-row queries.\n";
$userGuide .= "- Saved report definitions and export flows use module-specific AJAX endpoints and PDF/reporting services.\n";
$userGuide .= "\n## Module Usage\n\n";
foreach ( $manifests as $slug => $manifest ) {
    $userGuide .= "- **" . ( $manifest['label'] ?? $slug ) . "**: See [`../modules/" . $slug . ".md`](../modules/" . $slug . ".md) for routes, screens, APIs, and tables.\n";
}
metis_docs_write( $docsRoot . '/user-guide/navigation-and-workflows.md', $userGuide );

$adminGuide = "# Admin Guide\n\n";
$adminGuide .= "## Users, Permissions, and Roles\n\n";
$adminGuide .= "- Authentication and account state are managed through `metis_auth_users` plus the People role and permission tables.\n";
$adminGuide .= "- Module manifests declare role mappings for `view`, `edit`, `create`, and `delete` actions.\n";
$adminGuide .= "\n## Backups and Maintenance\n\n";
$adminGuide .= "- Backup execution is handled by the backup service and settings screens.\n";
$adminGuide .= "- Scheduler controls, integrity baselines, and release operations are centralized in Settings.\n";
$adminGuide .= "\n## System Settings\n\n";
$adminGuide .= "- Branding, workspace credentials, API keys, accessibility, menu order, and help-system controls all persist through `Core_Settings_Service`.\n";
$adminGuide .= "- Help mode and walkthroughs can be enabled or disabled from the Settings > Help section.\n";
metis_docs_write( $docsRoot . '/admin-guide/administration.md', $adminGuide );

$modulesIndex = "# Modules\n\n";
$modulesIndex .= "Each module document is generated from its manifest, templates, AJAX controllers, and table usage.\n\n";
foreach ( $manifests as $slug => $manifest ) {
    $label = (string) ( $manifest['label'] ?? metis_docs_slug_title( $slug ) );
    $modulesIndex .= "- [" . $label . "](./" . $slug . ".md)\n";
}
metis_docs_write( $docsRoot . '/modules/README.md', $modulesIndex );

foreach ( $manifests as $slug => $manifest ) {
    $moduleDir = $modulesRoot . '/' . $slug;
    $views = (array) ( $manifest['views'] ?? [] );
    $ajaxFile = (string) ( $manifest['assets']['ajax'] ?? '' );
    $ajaxPath = $ajaxFile !== '' ? $moduleDir . '/assets/' . $ajaxFile : '';
    $srcPaths = metis_docs_find_module_source( $srcModulesRoot, $slug );
    $sourcePaths = array_merge( $srcPaths, [ $ajaxPath ] );
    foreach ( $views as $template ) {
        $sourcePaths[] = $moduleDir . '/templates/' . $template;
    }
    $actions = metis_docs_actions_from_file( $ajaxPath );
    $tables = metis_docs_tables_used( $sourcePaths );
    $doc = "# " . ( $manifest['label'] ?? metis_docs_slug_title( $slug ) ) . "\n\n";
    $doc .= (string) ( $manifest['description'] ?? 'Module documentation.' ) . "\n\n";
    $doc .= "## Routes\n\n";
    $doc .= "- Base route: `/" . $slug . "`\n";
    foreach ( $views as $view => $template ) {
        $doc .= "- `/" . $slug . "/" . $view . "` -> `" . $template . "`\n";
    }
    $doc .= "\n## UI Components\n\n";
    foreach ( $views as $view => $template ) {
        $doc .= "- **" . metis_docs_slug_title( (string) $view ) . "** template: `" . $template . "`\n";
    }
    $doc .= "\n## APIs\n\n";
    if ( $actions === [] ) {
        $doc .= "- No dedicated AJAX controller was discovered for this module.\n";
    } else {
        foreach ( $actions as $action ) {
            $doc .= "- `" . $action . "`\n";
        }
    }
    $doc .= "\n## Database Tables Used\n\n";
    if ( $tables === [] ) {
        $doc .= "- No table references were discovered.\n";
    } else {
        foreach ( $tables as $tableKey ) {
            $doc .= "- `" . $tableKey . "` (`" . ( $tableMap[ $tableKey ] ?? $tableKey ) . "`)\n";
        }
    }
    $doc .= "\n## Assets and Extension Hooks\n\n";
    $doc .= "- CSS: `" . implode( '`, `', array_map( 'strval', (array) ( $manifest['assets']['css'] ?? [] ) ) ) . "`\n";
    $doc .= "- JS: `" . implode( '`, `', array_map( 'strval', (array) ( $manifest['assets']['js'] ?? [] ) ) ) . "`\n";
    $helpTopics = array_values( array_map( 'strval', (array) ( $manifest['help_topics'] ?? [] ) ) );
    if ( $helpTopics !== [] ) {
        $doc .= "- Registered help topics: `" . implode( '`, `', $helpTopics ) . "`\n";
    }
    metis_docs_write( $docsRoot . '/modules/' . $slug . '.md', $doc );
}

$databaseDoc = "# Database Schema\n\n";
$databaseDoc .= "This section is generated from `includes/core/db.php`, module schema managers, and table registry definitions.\n\n";
foreach ( $schema as $tableKey => $definition ) {
    $databaseDoc .= "## `" . $tableKey . "` (`" . $definition['name'] . "`)\n\n";
    $databaseDoc .= "### Fields\n\n";
    foreach ( $definition['fields'] as $field ) {
        $databaseDoc .= "- `" . $field['name'] . "`: `" . $field['definition'] . "`\n";
    }
    $databaseDoc .= "\n### Indexes\n\n";
    if ( $definition['indexes'] === [] ) {
        $databaseDoc .= "- No explicit secondary indexes discovered.\n";
    } else {
        foreach ( $definition['indexes'] as $index ) {
            $databaseDoc .= "- `" . $index . "`\n";
        }
    }
    $databaseDoc .= "\n### Data Lifecycle\n\n";
    $databaseDoc .= "- Created and updated through module services, AJAX handlers, scheduled jobs, or sync services that reference this table.\n";
    $databaseDoc .= "- Indexes should be preserved when adding filters, joins, or pagination flows against this table.\n\n";
}
metis_docs_write( $docsRoot . '/database/schema.md', $databaseDoc );

$apiDoc = "# API and AJAX Endpoints\n\n";
$apiDoc .= "Metis routes most interactive behavior through the shared AJAX endpoint exposed by `includes/core/ajax.php` and routed by `includes/core/router.php`.\n\n";
foreach ( $manifests as $slug => $manifest ) {
    $ajaxFile = (string) ( $manifest['assets']['ajax'] ?? '' );
    $moduleDir = $modulesRoot . '/' . $slug;
    $actions = metis_docs_actions_from_file( $ajaxFile !== '' ? $moduleDir . '/assets/' . $ajaxFile : '' );
    if ( $actions === [] ) {
        continue;
    }
    $apiDoc .= "## " . ( $manifest['label'] ?? metis_docs_slug_title( $slug ) ) . "\n\n";
    foreach ( $actions as $action ) {
        $apiDoc .= "- `POST /api/ajax` with `action=" . $action . "`\n";
    }
    $apiDoc .= "\n";
}
metis_docs_write( $docsRoot . '/api/endpoints.md', $apiDoc );

$developer = "# Developer Guide\n\n";
$developer .= "## Module Creation\n\n";
$developer .= "- Create a manifest in `includes/modules/<module>/<module>.json`.\n";
$developer .= "- Define views, assets, permissions, help topics, and optional services.\n";
$developer .= "- Keep module logic behind the existing router, service, and UI layers instead of bypassing them.\n";
$developer .= "\n## Router Usage\n\n";
$developer .= "- Portal routes are derived from domain and view query vars.\n";
$developer .= "- AJAX routes go through `/api/ajax` and should register secure controller definitions when possible.\n";
$developer .= "\n## Service Architecture\n\n";
$developer .= "- Register shared services through `includes/core/service_registry.php`.\n";
$developer .= "- Reuse `Core_Settings_Service`, `Metis_Tables`, and existing module services before adding new abstractions.\n";
$developer .= "\n## UI Services\n\n";
$developer .= "- Extend the shared `Metis` JS namespace instead of shipping isolated frameworks.\n";
$developer .= "- Use `data-help` attributes and manifest `help_topics` to connect UI elements to help content.\n";
$developer .= "\n## Coding Standards\n\n";
$developer .= "- Preserve the layered request path: router -> core services -> UI services -> modules.\n";
$developer .= "- Prefer aggregated queries, indexed filters, lazy-loaded help metadata, and cached references on performance-sensitive paths.\n";
metis_docs_write( $docsRoot . '/developer/developer-guide.md', $developer );

$helpDev = "# Help System\n\n";
$helpDev .= "## Register Help Topics\n\n";
$helpDev .= "- Add `help_topics` to a module manifest to explicitly register topic ids.\n";
$helpDev .= "- If a module omits `help_topics`, the help service falls back to `<module>.<view>` ids derived from manifest views.\n";
$helpDev .= "\n## Create Walkthroughs\n\n";
$helpDev .= "- Add walkthrough definitions to `docs/walkthroughs.json` or `help_custom_walkthroughs` in Settings.\n";
$helpDev .= "- Each step should provide a CSS selector in `target`, a human-readable `message`, and an optional `advance` mode.\n";
$helpDev .= "\n## Tag UI Elements\n\n";
$helpDev .= "- Add `data-help=\"topic.id\"` to buttons, links, tabs, and panels that need direct contextual help.\n";
$helpDev .= "- Elements without an explicit tag inherit the current page topic through the help UI fallback layer.\n";
$helpDev .= "\n## Extend Help Content\n\n";
$helpDev .= "- Administrators can override descriptions, add custom topics, and add custom walkthroughs from Settings > Help.\n";
$helpDev .= "- Extensions should reuse the shared help and walkthrough services rather than shipping separate tooltip or tour systems.\n";
metis_docs_write( $docsRoot . '/developer/help-system.md', $helpDev );

$security = "# Security Model\n\n";
$security .= "- Routing middleware enforces request normalization, AJAX contract checks, and route security.\n";
$security .= "- The help system uses the same authenticated AJAX pipeline as the rest of the portal.\n";
$security .= "- Help content should never expose secrets; keep API keys and credential instructions in protected admin-only docs or settings screens.\n";
metis_docs_write( $docsRoot . '/security/security-model.md', $security );

$operations = "# Operations\n\n";
$operations .= "- Configure backups, scheduler tasks, integrity baselines, and release checks from Settings.\n";
$operations .= "- Regenerate repository documentation after significant schema or manifest changes by running `php tools/generate_docs.php`.\n";
$operations .= "- Review `docs/database/schema.md` after schema changes to confirm indexes and lifecycle notes remain accurate.\n";
metis_docs_write( $docsRoot . '/operations/operations.md', $operations );

metis_docs_json( $docsRoot . '/help-index.json', metis_docs_help_index( $manifests ) );
metis_docs_json( $docsRoot . '/walkthroughs.json', metis_docs_walkthroughs() );

echo "Documentation generated in {$docsRoot}\n";
