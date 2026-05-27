<?php
declare(strict_types=1);

namespace Metis\Core\Services;

final class AjaxCodeLookupService {
    public static function resolveCodePayload( string $code, bool $fuzzy ): array {
        $code = strtoupper( trim( \metis_text_clean( \metis_runtime_unslash( $code ) ) ) );
        if ( $code === '' ) {
            \metis_runtime_send_json_error( [ 'message' => 'A lookup code is required.' ], 422 );
        }

        $max_candidates = 75;
        $max_results = 25;
        $matches = [];
        $seen_codes = [];
        $append_match = static function ( ?array $entry ) use ( &$matches, &$seen_codes ): void {
            if ( ! is_array( $entry ) ) {
                return;
            }

            $entry_code = strtoupper( trim( (string) ( $entry['code'] ?? '' ) ) );
            if ( $entry_code === '' || isset( $seen_codes[ $entry_code ] ) ) {
                return;
            }

            $seen_codes[ $entry_code ] = true;
            $matches[] = [
                'code' => $entry_code,
                'entity_uid' => $entry_code,
                'entity_type' => (string) ( $entry['entity_type'] ?? '' ),
                'label' => (string) ( $entry['label'] ?? $entry_code ),
                'url' => (string) ( $entry['resolve_url'] ?? $entry['url'] ?? '' ),
                'match_type' => (string) ( $entry['match_type'] ?? 'code' ),
                'matched_on' => (string) ( $entry['matched_on'] ?? '' ),
            ];
        };

        $score_match = static function ( array $match, string $query ): int {
            $candidate_code = strtoupper( trim( (string) ( $match['code'] ?? '' ) ) );
            $candidate_label = strtoupper( trim( (string) ( $match['label'] ?? '' ) ) );
            $query = strtoupper( trim( $query ) );
            if ( ( $candidate_code === '' && $candidate_label === '' ) || $query === '' ) {
                return 9999;
            }

            $candidate_norm = preg_replace( '/[^A-Z0-9]/', '', $candidate_code ) ?? '';
            $label_norm = preg_replace( '/[^A-Z0-9]/', '', $candidate_label ) ?? '';
            $query_norm = preg_replace( '/[^A-Z0-9*]/', '', $query ) ?? '';
            $query_plain = str_replace( '*', '', $query_norm );
            $score = 1000;

            if ( $candidate_code === $query ) {
                return 0;
            }
            if ( $query_plain !== '' && $candidate_norm === $query_plain ) {
                return 10;
            }
            if ( $query_plain !== '' && $label_norm === $query_plain ) {
                return 15;
            }

            if ( strpos( $query, '*' ) !== false ) {
                $pattern = '/^' . str_replace( '\*', '.*', preg_quote( $query_norm, '/' ) ) . '$/';
                if ( preg_match( $pattern, $candidate_norm ) === 1 || preg_match( $pattern, $label_norm ) === 1 ) {
                    $score -= 250;
                }
            }

            if ( $query_plain !== '' ) {
                $pos = strpos( $candidate_norm, $query_plain );
                if ( $pos === 0 ) {
                    $score -= 220;
                } elseif ( $pos !== false ) {
                    $score -= (150 - min( 120, (int) $pos ));
                }

                $label_pos = strpos( $label_norm, $query_plain );
                if ( $label_pos === 0 ) {
                    $score -= 240;
                } elseif ( $label_pos !== false ) {
                    $score -= (170 - min( 120, (int) $label_pos ));
                }
            }

            if ( preg_match( '/^([A-Z]{2,8})-(\d{6})$/', $candidate_code, $cm ) ) {
                $cand_prefix = (string) ( $cm[1] ?? '' );
                $cand_suffix = (string) ( $cm[2] ?? '' );
                $query_prefix = '';
                $query_digits = preg_replace( '/\D/', '', $query ) ?? '';

                if ( preg_match( '/^([A-Z0-9]{2,8})-?[0-9]*$/', $query, $qm ) ) {
                    $query_prefix = strtoupper( (string) ( $qm[1] ?? '' ) );
                }

                if ( $query_prefix !== '' && $query_prefix === $cand_prefix ) {
                    $score -= 140;
                }

                if ( $query_digits !== '' ) {
                    $suffix_pos = strpos( $cand_suffix, $query_digits );
                    if ( $suffix_pos === 0 ) {
                        $score -= 110;
                    } elseif ( $suffix_pos !== false ) {
                        $score -= (80 - min( 60, (int) $suffix_pos ));
                    }
                }
            }

            if ( $query_plain !== '' ) {
                $length_delta = $candidate_norm !== ''
                    ? abs( strlen( $candidate_norm ) - strlen( $query_plain ) )
                    : 0;
                if ( $label_norm !== '' ) {
                    $length_delta = min( $length_delta, abs( strlen( $label_norm ) - strlen( $query_plain ) ) );
                }
                $score += $length_delta;
            }

            return $score;
        };

        $resolved = class_exists( 'Metis_Code_Registry' ) ? \Metis_Code_Registry::resolve( $code ) : null;
        $append_match( is_array( $resolved ) ? $resolved : null );
        if ( ! is_array( $resolved ) && class_exists( 'Metis_Tables' ) ) {
            $people_table = \Metis_Tables::get( 'people' );
            if ( is_string( $people_table ) && $people_table !== '' ) {
                $db = \metis_db();
                $person = $db->fetchOne(
                    "SELECT id, pid, person_uid, display_name, first_name, last_name, email
                     FROM {$people_table}
                     WHERE pid = %s OR person_uid = %s
                     LIMIT 1",
                    [ $code, $code ]
                );

                if ( ! is_array( $person ) ) {
                    $person = $db->fetchOne(
                        "SELECT id, pid, person_uid, display_name, first_name, last_name, email
                         FROM {$people_table}
                         WHERE UPPER(COALESCE(pid, '')) = %s
                            OR UPPER(COALESCE(person_uid, '')) = %s
                         LIMIT 1",
                        [ $code, $code ]
                    );
                }

                if ( is_array( $person ) ) {
                    $person_pid = trim( (string) ( $person['pid'] ?? $person['person_uid'] ?? $code ) );
                    $label = trim( (string) ( $person['display_name'] ?? '' ) );
                    if ( $label === '' ) {
                        $label = trim( (string) ( $person['first_name'] ?? '' ) . ' ' . (string) ( $person['last_name'] ?? '' ) );
                    }
                    if ( $label === '' ) {
                        $label = trim( (string) ( $person['email'] ?? '' ) );
                    }
                    if ( $label === '' ) {
                        $label = $person_pid;
                    }

                    $resolved = [
                        'code' => $person_pid !== '' ? $person_pid : $code,
                        'entity_type' => 'person',
                        'label' => $label,
                        'resolve_url' => function_exists( 'metis_people_person_url' ) ? \metis_people_person_url( $person_pid ) : '',
                    ];
                    $append_match( $resolved );
                }
            }
        }

        if ( ! is_array( $resolved ) ) {
            $registry_table = class_exists( 'Metis_Tables' ) ? \Metis_Tables::get( 'entity_registry' ) : '';
            if ( $fuzzy && $registry_table !== '' ) {
                $db = \metis_db();
                $build_patterns = static function ( string $input ): array {
                    $input = strtoupper( trim( $input ) );
                    $input = preg_replace( '/\s+/', '', $input ) ?? '';
                    $input = preg_replace( '/[^A-Z0-9-]/', '', $input ) ?? '';
                    if ( $input === '' ) {
                        return [];
                    }

                    $variants = [ $input ];
                    $prefix_hint = '';
                    $normalize_prefix = static function ( string $prefix ): string {
                        return strtr( strtoupper( $prefix ), [
                            '0' => 'O',
                            '1' => 'L',
                            '2' => 'Z',
                            '5' => 'S',
                            '8' => 'B',
                        ] );
                    };

                    if ( preg_match( '/^([A-Z0-9]{2,8})-?([0-9]{1,6})$/', $input, $matches ) ) {
                        $prefix = $normalize_prefix( (string) ( $matches[1] ?? '' ) );
                        $number = (string) ( $matches[2] ?? '' );
                        if ( $prefix !== '' && $number !== '' ) {
                            $prefix_hint = $prefix;
                            $variants[] = $prefix . '-' . $number;
                        }
                    } elseif ( strpos( $input, '-' ) !== false ) {
                        [ $prefix, $suffix ] = array_pad( explode( '-', $input, 2 ), 2, '' );
                        $fixed_prefix = $normalize_prefix( (string) $prefix );
                        if ( $fixed_prefix !== '' && $suffix !== '' ) {
                            $prefix_hint = $fixed_prefix;
                            $variants[] = $fixed_prefix . '-' . $suffix;
                        }
                    }

                    $number_fragment = preg_replace( '/\D/', '', $input ) ?? '';
                    $patterns = [];
                    foreach ( array_values( array_unique( $variants ) ) as $variant ) {
                        $patterns[] = $variant . '%';
                        if ( preg_match( '/^([A-Z]{2,8})-([0-9]{1,6})$/', $variant, $m ) ) {
                            $prefix = (string) ( $m[1] ?? '' );
                            $num = (string) ( $m[2] ?? '' );
                            $patterns[] = $prefix . '-' . str_pad( $num, 6, '0', STR_PAD_LEFT ) . '%';
                        }
                        if ( preg_match( '/^[0-9]{1,6}$/', $variant ) ) {
                            $patterns[] = '%-' . str_pad( $variant, 6, '0', STR_PAD_LEFT );
                            if ( strlen( $variant ) >= 2 ) {
                                $patterns[] = '%-' . $variant . '%';
                            }
                        }
                    }

                    if ( $number_fragment !== '' ) {
                        $trimmed_fragment = ltrim( $number_fragment, '0' );
                        if ( $trimmed_fragment === '' ) {
                            $trimmed_fragment = '0';
                        }

                        $patterns[] = '%-' . $number_fragment . '%';
                        if ( $trimmed_fragment !== $number_fragment ) {
                            $patterns[] = '%-' . $trimmed_fragment . '%';
                        }

                        if ( $prefix_hint !== '' ) {
                            $patterns[] = $prefix_hint . '-%' . $number_fragment . '%';
                            if ( $trimmed_fragment !== $number_fragment ) {
                                $patterns[] = $prefix_hint . '-%' . $trimmed_fragment . '%';
                            }
                        }
                    }

                    return array_values( array_unique( $patterns ) );
                };

                foreach ( $build_patterns( $code ) as $pattern ) {
                    $rows = $db->fetchAll(
                        "SELECT entity_uid
                         FROM {$registry_table}
                         WHERE UPPER(entity_uid) LIKE %s
                         ORDER BY entity_uid ASC
                         LIMIT 24",
                        [ strtoupper( $pattern ) ]
                    );

                    foreach ( (array) $rows as $row ) {
                        if ( ! is_array( $row ) || empty( $row['entity_uid'] ) || ! class_exists( 'Metis_Code_Registry' ) ) {
                            continue;
                        }

                        $candidate = \Metis_Code_Registry::resolve( strtoupper( trim( (string) $row['entity_uid'] ) ) );
                        if ( is_array( $candidate ) ) {
                            $append_match( $candidate );
                            if ( ! is_array( $resolved ) ) {
                                $resolved = $candidate;
                            }
                        }
                    }

                    if ( count( $matches ) >= $max_candidates ) {
                        break;
                    }
                }

                if ( count( $matches ) < $max_candidates ) {
                    $normalized_lookup = strtoupper( preg_replace( '/[^A-Z0-9*]/', '', $code ) ?? '' );
                    if ( $normalized_lookup !== '' && strlen( str_replace( '*', '', $normalized_lookup ) ) >= 3 ) {
                        $normalized_pattern = str_replace( '*', '%', $normalized_lookup );
                        if ( strpos( $normalized_pattern, '%' ) === false ) {
                            $normalized_pattern = '%' . $normalized_pattern . '%';
                        }

                        $rows = $db->fetchAll(
                            "SELECT entity_uid
                             FROM {$registry_table}
                             WHERE REPLACE(UPPER(entity_uid), '-', '') LIKE %s
                             ORDER BY entity_uid ASC
                             LIMIT 24",
                            [ $normalized_pattern ]
                        );

                        foreach ( (array) $rows as $row ) {
                            if ( ! is_array( $row ) || empty( $row['entity_uid'] ) || ! class_exists( 'Metis_Code_Registry' ) ) {
                                continue;
                            }

                            $candidate = \Metis_Code_Registry::resolve( strtoupper( trim( (string) $row['entity_uid'] ) ) );
                            if ( is_array( $candidate ) ) {
                                $append_match( $candidate );
                                if ( ! is_array( $resolved ) ) {
                                    $resolved = $candidate;
                                }
                            }

                            if ( count( $matches ) >= $max_candidates ) {
                                break;
                            }
                        }
                    }
                }

                if ( count( $matches ) < $max_candidates && class_exists( 'Metis_Code_Registry' ) ) {
                    $keyword_lookup = strtoupper( preg_replace( '/[^A-Z0-9 @.-]/', ' ', $code ) ?? '' );
                    $keyword_plain = preg_replace( '/[^A-Z0-9]/', '', $keyword_lookup ) ?? '';
                    if ( strlen( $keyword_plain ) >= 3 ) {
                        foreach ( \Metis_Code_Registry::search( $keyword_lookup, $max_candidates - count( $matches ) ) as $candidate ) {
                            if ( is_array( $candidate ) ) {
                                $append_match( $candidate );
                                if ( ! is_array( $resolved ) ) {
                                    $resolved = $candidate;
                                }
                            }

                            if ( count( $matches ) >= $max_candidates ) {
                                break;
                            }
                        }
                    }
                }
            }
        }

        if ( $matches !== [] ) {
            foreach ( $matches as $index => $entry ) {
                $matches[ $index ]['_score'] = $score_match( is_array( $entry ) ? $entry : [], $code );
            }

            usort( $matches, static function ( array $a, array $b ): int {
                $left = (int) ( $a['_score'] ?? 9999 );
                $right = (int) ( $b['_score'] ?? 9999 );
                if ( $left === $right ) {
                    return strcmp( (string) ( $a['code'] ?? '' ), (string) ( $b['code'] ?? '' ) );
                }
                return $left <=> $right;
            } );

            $matches = array_map( static function ( array $entry ): array {
                unset( $entry['_score'] );
                return $entry;
            }, $matches );
        }

        if ( ! is_array( $resolved ) && $matches !== [] ) {
            $resolved = [
                'code' => (string) ( $matches[0]['code'] ?? $code ),
                'entity_type' => (string) ( $matches[0]['entity_type'] ?? '' ),
                'label' => (string) ( $matches[0]['label'] ?? $code ),
                'resolve_url' => (string) ( $matches[0]['url'] ?? '' ),
            ];
        }

        if ( ! is_array( $resolved ) ) {
            return [
                'found' => false,
                'message' => 'Code not found.',
                'code' => $code,
                'entity_uid' => $code,
                'entity_type' => '',
                'label' => '',
                'url' => '',
                'matches' => [],
            ];
        }

        return [
            'found' => true,
            'code' => (string) ( $resolved['code'] ?? $code ),
            'entity_uid' => (string) ( $resolved['code'] ?? $code ),
            'entity_type' => (string) ( $resolved['entity_type'] ?? '' ),
            'label' => (string) ( $resolved['label'] ?? ( $resolved['code'] ?? $code ) ),
            'url' => (string) ( $resolved['resolve_url'] ?? '' ),
            'matches' => array_slice( $matches, 0, $max_results ),
        ];
    }
}
