<?php
/**
 * Grandy's Stash Legacy Export Endpoint
 *
 * Drop this into a small must-use plugin or a custom plugin on the old
 * WordPress site. Then set the same secret in Metis and point Metis to:
 *
 *   https://mobilizewaco.org/wp-json/metis/v1/grandys-stash-export
 *
 * Requirements:
 * - Gravity Forms active
 * - Nested Forms data already stored in the same WordPress database
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'METIS_GRANDYS_STASH_EXPORT_SECRET' ) ) {
    define( 'METIS_GRANDYS_STASH_EXPORT_SECRET', 'jha8794wrhjn98ansodifh92093ruf9' );
}

add_action( 'rest_api_init', static function (): void {
    register_rest_route(
        'metis/v1',
        '/grandys-stash-export',
        [
            'methods'             => 'POST',
            'callback'            => 'metis_grandys_stash_legacy_export_handler',
            'permission_callback' => 'metis_grandys_stash_legacy_export_authorized',
        ]
    );
} );

function metis_grandys_stash_legacy_export_authorized( WP_REST_Request $request ): bool {
    $auth = (string) $request->get_header( 'authorization' );
    if ( ! preg_match( '/Bearer\s+(.+)$/i', $auth, $matches ) ) {
        return false;
    }

    $provided = trim( (string) ( $matches[1] ?? '' ) );
    return $provided !== '' && hash_equals( (string) METIS_GRANDYS_STASH_EXPORT_SECRET, $provided );
}

function metis_grandys_stash_legacy_export_handler( WP_REST_Request $request ): WP_REST_Response {
    global $wpdb;

    $form_id = max( 1, (int) $request->get_param( 'form_id' ) );
    $limit = max( 1, min( 1000, (int) $request->get_param( 'limit' ) ) );

    $entry_table = $wpdb->prefix . 'gf_entry';
    $meta_table = $wpdb->prefix . 'gf_entry_meta';
    $form_meta_table = $wpdb->prefix . 'gf_form_meta';

    $parent_form_meta = metis_grandys_stash_legacy_form_meta( $form_meta_table, $form_id );
    if ( $parent_form_meta === [] ) {
        return new WP_REST_Response(
            [
                'ok'    => false,
                'error' => 'Unable to load Gravity Forms form metadata.',
            ],
            404
        );
    }

    $parent_map = metis_grandys_stash_legacy_parent_field_map( $parent_form_meta );
    $donation_nested_field_id = (int) ( $parent_map['donation_nested']['id'] ?? 0 );
    $request_nested_field_id  = (int) ( $parent_map['request_nested']['id'] ?? 0 );
    $donation_child_form_id   = (int) ( $parent_map['donation_nested']['gpnfForm'] ?? 0 );
    $request_child_form_id    = (int) ( $parent_map['request_nested']['gpnfForm'] ?? 0 );

    $parent_entries = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, form_id, date_created, date_updated, status
             FROM {$entry_table}
             WHERE form_id = %d
               AND status = %s
             ORDER BY id ASC
             LIMIT %d",
            $form_id,
            'active',
            $limit
        ),
        ARRAY_A
    );

    if ( ! is_array( $parent_entries ) || $parent_entries === [] ) {
        return new WP_REST_Response(
            [
                'ok'      => true,
                'form_id' => $form_id,
                'entries' => [],
            ],
            200
        );
    }

    $parent_ids = array_values(
        array_filter(
            array_map( static fn ( array $row ): int => (int) ( $row['id'] ?? 0 ), $parent_entries )
        )
    );

    $parent_meta_rows = [];
    if ( $parent_ids !== [] ) {
        $parent_meta_rows = $wpdb->get_results(
            "SELECT entry_id, meta_key, meta_value
             FROM {$meta_table}
             WHERE entry_id IN (" . implode( ', ', array_map( 'intval', $parent_ids ) ) . ')',
            ARRAY_A
        );
    }

    $parent_meta_index = [];
    foreach ( (array) $parent_meta_rows as $row ) {
        $entry_id = (int) ( $row['entry_id'] ?? 0 );
        $meta_key = trim( (string) ( $row['meta_key'] ?? '' ) );
        if ( $entry_id < 1 || $meta_key === '' ) {
            continue;
        }
        $parent_meta_index[ $entry_id ][ $meta_key ] = (string) ( $row['meta_value'] ?? '' );
    }

    $child_form_ids = array_values(
        array_filter(
            array_unique( [ $donation_child_form_id, $request_child_form_id ] ),
            static fn ( int $value ): bool => $value > 0
        )
    );

    $child_field_maps = [];
    foreach ( $child_form_ids as $child_form_id ) {
        $child_field_maps[ $child_form_id ] = metis_grandys_stash_legacy_child_field_map(
            metis_grandys_stash_legacy_form_meta( $form_meta_table, $child_form_id )
        );
    }

    $child_links_by_parent = [];
    $child_meta_index = [];
    if ( $child_form_ids !== [] && $parent_ids !== [] ) {
        $child_rows = $wpdb->get_results(
            "SELECT e.id,
                    e.form_id,
                    parent_meta.meta_value AS parent_entry_id,
                    nested_meta.meta_value AS nested_field_id
             FROM {$entry_table} e
             INNER JOIN {$meta_table} parent_meta
                ON parent_meta.entry_id = e.id
               AND parent_meta.meta_key = 'gpnf_entry_parent'
             INNER JOIN {$meta_table} nested_meta
                ON nested_meta.entry_id = e.id
               AND nested_meta.meta_key = 'gpnf_entry_nested_form_field'
             WHERE e.form_id IN (" . implode( ', ', array_map( 'intval', $child_form_ids ) ) . ")
               AND e.status = 'active'
               AND CAST(parent_meta.meta_value AS UNSIGNED) IN (" . implode( ', ', array_map( 'intval', $parent_ids ) ) . ')
             ORDER BY e.id ASC',
            ARRAY_A
        );

        $child_ids = [];
        foreach ( (array) $child_rows as $row ) {
            $parent_entry_id = (int) ( $row['parent_entry_id'] ?? 0 );
            $child_id = (int) ( $row['id'] ?? 0 );
            if ( $parent_entry_id < 1 || $child_id < 1 ) {
                continue;
            }
            $child_ids[] = $child_id;
            $child_links_by_parent[ $parent_entry_id ][] = [
                'id' => $child_id,
                'form_id' => (int) ( $row['form_id'] ?? 0 ),
                'nested_field_id' => (int) ( $row['nested_field_id'] ?? 0 ),
            ];
        }

        if ( $child_ids !== [] ) {
            $child_meta_rows = $wpdb->get_results(
                "SELECT entry_id, meta_key, meta_value
                 FROM {$meta_table}
                 WHERE entry_id IN (" . implode( ', ', array_map( 'intval', array_unique( $child_ids ) ) ) . ')',
                ARRAY_A
            );

            foreach ( (array) $child_meta_rows as $row ) {
                $entry_id = (int) ( $row['entry_id'] ?? 0 );
                $meta_key = trim( (string) ( $row['meta_key'] ?? '' ) );
                if ( $entry_id < 1 || $meta_key === '' ) {
                    continue;
                }
                $child_meta_index[ $entry_id ][ $meta_key ] = (string) ( $row['meta_value'] ?? '' );
            }
        }
    }

    foreach ( $parent_entries as $entry ) {
        $parent_entry_id = (int) ( $entry['id'] ?? 0 );
        if ( $parent_entry_id < 1 ) {
            continue;
        }

        $parent_meta = (array) ( $parent_meta_index[ $parent_entry_id ] ?? [] );
        foreach (
            [
                $donation_nested_field_id => $donation_child_form_id,
                $request_nested_field_id  => $request_child_form_id,
            ] as $nested_field_id => $child_form_id
        ) {
            if ( $nested_field_id < 1 || $child_form_id < 1 ) {
                continue;
            }

            $child_ids = metis_grandys_stash_legacy_extract_entry_ids( $parent_meta[ (string) $nested_field_id ] ?? null );
            foreach ( $child_ids as $child_id ) {
                $already_linked = false;
                foreach ( (array) ( $child_links_by_parent[ $parent_entry_id ] ?? [] ) as $link ) {
                    if ( (int) ( $link['id'] ?? 0 ) === $child_id ) {
                        $already_linked = true;
                        break;
                    }
                }
                if ( $already_linked ) {
                    continue;
                }

                $child_links_by_parent[ $parent_entry_id ][] = [
                    'id' => $child_id,
                    'form_id' => $child_form_id,
                    'nested_field_id' => (int) $nested_field_id,
                ];
            }
        }
    }

    $linked_child_ids = [];
    foreach ( $child_links_by_parent as $links ) {
        foreach ( (array) $links as $link ) {
            $child_id = (int) ( $link['id'] ?? 0 );
            if ( $child_id > 0 ) {
                $linked_child_ids[] = $child_id;
            }
        }
    }
    $linked_child_ids = array_values( array_unique( $linked_child_ids ) );
    if ( $linked_child_ids !== [] ) {
        $missing_child_ids = array_values(
            array_filter(
                $linked_child_ids,
                static fn ( int $child_id ): bool => ! isset( $child_meta_index[ $child_id ] )
            )
        );

        if ( $missing_child_ids !== [] ) {
            $child_meta_rows = $wpdb->get_results(
                "SELECT entry_id, meta_key, meta_value
                 FROM {$meta_table}
                 WHERE entry_id IN (" . implode( ', ', array_map( 'intval', $missing_child_ids ) ) . ')',
                ARRAY_A
            );

            foreach ( (array) $child_meta_rows as $row ) {
                $entry_id = (int) ( $row['entry_id'] ?? 0 );
                $meta_key = trim( (string) ( $row['meta_key'] ?? '' ) );
                if ( $entry_id < 1 || $meta_key === '' ) {
                    continue;
                }
                $child_meta_index[ $entry_id ][ $meta_key ] = (string) ( $row['meta_value'] ?? '' );
            }
        }
    }

    $entries = [];
    foreach ( $parent_entries as $entry ) {
        $parent_entry_id = (int) ( $entry['id'] ?? 0 );
        if ( $parent_entry_id < 1 ) {
            continue;
        }

        $meta = (array) ( $parent_meta_index[ $parent_entry_id ] ?? [] );
        $flow_value = metis_grandys_stash_legacy_entry_value( $meta, (string) $parent_map['flow'] );
        $type = str_contains( strtolower( $flow_value ), 'donate' ) ? 'donation' : 'request';
        $name_parts = metis_grandys_stash_legacy_name_parts( $meta, (array) $parent_map['name'] );
        $email = strtolower( trim( sanitize_email( metis_grandys_stash_legacy_entry_value( $meta, (string) $parent_map['email'] ) ) ) );
        $items = [];

        foreach ( (array) ( $child_links_by_parent[ $parent_entry_id ] ?? [] ) as $child_link ) {
            $child_form_id = (int) ( $child_link['form_id'] ?? 0 );
            $nested_field_id = (int) ( $child_link['nested_field_id'] ?? 0 );
            if ( $type === 'donation' && $nested_field_id !== $donation_nested_field_id ) {
                continue;
            }
            if ( $type === 'request' && $nested_field_id !== $request_nested_field_id ) {
                continue;
            }

            $child_map = (array) ( $child_field_maps[ $child_form_id ] ?? [] );
            $child_meta = (array) ( $child_meta_index[ (int) ( $child_link['id'] ?? 0 ) ] ?? [] );
            $item_name = metis_grandys_stash_legacy_entry_value( $child_meta, (string) ( $child_map['item'] ?? '' ) );
            if ( $item_name === '' ) {
                continue;
            }

            $items[] = [
                'item_name' => $item_name,
                'quantity'  => max( 1, (int) metis_grandys_stash_legacy_entry_value( $child_meta, (string) ( $child_map['quantity'] ?? '' ) ) ),
                'condition' => metis_grandys_stash_legacy_entry_value( $child_meta, (string) ( $child_map['condition'] ?? '' ) ),
            ];
        }

        $entries[] = [
            'parent_entry_id'   => $parent_entry_id,
            'type'              => $type,
            'legacy_status'     => metis_grandys_stash_legacy_entry_value( $meta, (string) $parent_map['status'] ),
            'name'              => (string) ( $name_parts['full'] ?? '' ),
            'first_name'        => (string) ( $name_parts['first'] ?? '' ),
            'last_name'         => (string) ( $name_parts['last'] ?? '' ),
            'email'             => $email,
            'phone'             => metis_grandys_stash_legacy_entry_value( $meta, (string) $parent_map['phone'] ),
            'organization_name' => metis_grandys_stash_legacy_entry_value( $meta, (string) $parent_map['organization'] ),
            'location'          => metis_grandys_stash_legacy_entry_value( $meta, (string) $parent_map['location'] ),
            'best_time'         => metis_grandys_stash_legacy_entry_value( $meta, (string) $parent_map['best_time'] ),
            'submitted_at'      => (string) ( $entry['date_created'] ?? '' ),
            'updated_at'        => (string) ( $entry['date_updated'] ?? '' ),
            'items'             => $items,
        ];
    }

    return new WP_REST_Response(
        [
            'ok'          => true,
            'form_id'     => $form_id,
            'exported_at' => gmdate( 'c' ),
            'entries'     => $entries,
        ],
        200
    );
}

function metis_grandys_stash_legacy_form_meta( string $form_meta_table, int $form_id ): array {
    global $wpdb;
    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT display_meta FROM {$form_meta_table} WHERE form_id = %d LIMIT 1",
            $form_id
        ),
        ARRAY_A
    );

    $raw = $row['display_meta'] ?? null;
    if ( is_array( $raw ) ) {
        return $raw;
    }
    if ( ! is_string( $raw ) || $raw === '' ) {
        return [];
    }

    $json = json_decode( $raw, true );
    if ( is_array( $json ) ) {
        return $json;
    }

    $unserialized = @unserialize( $raw );
    return is_array( $unserialized ) ? $unserialized : [];
}

function metis_grandys_stash_legacy_normalize_label( string $label ): string {
    return trim( strtolower( preg_replace( '/\s+/', ' ', $label ) ?? $label ) );
}

function metis_grandys_stash_legacy_parent_field_map( array $form_meta ): array {
    $map = [
        'status' => '',
        'flow' => '',
        'name' => [],
        'phone' => '',
        'email' => '',
        'organization' => '',
        'location' => '',
        'best_time' => '',
        'donation_nested' => [],
        'request_nested' => [],
    ];
    $nested_candidates = [];

    foreach ( (array) ( $form_meta['fields'] ?? [] ) as $field ) {
        if ( ! is_array( $field ) ) {
            continue;
        }

        $signals = array_values(
            array_filter(
                array_unique(
                    array_map(
                        static fn ( $value ) => metis_grandys_stash_legacy_normalize_label( (string) $value ),
                        [
                            $field['label'] ?? '',
                            $field['adminLabel'] ?? '',
                            $field['inputName'] ?? '',
                            $field['placeholder'] ?? '',
                            $field['description'] ?? '',
                        ]
                    )
                ),
                static fn ( string $value ): bool => $value !== ''
            )
        );
        $label = (string) ( $signals[0] ?? '' );
        $field_id = (string) ( $field['id'] ?? '' );
        $field_type = sanitize_key( (string) ( $field['type'] ?? '' ) );
        $is_nested = isset( $field['gpnfForm'] ) || str_contains( $field_type, 'nested' ) || str_contains( $field_type, 'form' );
        if ( $field_id === '' ) {
            continue;
        }

        $contains = static function ( array $haystack, array $needles ): bool {
            foreach ( $haystack as $signal ) {
                foreach ( $needles as $needle ) {
                    if ( $needle !== '' && str_contains( $signal, $needle ) ) {
                        return true;
                    }
                }
            }
            return false;
        };

        if ( $label === 'status' || $contains( $signals, [ 'status' ] ) ) {
            $map['status'] = $field_id;
        } elseif ( $contains( $signals, [ 'donate or request', 'donation or request', 'donate', 'request supplies', 'request equipment' ] ) ) {
            $map['flow'] = $field_id;
        } elseif ( $field_type === 'name' || $label === 'name' ) {
            $map['name'] = $field;
        } elseif ( $field_type === 'phone' || $contains( $signals, [ 'phone', 'telephone', 'mobile', 'cell' ] ) ) {
            $map['phone'] = $field_id;
        } elseif ( $field_type === 'email' || $contains( $signals, [ 'email', 'e-mail' ] ) ) {
            $map['email'] = $field_id;
        } elseif ( $contains( $signals, [ 'agency associated with', 'organization', 'organisation', 'agency', 'facility', 'company' ] ) ) {
            $map['organization'] = $field_id;
        } elseif ( $contains( $signals, [ 'location', 'address', 'pickup address', 'delivery address' ] ) ) {
            $map['location'] = $field_id;
        } elseif ( $contains( $signals, [ 'best time to contact', 'best time', 'contact time', 'best time to reach' ] ) ) {
            $map['best_time'] = $field_id;
        } elseif ( $is_nested && $contains( $signals, [ 'donate', 'donation', 'offer' ] ) ) {
            $map['donation_nested'] = $field;
        } elseif ( $is_nested && $contains( $signals, [ 'request', 'requested', 'need', 'needed' ] ) ) {
            $map['request_nested'] = $field;
        } elseif ( $is_nested ) {
            $nested_candidates[] = $field;
        }
    }

    if ( $map['request_nested'] === [] && $nested_candidates !== [] ) {
        $map['request_nested'] = $nested_candidates[0];
    }
    if ( $map['donation_nested'] === [] ) {
        if ( count( $nested_candidates ) > 1 ) {
            $map['donation_nested'] = $nested_candidates[1];
        } elseif ( $nested_candidates !== [] && $map['request_nested'] === [] ) {
            $map['donation_nested'] = $nested_candidates[0];
        }
    }

    return $map;
}

function metis_grandys_stash_legacy_child_field_map( array $form_meta ): array {
    $map = [
        'item' => '',
        'quantity' => '',
        'condition' => '',
    ];
    $fallback_item_field = '';

    foreach ( (array) ( $form_meta['fields'] ?? [] ) as $field ) {
        if ( ! is_array( $field ) ) {
            continue;
        }

        $signals = array_values(
            array_filter(
                array_unique(
                    array_map(
                        static fn ( $value ) => metis_grandys_stash_legacy_normalize_label( (string) $value ),
                        [
                            $field['label'] ?? '',
                            $field['adminLabel'] ?? '',
                            $field['inputName'] ?? '',
                            $field['placeholder'] ?? '',
                            $field['description'] ?? '',
                        ]
                    )
                ),
                static fn ( string $value ): bool => $value !== ''
            )
        );
        $label = (string) ( $signals[0] ?? '' );
        $field_id = (string) ( $field['id'] ?? '' );
        $field_type = sanitize_key( (string) ( $field['type'] ?? '' ) );
        if ( $field_id === '' ) {
            continue;
        }

        $contains = static function ( array $haystack, array $needles ): bool {
            foreach ( $haystack as $signal ) {
                foreach ( $needles as $needle ) {
                    if ( $needle !== '' && str_contains( $signal, $needle ) ) {
                        return true;
                    }
                }
            }
            return false;
        };

        if ( $contains( $signals, [ 'how many', 'quantity', 'qty', 'count', 'number needed', 'number requested' ] ) ) {
            $map['quantity'] = $field_id;
            continue;
        }

        if ( $contains( $signals, [ 'condition', 'quality', 'state of item' ] ) ) {
            $map['condition'] = $field_id;
            continue;
        }

        if ( $contains( $signals, [ 'item requested', 'item to donate', 'item needed', 'equipment', 'supply', 'supplies', 'dme', 'item', 'device', 'product', 'requested' ] ) ) {
            $map['item'] = $field_id;
            continue;
        }

        if (
            $fallback_item_field === ''
            && ! in_array( $field_type, [ 'hidden', 'html', 'section', 'page', 'captcha' ], true )
            && ! $contains( $signals, [ 'name', 'email', 'phone', 'address' ] )
        ) {
            $fallback_item_field = $field_id;
        }
    }

    if ( $map['item'] === '' ) {
        $map['item'] = $fallback_item_field;
    }

    return $map;
}

function metis_grandys_stash_legacy_entry_value( array $meta_index, string $field_id ): string {
    if ( $field_id === '' ) {
        return '';
    }

    return trim( (string) ( $meta_index[ $field_id ] ?? '' ) );
}

function metis_grandys_stash_legacy_extract_entry_ids( $value ): array {
    $ids = [];

    $collect = static function ( $candidate ) use ( &$ids, &$collect ): void {
        if ( is_array( $candidate ) ) {
            foreach ( $candidate as $nested ) {
                $collect( $nested );
            }
            return;
        }

        if ( is_string( $candidate ) ) {
            $candidate = trim( $candidate );
            if ( $candidate === '' ) {
                return;
            }

            $decoded = json_decode( $candidate, true );
            if ( is_array( $decoded ) ) {
                $collect( $decoded );
                return;
            }

            $unserialized = @unserialize( $candidate );
            if ( is_array( $unserialized ) ) {
                $collect( $unserialized );
                return;
            }

            if ( preg_match_all( '/\d+/', $candidate, $matches ) ) {
                foreach ( (array) ( $matches[0] ?? [] ) as $match ) {
                    $id = (int) $match;
                    if ( $id > 0 ) {
                        $ids[] = $id;
                    }
                }
            }

            return;
        }

        $id = (int) $candidate;
        if ( $id > 0 ) {
            $ids[] = $id;
        }
    };

    $collect( $value );

    return array_values( array_unique( array_filter( $ids ) ) );
}

function metis_grandys_stash_legacy_name_parts( array $meta_index, array $name_field ): array {
    $full_name = '';
    $first_name = '';
    $last_name = '';

    foreach ( (array) ( $name_field['inputs'] ?? [] ) as $input ) {
        if ( ! is_array( $input ) ) {
            continue;
        }

        $input_id = (string) ( $input['id'] ?? '' );
        $value = metis_grandys_stash_legacy_entry_value( $meta_index, $input_id );
        if ( $value === '' ) {
            continue;
        }

        $input_label = metis_grandys_stash_legacy_normalize_label( (string) ( $input['label'] ?? '' ) );
        if ( $input_label === 'first' ) {
            $first_name = $value;
        } elseif ( $input_label === 'last' ) {
            $last_name = $value;
        }
    }

    if ( $first_name === '' && $last_name === '' ) {
        $full_name = metis_grandys_stash_legacy_entry_value( $meta_index, (string) ( $name_field['id'] ?? '' ) );
        $parts = preg_split( '/\s+/', trim( $full_name ) ) ?: [];
        if ( count( $parts ) > 1 ) {
            $last_name = (string) array_pop( $parts );
            $first_name = trim( implode( ' ', $parts ) );
        } else {
            $first_name = $full_name;
        }
    } else {
        $full_name = trim( $first_name . ' ' . $last_name );
    }

    return [
        'full' => $full_name,
        'first' => $first_name,
        'last' => $last_name,
    ];
}
