<?php
declare(strict_types=1);

namespace Metis\Core\Services;

use Metis\Core\Application;

final class QuickActionsRegistryService {
    private array $actions = [];
    private bool $booted = false;

    public function bootstrap(): void {
        if ( $this->booted ) {
            return;
        }

        $this->booted = true;
        $this->registerSystemActions();

        $modules = Application::has_service( 'modules' ) ? (array) Application::service( 'modules' )->all() : [];
        foreach ( $modules as $slug => $module ) {
            $config = is_array( $module['config'] ?? null ) ? $module['config'] : [];
            $this->registerModuleActions( (string) $slug, $config );
        }
    }

    public function registerModuleActions( string $moduleSlug, array $config ): void {
        $moduleSlug = \metis_key_clean( $moduleSlug );
        if ( $moduleSlug === '' ) {
            return;
        }

        foreach ( (array) ( $config['quick_actions'] ?? [] ) as $action ) {
            if ( ! is_array( $action ) ) {
                continue;
            }

            $action['module'] = $moduleSlug;
            $this->register( $action );
        }
    }

    public function register( array $action ): bool {
        $normalized = $this->normalize( $action );
        if ( $normalized === null ) {
            return false;
        }

        $key = (string) $normalized['key'];
        if ( isset( $this->actions[ $key ] ) ) {
            return false;
        }

        if ( ! $this->isValidPermission( (string) $normalized['permission'] ) ) {
            return false;
        }

        $this->actions[ $key ] = $normalized;
        return true;
    }

    public function all(): array {
        $this->bootstrap();
        return array_values( $this->actions );
    }

    public function available(): array {
        $this->bootstrap();

        $available = [];
        foreach ( $this->actions as $action ) {
            $permission = (string) ( $action['permission'] ?? '' );
            if ( $permission !== '' && ! $this->userCan( $permission ) ) {
                continue;
            }

            $available[] = $action;
        }

        usort(
            $available,
            static fn ( array $a, array $b ): int => [ (string) ( $a['group'] ?? '' ), (string) ( $a['label'] ?? '' ) ] <=> [ (string) ( $b['group'] ?? '' ), (string) ( $b['label'] ?? '' ) ]
        );

        return $available;
    }

    public function availableAction( string $key ): ?array {
        $this->bootstrap();

        $key = \metis_key_clean( $key );
        if ( $key === '' || ! isset( $this->actions[ $key ] ) ) {
            return null;
        }

        $action = $this->actions[ $key ];
        $permission = (string) ( $action['permission'] ?? '' );
        if ( $permission !== '' && ! $this->userCan( $permission ) ) {
            return null;
        }

        return $action;
    }

    public function modalPayload( string $key ): ?array {
        $action = $this->availableAction( $key );
        if ( $action === null || (string) ( $action['type'] ?? '' ) !== 'modal' ) {
            return null;
        }

        $handler = (string) ( $action['handler'] ?? '' );
        if ( $handler === '' || ! is_callable( $handler ) ) {
            return null;
        }

        $payload = call_user_func( $handler, $action );
        if ( ! is_array( $payload ) ) {
            return null;
        }

        $html = trim( (string) ( $payload['html'] ?? '' ) );
        if ( $html === '' ) {
            return null;
        }

        $submitAction = \metis_key_clean( (string) ( $payload['submit_action'] ?? ( $action['submit_action'] ?? '' ) ) );
        $submitNonceAction = trim( (string) ( $payload['submit_nonce_action'] ?? $submitAction ) );
        $submitControllerNonceAction = $this->ajaxNonceAction( $submitAction );

        return [
            'key' => (string) $action['key'],
            'title' => trim( (string) ( $payload['title'] ?? $action['label'] ) ),
            'html' => $html,
            'submit_action' => $submitAction,
            'submit_nonce' => $submitNonceAction !== '' && function_exists( 'metis_runtime_create_nonce' )
                ? (string) \metis_runtime_create_nonce( $submitNonceAction )
                : '',
            'submit_action_nonce' => $submitControllerNonceAction !== '' && function_exists( 'metis_runtime_create_nonce' )
                ? (string) \metis_runtime_create_nonce( $submitControllerNonceAction )
                : '',
            'submit_label' => trim( (string) ( $payload['submit_label'] ?? ( $action['submit_label'] ?? 'Save' ) ) ),
            'success_message' => trim( (string) ( $payload['success_message'] ?? 'Action completed.' ) ),
            'redirect' => trim( (string) ( $payload['redirect'] ?? $action['route'] ?? '' ) ),
        ];
    }

    private function normalize( array $action ): ?array {
        $key = \metis_key_clean( (string) ( $action['key'] ?? '' ) );
        $label = trim( (string) ( $action['label'] ?? '' ) );
        $type = \metis_key_clean( (string) ( $action['type'] ?? 'route' ) );
        $route = trim( (string) ( $action['route'] ?? '' ) );
        $group = \metis_key_clean( (string) ( $action['group'] ?? 'other' ) );

        if ( $key === '' || $label === '' ) {
            return null;
        }

        if ( ! in_array( $type, [ 'modal', 'route' ], true ) ) {
            $type = 'route';
        }

        $permission = trim( (string) ( $action['permission'] ?? '' ) );
        $handler = trim( (string) ( $action['handler'] ?? '' ) );
        $submitAction = \metis_key_clean( (string) ( $action['submit_action'] ?? '' ) );
        $submitLabel = trim( (string) ( $action['submit_label'] ?? '' ) );
        $module = \metis_key_clean( (string) ( $action['module'] ?? '' ) );
        $route = $this->normalizeRoute( $route, $module );

        return [
            'key' => $key,
            'label' => $label,
            'icon' => (string) ( $action['icon'] ?? '' ),
            'type' => $type,
            'route' => $route,
            'handler' => $handler,
            'module' => $module,
            'permission' => $permission,
            'group' => $group !== '' ? $group : 'other',
            'submit_action' => $submitAction,
            'submit_label' => $submitLabel,
        ];
    }

    private function registerSystemActions(): void {
        $defaults = [
            [
                'key' => 'website_create_page',
                'label' => 'Create Page',
                'icon' => 'file-plus',
                'type' => 'route',
                'route' => '/admin/website/pages/editor/new/',
                'permission' => 'website.create',
                'group' => 'website',
                'module' => 'website',
            ],
            [
                'key' => 'website_create_post',
                'label' => 'Create Post',
                'icon' => 'square-pen',
                'type' => 'route',
                'route' => '/admin/website/posts/editor/new/',
                'permission' => 'website.create',
                'group' => 'website',
                'module' => 'website',
            ],
            [
                'key' => 'donations_record_offline_donation',
                'label' => 'Record Offline Donation',
                'icon' => 'hand-heart',
                'type' => 'modal',
                'route' => $this->portalRoute( 'donations', 'transactions' ),
                'handler' => 'metis_donations_quick_action_offline_donation_form',
                'submit_action' => 'metis_donations_record_offline_donation',
                'submit_label' => 'Record Donation',
                'permission' => 'donations.edit',
                'group' => 'donations',
                'module' => 'donations',
            ],
            [
                'key' => 'communications_create_newsletter',
                'label' => 'Create Newsletter',
                'icon' => 'mail-plus',
                'type' => 'route',
                'route' => '/admin/newsletter/campaigns/new/',
                'permission' => 'newsletter.create',
                'group' => 'communications',
                'module' => 'newsletter',
            ],
            [
                'key' => 'communications_create_form',
                'label' => 'Create Form',
                'icon' => 'square-pen',
                'type' => 'route',
                'route' => '/admin/forms/build/',
                'permission' => 'forms.create',
                'group' => 'communications',
                'module' => 'forms',
            ],
            [
                'key' => 'calendar_create_event',
                'label' => 'Create Event',
                'icon' => 'calendar-plus',
                'type' => 'modal',
                'route' => $this->portalRoute( 'calendar' ),
                'handler' => 'metis_calendar_quick_action_event_form',
                'submit_action' => 'metis_calendar_save_event',
                'submit_label' => 'Save Event',
                'permission' => 'calendar.create',
                'group' => 'calendar',
                'module' => 'calendar',
            ],
            [
                'key' => 'contacts_add_contact',
                'label' => 'Add Contact',
                'icon' => 'user-plus',
                'type' => 'modal',
                'route' => $this->portalRoute( 'contacts' ),
                'handler' => 'metis_contacts_quick_action_contact_form',
                'submit_action' => 'metis_contacts_save',
                'submit_label' => 'Save Contact',
                'permission' => 'contacts.create',
                'group' => 'contacts',
                'module' => 'contacts',
            ],
            [
                'key' => 'people_add_person',
                'label' => 'Add Person',
                'icon' => 'user-round-plus',
                'type' => 'modal',
                'route' => $this->portalRoute( 'people', 'people_list' ),
                'handler' => 'metis_people_quick_action_person_form',
                'submit_action' => 'metis_people_save_person',
                'submit_label' => 'Create Person',
                'permission' => 'people.create',
                'group' => 'people',
                'module' => 'people',
            ],
        ];

        foreach ( $defaults as $action ) {
            $this->register( $action );
        }
    }

    private function portalRoute( string $domain, string $view = '' ): string {
        if ( \function_exists( 'metis_portal_url' ) ) {
            return (string) \metis_portal_url( $domain, $view );
        }

        $base = '/' . trim( $domain, '/' ) . '/';
        if ( $view === '' ) {
            return $base;
        }

        return $base . trim( $view, '/' ) . '/';
    }

    private function normalizeRoute( string $route, string $module ): string {
        $route = trim( $route );
        if ( $route === '' ) {
            return $route;
        }

        $query = '';
        $fragment = '';
        if ( str_contains( $route, '?' ) || str_contains( $route, '#' ) ) {
            $parts = parse_url( $route );
            if ( is_array( $parts ) ) {
                $pathPart = (string) ( $parts['path'] ?? '' );
                $queryPart = (string) ( $parts['query'] ?? '' );
                $fragmentPart = (string) ( $parts['fragment'] ?? '' );
                if ( $queryPart !== '' ) {
                    $query = '?' . $queryPart;
                }
                if ( $fragmentPart !== '' ) {
                    $fragment = '#' . $fragmentPart;
                }
                if ( $pathPart !== '' ) {
                    $route = $pathPart;
                }
            }
        }

        if ( preg_match( '#^https?://#i', $route ) === 1 ) {
            return $route;
        }

        $path = $route;
        if ( preg_match( '#^/?admin/(.+)$#i', $path, $adminMatches ) === 1 ) {
            $path = (string) ( $adminMatches[1] ?? '' );
        }

        if ( \function_exists( 'metis_portal_slug' ) ) {
            $portalSlug = trim( (string) \metis_portal_slug(), '/' );
            if ( $portalSlug !== '' ) {
                $path = preg_replace( '#^/?' . preg_quote( $portalSlug, '#' ) . '/#', '', $path ) ?? $path;
            }
        }

        if ( str_starts_with( $path, '/' ) ) {
            $path = ltrim( $path, '/' );
        }

        $parts = array_values(
            array_filter(
                array_map(
                    static fn ( mixed $segment ): string => \metis_key_clean( (string) $segment ),
                    explode( '/', $path )
                ),
                static fn ( string $segment ): bool => $segment !== ''
            )
        );

        if ( $parts === [] ) {
            return $route;
        }

        $domain = $parts[0];
        $view = $parts[1] ?? '';
        $extra = array_slice( $parts, 2 );

        if ( $domain === '' && $module !== '' ) {
            $domain = $module;
        }

        if ( $domain !== '' ) {
            $route = $this->portalRoute( $domain, $view );
            if ( $extra !== [] ) {
                $route = rtrim( $route, '/' ) . '/' . implode( '/', $extra ) . '/';
            }
            return $route . $query . $fragment;
        }

        return $route . $query . $fragment;
    }

    private function isValidPermission( string $permission ): bool {
        $permission = trim( $permission );
        if ( $permission === '' ) {
            return true;
        }

        if ( str_contains( $permission, '.' ) ) {
            [ $module, $action ] = array_pad( explode( '.', $permission, 2 ), 2, 'view' );
            $module = \metis_key_clean( (string) $module );
            $action = \metis_key_clean( (string) $action );
            if ( $module === '' || $action === '' ) {
                return false;
            }

            $modules = Application::has_service( 'modules' ) ? (array) Application::service( 'modules' )->all() : [];
            if ( ! isset( $modules[ $module ] ) ) {
                return false;
            }

            $config = is_array( $modules[ $module ]['config'] ?? null ) ? $modules[ $module ]['config'] : [];
            $permissions = (array) ( $config['permissions'] ?? [] );
            if ( isset( $permissions[ $action ] ) ) {
                return true;
            }

            foreach ( (array) ( $config['permission_definitions'] ?? [] ) as $definition ) {
                if ( ! is_array( $definition ) ) {
                    continue;
                }

                if ( trim( (string) ( $definition['key'] ?? '' ) ) === $permission ) {
                    return true;
                }
            }

            return false;
        }

        return false;
    }

    private function ajaxNonceAction( string $submitAction ): string {
        $submitAction = \metis_key_clean( $submitAction );
        if ( $submitAction === '' ) {
            return '';
        }

        if ( \function_exists( 'metis_ajax_registry' ) ) {
            $controller = \metis_ajax_registry()->get( $submitAction );
            if ( is_array( $controller ) ) {
                $nonceAction = trim( (string) ( $controller['nonce_action'] ?? '' ) );
                if ( $nonceAction !== '' ) {
                    return $nonceAction;
                }
            }
        }

        return \function_exists( 'metis_ajax_nonce_action' )
            ? (string) \metis_ajax_nonce_action( $submitAction )
            : $submitAction;
    }

    private function userCan( string $permission ): bool {
        if ( $permission === '' ) {
            return true;
        }

        if ( \function_exists( 'metis_security_user_can' ) ) {
            return \metis_security_user_can( $permission );
        }

        return true;
    }
}
