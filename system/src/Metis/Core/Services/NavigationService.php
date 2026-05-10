<?php
declare(strict_types=1);

namespace Metis\Core\Services;

use Metis\Services\DatabaseService;

final class NavigationService {
    private const SYSTEM_CATEGORIES = [ 'core', 'communications', 'website', 'administration', 'other' ];

    private DatabaseService $db;
    private bool $schemaReady = false;
    private bool $seedReady = false;
    private bool $iconBackfillReady = false;
    private ?array $moduleIndex = null;
    private ?array $visibleTreeCache = null;
    private array $syncedModules = [];
    private array $permissionAllowedCache = [];

    public function __construct( DatabaseService $db ) {
        $this->db = $db;
    }

    public function ensureSchema(): void {
        if ( $this->schemaReady ) {
            return;
        }

        if ( ! $this->persistenceAvailable() ) {
            return;
        }

        $table = \Metis_Tables::get( 'navigation_items' );
        $charset = $this->charsetCollate();

        $ensure = function () use ( $table, $charset ): void {
            \metis_db_delta(
                "CREATE TABLE {$table} (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    label VARCHAR(191) NOT NULL,
                    route VARCHAR(255) NOT NULL DEFAULT '',
                    icon TEXT NULL,
                    parent_id BIGINT UNSIGNED NULL,
                    position INT NOT NULL DEFAULT 0,
                    is_visible TINYINT(1) NOT NULL DEFAULT 1,
                    permissions_required VARCHAR(191) NOT NULL DEFAULT '',
                    module_key VARCHAR(191) NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY uniq_module_key (module_key),
                    KEY idx_parent_position (parent_id, position),
                    KEY idx_visible (is_visible),
                    KEY idx_module_key (module_key)
                ) {$charset};"
            );
        };

        if ( \function_exists( 'metis_runtime_run_once_per_signature' ) ) {
            \metis_runtime_run_once_per_signature(
                'navigation_schema',
                [ __FILE__ ],
                $ensure
            );
        } else {
            $ensure();
        }

        $this->schemaReady = true;
    }

    public function seedDefaults(): void {
        if ( ! $this->persistenceAvailable() ) {
            return;
        }

        $this->ensureSchema();
        if ( ! $this->schemaReady ) {
            return;
        }

        if ( $this->seedReady ) {
            return;
        }

        $seedVersion = \defined( 'METIS_VERSION' ) ? (string) \METIS_VERSION : 'unknown';
        if (
            \class_exists( 'Core_Settings_Service' )
            && (string) \Core_Settings_Service::get( 'navigation_defaults_seeded_version', '' ) === $seedVersion
        ) {
            $this->seedReady = true;
            return;
        }

        $table = \Metis_Tables::get( 'navigation_items' );

        $groups = [
            [ 'key' => 'group:communications', 'label' => 'Communications', 'position' => 50, 'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18v12H3z"/><path d="m3 7 9 6 9-6"/></svg>' ],
            [ 'key' => 'group:website', 'label' => 'Website', 'position' => 60, 'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3a14 14 0 0 1 0 18"/><path d="M12 3a14 14 0 0 0 0 18"/></svg>' ],
            [ 'key' => 'group:administration', 'label' => 'Administration', 'position' => 110, 'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 0 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3h.1a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5h.1a1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 0 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8v.1a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/></svg>' ],
        ];

        foreach ( $groups as $group ) {
            $existingGroup = $this->db->fetchOne(
                "SELECT id FROM {$table} WHERE module_key = %s LIMIT 1",
                [ (string) $group['key'] ]
            );

            if ( is_array( $existingGroup ) && ! empty( $existingGroup['id'] ) ) {
                $this->db->update(
                    $table,
                    [
                        'label' => (string) $group['label'],
                        'icon' => (string) ( $group['icon'] ?? '' ),
                        'position' => (int) $group['position'],
                        'updated_at' => \metis_current_time( 'mysql' ),
                    ],
                    [ 'id' => (int) $existingGroup['id'] ],
                    [ '%s', '%s', '%d', '%s' ],
                    [ '%d' ]
                );
            } else {
                $this->db->insert(
                    $table,
                    [
                        'label' => (string) $group['label'],
                        'route' => '',
                        'icon' => (string) ( $group['icon'] ?? '' ),
                        'parent_id' => null,
                        'position' => (int) $group['position'],
                        'is_visible' => 1,
                        'permissions_required' => '',
                        'module_key' => (string) $group['key'],
                        'created_at' => \metis_current_time( 'mysql' ),
                        'updated_at' => \metis_current_time( 'mysql' ),
                    ],
                    [ '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s' ]
                );
            }
        }

        $this->ensureLogoutEntry();
        $this->backfillLegacyIcons();

        $this->backfillParentAssignments();
        $this->backfillDefaultPositions();

        $this->seedReady = true;
        if ( \class_exists( 'Core_Settings_Service' ) ) {
            \Core_Settings_Service::set( 'navigation_defaults_seeded_version', $seedVersion, false );
        }
    }

    public function ensureModuleEntry( string $slug, array $config ): void {
        $slug = \metis_key_clean( $slug );
        if ( $slug === '' || $slug === 'profile' || ! $this->persistenceAvailable() ) {
            return;
        }

        $this->seedDefaults();
        if ( ! $this->seedReady ) {
            return;
        }

        $table = \Metis_Tables::get( 'navigation_items' );

        $exists = (int) $this->db->scalar(
            "SELECT COUNT(*) FROM {$table} WHERE module_key = %s",
            [ $slug ]
        );

        if ( $exists > 0 ) {
            return;
        }

        $navigation = \is_array( $config['navigation'] ?? null ) ? $config['navigation'] : [];
        $enabled = \array_key_exists( 'enabled', $navigation ) ? (bool) $navigation['enabled'] : true;
        if ( ! $enabled ) {
            return;
        }

        $label = trim( (string) ( $navigation['label'] ?? $config['label'] ?? $config['name'] ?? $slug ) );
        if ( $label === '' ) {
            $label = $slug;
        }

        $label = $this->titleCase( $label );
        $isVisible = \array_key_exists( 'visible', $navigation ) ? (bool) $navigation['visible'] : true;
        $icon = trim( (string) ( $config['icon'] ?? '' ) );
        $position = (int) ( $config['order'] ?? $config['menu_order'] ?? $this->defaultPositionForModule( $slug ) );
        $defaultParent = $this->normalizeDefaultParent( $config['default_parent'] ?? null );
        $parentId = $this->resolveDefaultParentId( $defaultParent );

        $this->db->insert(
            $table,
            [
                'label' => $label,
                'route' => $this->moduleRoute( $slug ),
                'icon' => $icon,
                'parent_id' => $parentId,
                'position' => $position,
                'is_visible' => $isVisible ? 1 : 0,
                'permissions_required' => $this->modulePermissionKey( $slug, $config ),
                'module_key' => $slug,
                'created_at' => \metis_current_time( 'mysql' ),
                'updated_at' => \metis_current_time( 'mysql' ),
            ],
            [ '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s' ]
        );
        $this->visibleTreeCache = null;
    }

    public function visibleTree(): array {
        if ( \class_exists( 'Profiler', false ) ) {
            \Profiler::mark( 'ROUTER_NAV_SERVICE' );
        }

        if ( $this->visibleTreeCache !== null ) {
            if ( \class_exists( 'Profiler', false ) ) {
                \Profiler::mark( 'ROUTER_NAV_SERVICE_DONE' );
            }
            return $this->visibleTreeCache;
        }

        if ( ! $this->persistenceAvailable() ) {
            if ( \class_exists( 'Profiler', false ) ) {
                \Profiler::mark( 'ROUTER_NAV_SERVICE_DONE' );
            }
            $this->visibleTreeCache = [];
            return [];
        }

        if ( \class_exists( 'Profiler', false ) ) {
            \Profiler::mark( 'ROUTER_NAV_SEED' );
        }
        $this->seedDefaults();
        if ( \class_exists( 'Profiler', false ) ) {
            \Profiler::mark( 'ROUTER_NAV_SEED_DONE' );
        }

        if ( \class_exists( 'Profiler', false ) ) {
            \Profiler::mark( 'ROUTER_NAV_SYNC' );
        }
        $this->syncRegisteredModules();
        if ( \class_exists( 'Profiler', false ) ) {
            \Profiler::mark( 'ROUTER_NAV_SYNC_DONE' );
        }

        $table = \Metis_Tables::get( 'navigation_items' );
        if ( \class_exists( 'Profiler', false ) ) {
            \Profiler::mark( 'ROUTER_NAV_QUERY' );
        }
        $rows = $this->db->fetchAll(
            "SELECT id, label, route, icon, parent_id, position, is_visible, permissions_required, module_key
             FROM {$table}
             WHERE is_visible = 1
             ORDER BY parent_id ASC, position ASC, id ASC"
        );
        if ( \class_exists( 'Profiler', false ) ) {
            \Profiler::mark( 'ROUTER_NAV_QUERY_DONE' );
        }

        if ( \class_exists( 'Profiler', false ) ) {
            \Profiler::mark( 'ROUTER_NAV_TREE' );
        }
        $allowed = [];
        foreach ( $rows as $row ) {
            $permission = trim( (string) ( $row['permissions_required'] ?? '' ) );
            if ( $permission !== '' && ! $this->isPermissionAllowed( $permission ) ) {
                continue;
            }

            $row['id'] = (int) ( $row['id'] ?? 0 );
            $row['parent_id'] = isset( $row['parent_id'] ) ? (int) $row['parent_id'] : 0;
            $row['position'] = (int) ( $row['position'] ?? 0 );
            $row['route'] = (string) ( $row['route'] ?? '' );
            $row['label'] = $this->titleCase( (string) ( $row['label'] ?? '' ) );
            $row['icon'] = (string) ( $row['icon'] ?? '' );
            $row['module_key'] = (string) ( $row['module_key'] ?? '' );
            if ( ! $this->isRenderableModuleKey( $row['module_key'] ) ) {
                continue;
            }
            $row['icon'] = $this->normalizeIconValue( $row['icon'] );
            $row['children'] = [];
            $allowed[ $row['id'] ] = $row;
        }

        $tree = [];
        foreach ( $allowed as $id => $row ) {
            $parentId = (int) ( $row['parent_id'] ?? 0 );
            if ( $parentId > 0 && isset( $allowed[ $parentId ] ) ) {
                $allowed[ $parentId ]['children'][] = $id;
                continue;
            }
            $tree[] = $id;
        }

        $result = [];
        foreach ( $tree as $id ) {
            if ( ! isset( $allowed[ $id ] ) ) {
                continue;
            }

            $item = $allowed[ $id ];
            $childNodes = [];
            foreach ( $item['children'] as $childId ) {
                if ( isset( $allowed[ $childId ] ) ) {
                    $childNodes[] = $allowed[ $childId ];
                }
            }
            usort( $childNodes, static fn ( array $a, array $b ): int => [ $a['position'], $a['id'] ] <=> [ $b['position'], $b['id'] ] );
            $item['children'] = $childNodes;
            if ( trim( (string) ( $item['route'] ?? '' ) ) === '' && $item['children'] === [] ) {
                continue;
            }
            $result[] = $item;
        }

        usort( $result, static fn ( array $a, array $b ): int => [ $a['position'], $a['id'] ] <=> [ $b['position'], $b['id'] ] );
        if ( \class_exists( 'Profiler', false ) ) {
            \Profiler::mark( 'ROUTER_NAV_TREE_DONE' );
            \Profiler::mark( 'ROUTER_NAV_SERVICE_DONE' );
        }
        $this->visibleTreeCache = $result;
        return $result;
    }

    public function editorState( array $modules ): array {
        if ( ! $this->persistenceAvailable() ) {
            return [
                'items' => [],
                'unassigned' => $this->buildUnassignedModules( $modules, [] ),
            ];
        }

        $this->seedDefaults();
        $this->syncRegisteredModules( $modules, true );

        $table = \Metis_Tables::get( 'navigation_items' );
        $rows = $this->db->fetchAll(
            "SELECT id, label, route, icon, parent_id, position, is_visible, permissions_required, module_key
             FROM {$table}
             ORDER BY parent_id ASC, position ASC, id ASC"
        );

        $byId = [];
        foreach ( $rows as $row ) {
            $row['id'] = (int) ( $row['id'] ?? 0 );
            $row['parent_id'] = isset( $row['parent_id'] ) ? (int) $row['parent_id'] : 0;
            $row['position'] = (int) ( $row['position'] ?? 0 );
            $row['is_visible'] = ! empty( $row['is_visible'] ) ? 1 : 0;
            $row['label'] = $this->titleCase( (string) ( $row['label'] ?? '' ) );
            $row['icon'] = (string) ( $row['icon'] ?? '' );
            $row['route'] = (string) ( $row['route'] ?? '' );
            $row['module_key'] = (string) ( $row['module_key'] ?? '' );
            if ( $row['module_key'] === 'system:logout' ) {
                continue;
            }
            $row['icon'] = $this->normalizeIconValue( $row['icon'] );
            $row['children'] = [];
            $byId[ $row['id'] ] = $row;
        }

        $roots = [];
        foreach ( $byId as $id => $row ) {
            $parentId = (int) ( $row['parent_id'] ?? 0 );
            if ( $parentId > 0 && isset( $byId[ $parentId ] ) ) {
                $byId[ $parentId ]['children'][] = $id;
            } else {
                $roots[] = $id;
            }
        }

        usort( $roots, static fn ( int $a, int $b ) => [ $byId[ $a ]['position'], $a ] <=> [ $byId[ $b ]['position'], $b ] );

        $items = [];
        foreach ( $roots as $rootId ) {
            $node = $byId[ $rootId ];
            $children = [];
            $childIds = $node['children'];
            usort( $childIds, static fn ( int $a, int $b ) => [ $byId[ $a ]['position'], $a ] <=> [ $byId[ $b ]['position'], $b ] );
            foreach ( $childIds as $childId ) {
                $children[] = $byId[ $childId ];
            }
            $node['children'] = $children;
            $items[] = $node;
        }

        return [
            'items' => $items,
            'unassigned' => $this->buildUnassignedModules( $modules, $rows ),
        ];
    }

    public function saveStructure( array $structure ): array {
        $this->seedDefaults();

        $table = \Metis_Tables::get( 'navigation_items' );
        $normalized = [];

        foreach ( $structure as $rowIndex => $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }

            $item = $this->normalizeEntry( $entry, null, (int) $rowIndex );
            if ( $item === null ) {
                continue;
            }

            $parentToken = -1 * ( (int) $rowIndex + 1 );
            $item['_self_token'] = $parentToken;
            $normalized[] = $item;
            foreach ( (array) ( $entry['children'] ?? [] ) as $childIndex => $childEntry ) {
                if ( ! is_array( $childEntry ) ) {
                    continue;
                }

                $child = $this->normalizeEntry( $childEntry, $parentToken, (int) $childIndex );
                if ( $child === null ) {
                    continue;
                }
                $normalized[] = $child;
            }
        }

        $updated = 0;
        $tokenParentMap = [];
        foreach ( $normalized as $item ) {
            $moduleKeyForLock = $this->normalizeMenuModuleKey( $item['module_key'] ?? '' );
            if ( $moduleKeyForLock === 'hermes' ) {
                $existingHermes = $this->db->fetchOne(
                    "SELECT id, label, icon, route, is_visible, parent_id, position, permissions_required
                     FROM {$table}
                     WHERE module_key = %s
                     LIMIT 1",
                    [ 'hermes' ]
                );
                if ( is_array( $existingHermes ) && ! empty( $existingHermes['id'] ) ) {
                    $item['id'] = (int) $existingHermes['id'];
                    $item['label'] = (string) ( $existingHermes['label'] ?? $item['label'] );
                $item['icon'] = (string) ( $existingHermes['icon'] ?? $item['icon'] );
                    $item['route'] = (string) ( $existingHermes['route'] ?? $item['route'] );
                    $item['is_visible'] = ! empty( $existingHermes['is_visible'] ) ? 1 : 0;
                    $item['permissions_required'] = (string) ( $existingHermes['permissions_required'] ?? $item['permissions_required'] );
                }
            }

            $resolvedParentId = $item['parent_id'];
            if ( is_int( $resolvedParentId ) && $resolvedParentId < 0 ) {
                $resolvedParentId = $tokenParentMap[ $resolvedParentId ] ?? null;
            }

            $payload = [
                'label' => (string) $item['label'],
                'icon' => $this->normalizeIconValue( (string) $item['icon'] ),
                'route' => (string) $item['route'],
                'is_visible' => (int) $item['is_visible'],
                'parent_id' => $resolvedParentId,
                'position' => (int) $item['position'],
                'permissions_required' => (string) $item['permissions_required'],
                'updated_at' => \metis_current_time( 'mysql' ),
            ];

            $finalId = 0;
            if ( (int) $item['id'] > 0 ) {
                $result = $this->db->update(
                    $table,
                    $payload,
                    [ 'id' => (int) $item['id'] ],
                    [ '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s' ],
                    [ '%d' ]
                );
                $finalId = (int) $item['id'];
            } else {
                $moduleKey = $this->normalizeMenuModuleKey( $item['module_key'] ?? '' );
                if ( $moduleKey === '' ) {
                    continue;
                }

                $existing = $this->db->fetchOne( "SELECT id FROM {$table} WHERE module_key = %s LIMIT 1", [ $moduleKey ] );
                if ( is_array( $existing ) && ! empty( $existing['id'] ) ) {
                    $result = $this->db->update(
                        $table,
                        $payload,
                        [ 'id' => (int) $existing['id'] ],
                        [ '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s' ],
                        [ '%d' ]
                    );
                    $finalId = (int) $existing['id'];
                } else {
                    $result = $this->db->insert(
                        $table,
                        array_merge(
                            $payload,
                            [
                                'module_key' => $moduleKey,
                                'created_at' => \metis_current_time( 'mysql' ),
                            ]
                        ),
                        [ '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s' ]
                    );
                    $finalId = $result === false ? 0 : (int) $this->db->lastInsertId();
                }
            }

            if ( $result !== false ) {
                $updated++;
            }

            $selfToken = isset( $item['_self_token'] ) ? (int) $item['_self_token'] : 0;
            if ( $selfToken < 0 && $finalId > 0 ) {
                $tokenParentMap[ $selfToken ] = $finalId;
            }
        }

        $this->visibleTreeCache = null;
        return [ 'updated' => $updated ];
    }

    public function addUnassignedModule( array $moduleItem, int $position = 999 ): ?int {
        $this->seedDefaults();

        $moduleKey = \metis_key_clean( (string) ( $moduleItem['module_key'] ?? '' ) );
        if ( $moduleKey === '' ) {
            return null;
        }

        $table = \Metis_Tables::get( 'navigation_items' );
        $exists = $this->db->fetchOne( "SELECT id FROM {$table} WHERE module_key = %s LIMIT 1", [ $moduleKey ] );
        if ( is_array( $exists ) && ! empty( $exists['id'] ) ) {
            return (int) $exists['id'];
        }

        $this->db->insert(
            $table,
            [
                'label' => $this->titleCase( (string) ( $moduleItem['label'] ?? $moduleKey ) ),
                'route' => (string) ( $moduleItem['route'] ?? $this->moduleRoute( $moduleKey ) ),
                'icon' => (string) ( $moduleItem['icon'] ?? '' ),
                'parent_id' => null,
                'position' => $position,
                'is_visible' => 1,
                'permissions_required' => (string) ( $moduleItem['permissions_required'] ?? '' ),
                'module_key' => $moduleKey,
                'created_at' => \metis_current_time( 'mysql' ),
                'updated_at' => \metis_current_time( 'mysql' ),
            ],
            [ '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s' ]
        );

        $this->visibleTreeCache = null;
        return (int) $this->db->lastInsertId();
    }

    private function normalizeEntry( array $entry, ?int $parentId, int $position ): ?array {
        $id = isset( $entry['id'] ) ? (int) $entry['id'] : 0;
        $moduleKey = $this->normalizeMenuModuleKey( $entry['module_key'] ?? '' );
        if ( $moduleKey === 'system:logout' ) {
            return null;
        }

        $label = trim( (string) ( $entry['label'] ?? '' ) );
        if ( $label === '' ) {
            return null;
        }

        $permissions = trim( (string) ( $entry['permissions_required'] ?? '' ) );

        return [
            'id' => $id,
            'label' => $this->titleCase( $label ),
            'icon' => trim( (string) ( $entry['icon'] ?? '' ) ),
            'route' => trim( (string) ( $entry['route'] ?? '' ) ),
            'is_visible' => ! empty( $entry['is_visible'] ) ? 1 : 0,
            'parent_id' => $parentId,
            'position' => $position,
            'permissions_required' => $permissions,
            'module_key' => $moduleKey,
        ];
    }

    private function normalizeIconValue( string $icon ): string {
        $normalized = trim( $icon );
        if ( $normalized === '' ) {
            return '';
        }

        if ( \function_exists( 'metis_navigation_normalize_icon_value' ) ) {
            return (string) \metis_navigation_normalize_icon_value( $normalized );
        }

        return $normalized;
    }

    private function backfillLegacyIcons(): void {
        if ( $this->iconBackfillReady ) {
            return;
        }
        $this->iconBackfillReady = true;

        if ( ! \function_exists( 'metis_navigation_normalize_icon_value' ) ) {
            return;
        }

        $table = \Metis_Tables::get( 'navigation_items' );
        $rows = $this->db->fetchAll(
            "SELECT id, icon FROM {$table}
             WHERE icon IS NOT NULL
               AND TRIM(icon) <> ''"
        );

        foreach ( $rows as $row ) {
            $id = (int) ( $row['id'] ?? 0 );
            $icon = trim( (string) ( $row['icon'] ?? '' ) );
            if ( $id <= 0 || $icon === '' ) {
                continue;
            }

            $normalized = $this->normalizeIconValue( $icon );
            if ( $normalized === $icon ) {
                continue;
            }

            $this->db->update(
                $table,
                [
                    'icon' => $normalized,
                    'updated_at' => \metis_current_time( 'mysql' ),
                ],
                [ 'id' => $id ],
                [ '%s', '%s' ],
                [ '%d' ]
            );
        }
    }

    private function normalizeDefaultParent( mixed $value ): ?string {
        if ( $value === null ) {
            return null;
        }

        $parent = \metis_key_clean( (string) $value );
        if ( $parent === '' || $parent === 'null' ) {
            return null;
        }

        if ( ! in_array( $parent, [ 'communications', 'website', 'administration' ], true ) ) {
            return null;
        }

        return $parent;
    }

    private function normalizeMenuModuleKey( mixed $value ): string {
        $raw = strtolower( trim( (string) $value ) );
        if ( $raw === '' ) {
            return '';
        }

        if ( preg_match( '/^(group|system):([a-z0-9_-]+)$/', $raw, $matches ) === 1 ) {
            return (string) ( $matches[1] . ':' . $matches[2] );
        }

        return \metis_key_clean( $raw );
    }

    private function resolveDefaultParentId( ?string $parent ): ?int {
        if ( $parent === null ) {
            return null;
        }

        $table = \Metis_Tables::get( 'navigation_items' );
        $row = $this->db->fetchOne(
            "SELECT id FROM {$table} WHERE module_key = %s LIMIT 1",
            [ 'group:' . $parent ]
        );

        if ( ! is_array( $row ) || empty( $row['id'] ) ) {
            return null;
        }

        return (int) $row['id'];
    }

    private function moduleRoute( string $slug ): string {
        if ( \function_exists( 'metis_portal_url' ) ) {
            return (string) \metis_portal_url( $slug );
        }

        return '/' . trim( (string) $slug, '/' ) . '/';
    }

    private function logoutRoute(): string {
        if ( \function_exists( 'metis_auth_logout_url' ) ) {
            return (string) \metis_auth_logout_url();
        }

        return \metis_home_url( '/logout' );
    }

    private function logoutIcon(): string {
        return 'icon:logout';
    }

    private function ensureLogoutEntry(): void {
        $table = \Metis_Tables::get( 'navigation_items' );
        $route = $this->logoutRoute();
        $icon = $this->logoutIcon();
        $now = \metis_current_time( 'mysql' );

        $logoutRow = $this->db->fetchOne(
            "SELECT id FROM {$table} WHERE module_key = %s LIMIT 1",
            [ 'system:logout' ]
        );

        if ( is_array( $logoutRow ) && ! empty( $logoutRow['id'] ) ) {
            $this->db->update(
                $table,
                [
                    'label' => 'Log Out',
                    'route' => $route,
                    'icon' => $icon,
                    'parent_id' => null,
                    'position' => 999,
                    'is_visible' => 1,
                    'permissions_required' => '',
                    'updated_at' => $now,
                ],
                [ 'id' => (int) $logoutRow['id'] ],
                [ '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s' ],
                [ '%d' ]
            );
            return;
        }

        $legacyRow = $this->db->fetchOne(
            "SELECT id FROM {$table}
             WHERE module_key IS NULL
               AND LOWER(TRIM(label)) IN ('logout', 'log out')
             ORDER BY id ASC
             LIMIT 1"
        );

        if ( is_array( $legacyRow ) && ! empty( $legacyRow['id'] ) ) {
            $this->db->update(
                $table,
                [
                    'label' => 'Log Out',
                    'route' => $route,
                    'icon' => $icon,
                    'parent_id' => null,
                    'position' => 999,
                    'is_visible' => 1,
                    'permissions_required' => '',
                    'module_key' => 'system:logout',
                    'updated_at' => $now,
                ],
                [ 'id' => (int) $legacyRow['id'] ],
                [ '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s' ],
                [ '%d' ]
            );
            return;
        }

        $this->db->insert(
            $table,
            [
                'label' => 'Log Out',
                'route' => $route,
                'icon' => $icon,
                'parent_id' => null,
                'position' => 999,
                'is_visible' => 1,
                'permissions_required' => '',
                'module_key' => 'system:logout',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [ '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s' ]
        );
    }

    private function defaultPositionForModule( string $slug ): int {
        static $defaults = [
            'portal' => 10,
            'donations' => 20,
            'finance' => 30,
            'contacts' => 40,
            'people' => 45,
            'forms' => 10,
            'newsletter' => 20,
            'website' => 10,
            'media' => 20,
            'import' => 30,
            'drive' => 70,
            'calendar' => 80,
            'grandys_stash' => 90,
            'board' => 100,
            'settings' => 10,
            'hermes' => 20,
        ];

        return $defaults[ $slug ] ?? 500;
    }

    private function modulePermissionKey( string $slug, array $config ): string {
        $definitions = (array) ( $config['permission_definitions'] ?? [] );
        foreach ( $definitions as $definition ) {
            if ( ! is_array( $definition ) ) {
                continue;
            }
            $action = \metis_key_clean( (string) ( $definition['action'] ?? '' ) );
            if ( $action !== 'view' ) {
                continue;
            }

            $key = trim( (string) ( $definition['key'] ?? '' ) );
            if ( $key !== '' ) {
                return $key;
            }
        }

        return $slug . '.view';
    }

    private function backfillParentAssignments(): void {
        if ( ! \function_exists( 'metis_get_modules' ) ) {
            return;
        }

        $modules = \metis_get_modules();
        if ( $modules === [] ) {
            return;
        }

        $table = \Metis_Tables::get( 'navigation_items' );
        $rows = $this->db->fetchAll(
            "SELECT id, module_key, parent_id, created_at, updated_at
             FROM {$table}
             WHERE module_key IS NOT NULL AND module_key <> ''"
        );

        foreach ( $rows as $row ) {
            $moduleKey = \metis_key_clean( (string) ( $row['module_key'] ?? '' ) );
            if ( $moduleKey === '' || ! isset( $modules[ $moduleKey ] ) ) {
                continue;
            }

            $config = \is_array( $modules[ $moduleKey ]['config'] ?? null ) ? $modules[ $moduleKey ]['config'] : [];
            $defaultParent = $this->normalizeDefaultParent( $config['default_parent'] ?? null );
            if ( $defaultParent === null ) {
                continue;
            }

            $parentId = $this->resolveDefaultParentId( $defaultParent );
            if ( $parentId === null ) {
                continue;
            }

            $currentParentId = (int) ( $row['parent_id'] ?? 0 );
            if ( $currentParentId === $parentId ) {
                continue;
            }

            $this->db->update(
                $table,
                [
                    'parent_id' => $parentId,
                    'updated_at' => \metis_current_time( 'mysql' ),
                ],
                [ 'id' => (int) ( $row['id'] ?? 0 ) ],
                [ '%d', '%s' ],
                [ '%d' ]
            );
        }
    }

    private function backfillDefaultPositions(): void {
        if ( ! \function_exists( 'metis_get_modules' ) ) {
            return;
        }

        $modules = \metis_get_modules();
        if ( $modules === [] ) {
            return;
        }

        $table = \Metis_Tables::get( 'navigation_items' );
        $rows = $this->db->fetchAll(
            "SELECT id, module_key, position, created_at, updated_at
             FROM {$table}
             WHERE module_key IS NOT NULL
               AND module_key <> ''
               AND module_key NOT LIKE %s
               AND module_key NOT LIKE %s",
            [ 'group:%', 'system:%' ]
        );

        foreach ( $rows as $row ) {
            $moduleKey = \metis_key_clean( (string) ( $row['module_key'] ?? '' ) );
            if ( $moduleKey === '' || ! isset( $modules[ $moduleKey ] ) ) {
                continue;
            }

            // Preserve user-managed entries.
            if ( (string) ( $row['created_at'] ?? '' ) !== (string) ( $row['updated_at'] ?? '' ) ) {
                continue;
            }

            $config = \is_array( $modules[ $moduleKey ]['config'] ?? null ) ? $modules[ $moduleKey ]['config'] : [];
            $targetPosition = (int) ( $config['order'] ?? $config['menu_order'] ?? $this->defaultPositionForModule( $moduleKey ) );
            if ( $targetPosition <= 0 ) {
                $targetPosition = $this->defaultPositionForModule( $moduleKey );
            }

            if ( (int) ( $row['position'] ?? 0 ) === $targetPosition ) {
                continue;
            }

            $this->db->update(
                $table,
                [
                    'position' => $targetPosition,
                    'updated_at' => \metis_current_time( 'mysql' ),
                ],
                [ 'id' => (int) ( $row['id'] ?? 0 ) ],
                [ '%d', '%s' ],
                [ '%d' ]
            );
        }
    }

    private function isPermissionAllowed( string $permission ): bool {
        $permission = trim( $permission );
        if ( $permission === '' ) {
            return true;
        }

        if ( array_key_exists( $permission, $this->permissionAllowedCache ) ) {
            return $this->permissionAllowedCache[ $permission ];
        }

        if ( \function_exists( 'metis_security_user_can' ) ) {
            $allowed = \metis_security_user_can( $permission );
            $this->permissionAllowedCache[ $permission ] = $allowed;
            return $allowed;
        }

        $this->permissionAllowedCache[ $permission ] = true;
        return true;
    }

    private function titleCase( string $value ): string {
        $value = trim( preg_replace( '/\s+/', ' ', $value ) ?? '' );
        if ( $value === '' ) {
            return $value;
        }

        return ucwords( $value );
    }

    private function isRenderableModuleKey( string $moduleKey ): bool {
        $moduleKey = trim( $moduleKey );
        if ( $moduleKey === '' ) {
            return false;
        }

        if ( str_starts_with( $moduleKey, 'group:' ) || str_starts_with( $moduleKey, 'system:' ) ) {
            return true;
        }

        if ( $this->moduleIndex === null ) {
            $this->moduleIndex = [];
            if ( \function_exists( 'metis_get_modules' ) ) {
                $this->moduleIndex = array_fill_keys(
                    array_map(
                        static fn ( mixed $slug ): string => \metis_key_clean( (string) $slug ),
                        array_keys( (array) \metis_get_modules() )
                    ),
                    true
                );
            }
        }

        $slug = \metis_key_clean( $moduleKey );
        if ( $slug === '' ) {
            return false;
        }

        if ( $this->moduleIndex === [] ) {
            return true;
        }

        return isset( $this->moduleIndex[ $slug ] );
    }

    public static function normalizeCategory( mixed $category ): string {
        $category = \metis_key_clean( (string) $category );
        if ( ! in_array( $category, self::SYSTEM_CATEGORIES, true ) ) {
            return 'other';
        }

        return $category;
    }

    private function persistenceAvailable(): bool {
        if ( ! \class_exists( 'Metis_Tables' ) || ! \function_exists( 'metis_db_delta' ) ) {
            return false;
        }

        return $this->db->isAvailable();
    }

    private function charsetCollate(): string {
        try {
            if ( \method_exists( $this->db, 'get_charset_collate' ) ) {
                $charset = (string) $this->db->get_charset_collate();
                if ( $charset !== '' ) {
                    return $charset;
                }
            }
        } catch ( \Throwable ) {
        }

        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }

    private function syncRegisteredModules( array $modules = [], bool $force = false ): void {
        if ( ! $this->persistenceAvailable() ) {
            return;
        }

        $modules = $modules !== [] ? $modules : $this->registeredModules();
        $signature = $this->registeredModulesSignature( $modules );
        if (
            ! $force
            && $signature !== ''
            && \class_exists( 'Core_Settings_Service' )
            && (string) \Core_Settings_Service::get( 'navigation_modules_synced_signature', '' ) === $signature
        ) {
            foreach ( array_keys( $modules ) as $slug ) {
                $slug = \metis_key_clean( (string) $slug );
                if ( $slug !== '' ) {
                    $this->syncedModules[ $slug ] = true;
                }
            }
            return;
        }

        foreach ( $modules as $slug => $module ) {
            $slug = \metis_key_clean( (string) $slug );
            if ( $slug === '' || isset( $this->syncedModules[ $slug ] ) ) {
                continue;
            }

            $config = \is_array( $module['config'] ?? null ) ? $module['config'] : [];
            $this->ensureModuleEntry( $slug, $config );
            $this->syncedModules[ $slug ] = true;
        }

        if ( $signature !== '' && \class_exists( 'Core_Settings_Service' ) ) {
            \Core_Settings_Service::set( 'navigation_modules_synced_signature', $signature, false );
        }
    }

    private function registeredModulesSignature( array $modules ): string {
        if ( $modules === [] ) {
            return '';
        }

        $snapshot = [];
        foreach ( $modules as $slug => $module ) {
            $slug = \metis_key_clean( (string) $slug );
            if ( $slug === '' || ! \is_array( $module ) ) {
                continue;
            }

            $config = \is_array( $module['config'] ?? null ) ? $module['config'] : [];
            $snapshot[ $slug ] = [
                'label' => (string) ( $config['label'] ?? '' ),
                'name' => (string) ( $config['name'] ?? '' ),
                'icon' => (string) ( $config['icon'] ?? '' ),
                'order' => (int) ( $config['order'] ?? $config['menu_order'] ?? 0 ),
                'default_parent' => (string) ( $config['default_parent'] ?? '' ),
                'navigation' => \is_array( $config['navigation'] ?? null ) ? $config['navigation'] : [],
                'permissions' => \is_array( $config['permission_definitions'] ?? null ) ? $config['permission_definitions'] : [],
                'manifest_mtime' => (int) ( $config['_manifest_mtime'] ?? 0 ),
            ];
        }

        ksort( $snapshot );
        return hash( 'sha256', \json_encode( $snapshot, JSON_UNESCAPED_SLASHES ) ?: serialize( $snapshot ) );
    }

    private function registeredModules(): array {
        if ( ! \function_exists( 'metis_get_modules' ) ) {
            return [];
        }

        return (array) \metis_get_modules();
    }

    private function buildUnassignedModules( array $modules, array $rows ): array {
        $existing = [];
        foreach ( $rows as $row ) {
            $moduleKey = \metis_key_clean( (string) ( $row['module_key'] ?? '' ) );
            if ( $moduleKey !== '' ) {
                $existing[ $moduleKey ] = true;
            }
        }

        $unassigned = [];
        foreach ( $modules as $slug => $module ) {
            $slug = \metis_key_clean( (string) $slug );
            if ( $slug === '' || $slug === 'profile' || isset( $existing[ $slug ] ) ) {
                continue;
            }

            $cfg = \is_array( $module['config'] ?? null ) ? $module['config'] : [];
            $navigation = \is_array( $cfg['navigation'] ?? null ) ? $cfg['navigation'] : [];
            $enabled = \array_key_exists( 'enabled', $navigation ) ? (bool) $navigation['enabled'] : true;
            if ( ! $enabled ) {
                continue;
            }

            $unassigned[] = [
                'module_key' => $slug,
                'label' => $this->titleCase( (string) ( $navigation['label'] ?? $cfg['name'] ?? $cfg['label'] ?? $slug ) ),
                'icon' => (string) ( $cfg['icon'] ?? '' ),
                'route' => $this->moduleRoute( $slug ),
                'permissions_required' => $this->modulePermissionKey( $slug, $cfg ),
            ];
        }

        usort( $unassigned, static fn ( array $a, array $b ): int => strcmp( (string) $a['label'], (string) $b['label'] ) );
        return $unassigned;
    }
}
