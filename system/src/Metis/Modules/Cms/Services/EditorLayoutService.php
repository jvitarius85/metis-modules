<?php
declare(strict_types=1);

namespace Metis\Modules\Cms\Services;

final class EditorLayoutService {
    /**
     * @param mixed $raw
     * @return array<string,mixed>
     */
    public static function decodeLayout( $raw ): array {
        if ( is_string( $raw ) ) {
            $decoded = json_decode( $raw, true );
            if ( ! is_array( $decoded ) ) {
                return self::emptyLayout();
            }
            return self::normalizeLayout( $decoded );
        }

        if ( is_array( $raw ) ) {
            return self::normalizeLayout( $raw );
        }

        return self::emptyLayout();
    }

    /**
     * @param mixed $raw
     * @return array<int,array<string,mixed>>
     */
    public static function modulesFromLayout( $raw ): array {
        $layout = self::decodeLayout( $raw );
        $modules = [];
        self::collectModulesFromSections( self::layoutSections( $layout ), $modules );
        return $modules;
    }

    /**
     * Returns editor-renderable blocks while preserving section/column intent.
     *
     * @param mixed $raw
     * @return array<int,array<string,mixed>>
     */
    public static function renderBlocksFromLayout( $raw ): array {
        $layout = self::decodeLayout( $raw );
        return self::sectionsToRenderableBlocks( self::layoutSections( $layout ) );
    }

    /**
     * @param mixed $raw
     * @return array{valid:bool,errors:array<int,array<string,mixed>>}
     */
    public static function validateLayout( $raw ): array {
        $layout = self::decodeLayout( $raw );
        $errors = [];
        self::validateSections( self::layoutSections( $layout ), 1, 'sections', $errors );

        return [
            'valid' => $errors === [],
            'errors' => $errors,
        ];
    }

    /**
     * @param array<string,mixed> $layout
     * @return array<int,array<string,mixed>>
     */
    private static function layoutSections( array $layout ): array {
        $sections = isset( $layout['sections'] ) && is_array( $layout['sections'] )
            ? $layout['sections']
            : [];
        return array_values( array_filter( $sections, 'is_array' ) );
    }

    /**
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    private static function normalizeLayout( array $raw ): array {
        // Legacy payload: root block list.
        if ( isset( $raw[0] ) && is_array( $raw[0] ) && isset( $raw[0]['type'] ) ) {
            return [
                'version' => 2,
                'sections' => [
                    self::buildSectionFromLegacyBlocks( $raw, 0 ),
                ],
            ];
        }

        // Legacy payload: root blocks object.
        if ( isset( $raw['blocks'] ) && is_array( $raw['blocks'] ) ) {
            return [
                'version' => 2,
                'sections' => [
                    self::buildSectionFromLegacyBlocks( $raw['blocks'], 0 ),
                ],
            ];
        }

        $sections = isset( $raw['sections'] ) && is_array( $raw['sections'] ) ? $raw['sections'] : [];
        if ( $sections === [] ) {
            return self::emptyLayout();
        }

        $normalized_sections = [];
        foreach ( $sections as $section_index => $section ) {
            if ( ! is_array( $section ) ) {
                continue;
            }
            $normalized_sections[] = self::normalizeSection( $section, (int) $section_index );
        }

        return [
            'version' => 2,
            'sections' => $normalized_sections,
        ];
    }

    /**
     * @param array<string,mixed> $section
     * @return array<string,mixed>
     */
    private static function normalizeSection( array $section, int $index ): array {
        $id = isset( $section['id'] ) && is_scalar( $section['id'] ) && trim( (string) $section['id'] ) !== ''
            ? trim( (string) $section['id'] )
            : ( 'section_' . (string) $index );

        $columns_input = isset( $section['columns'] ) && is_array( $section['columns'] ) ? $section['columns'] : null;
        $columns = [];

        if ( is_array( $columns_input ) && $columns_input !== [] ) {
            foreach ( $columns_input as $column_index => $column ) {
                if ( ! is_array( $column ) ) {
                    continue;
                }
                $columns[] = self::normalizeColumn( $column, (string) $id, (int) $column_index );
            }
        } else {
            $legacy_blocks = isset( $section['blocks'] ) && is_array( $section['blocks'] ) ? $section['blocks'] : [];
            $columns[] = [
                'id' => $id . '_col_0',
                'width' => 1.0,
                'modules' => self::filterModules( $legacy_blocks ),
                'settings' => [],
            ];
        }

        if ( $columns === [] ) {
            $columns[] = [
                'id' => $id . '_col_0',
                'width' => 1.0,
                'modules' => [],
                'settings' => [],
            ];
        }

        $nested = isset( $section['sections'] ) && is_array( $section['sections'] ) ? $section['sections'] : [];
        $normalized_nested = [];
        foreach ( $nested as $nested_index => $child ) {
            if ( ! is_array( $child ) ) {
                continue;
            }
            $normalized_nested[] = self::normalizeSection( $child, (int) $nested_index );
        }

        $settings = [];
        if ( isset( $section['settings'] ) && is_array( $section['settings'] ) ) {
            $settings = $section['settings'];
        } else {
            if ( isset( $section['style'] ) && is_array( $section['style'] ) ) {
                $settings['style'] = $section['style'];
            }
            if ( isset( $section['max_width'] ) && is_scalar( $section['max_width'] ) ) {
                $settings['max_width'] = (string) $section['max_width'];
            }
            if ( isset( $section['align'] ) && is_scalar( $section['align'] ) ) {
                $settings['align'] = (string) $section['align'];
            }
        }

        return [
            'id' => $id,
            'columns' => $columns,
            'sections' => $normalized_nested,
            'settings' => $settings,
        ];
    }

    /**
     * @param array<string,mixed> $column
     * @return array<string,mixed>
     */
    private static function normalizeColumn( array $column, string $section_id, int $index ): array {
        $id = isset( $column['id'] ) && is_scalar( $column['id'] ) && trim( (string) $column['id'] ) !== ''
            ? trim( (string) $column['id'] )
            : ( $section_id . '_col_' . (string) $index );

        $width = isset( $column['width'] ) && is_numeric( $column['width'] )
            ? (float) $column['width']
            : 1.0;
        if ( $width <= 0.0 ) {
            $width = 1.0;
        }

        $modules_input = isset( $column['modules'] ) && is_array( $column['modules'] ) ? $column['modules'] : [];
        $settings = isset( $column['settings'] ) && is_array( $column['settings'] ) ? $column['settings'] : [];

        return [
            'id' => $id,
            'width' => $width,
            'modules' => self::filterModules( $modules_input ),
            'settings' => $settings,
        ];
    }

    /**
     * @param array<int,mixed> $blocks
     * @return array<string,mixed>
     */
    private static function buildSectionFromLegacyBlocks( array $blocks, int $index ): array {
        $id = 'section_' . (string) $index;
        return [
            'id' => $id,
            'columns' => [
                [
                    'id' => $id . '_col_0',
                    'width' => 1.0,
                    'modules' => self::filterModules( $blocks ),
                    'settings' => [],
                ],
            ],
            'sections' => [],
            'settings' => [],
        ];
    }

    /**
     * @param array<int,mixed> $candidate
     * @return array<int,array<string,mixed>>
     */
    private static function filterModules( array $candidate ): array {
        $modules = [];
        foreach ( $candidate as $item ) {
            if ( is_array( $item ) && isset( $item['type'] ) ) {
                $modules[] = $item;
            }
        }
        return array_values( $modules );
    }

    /**
     * @param array<int,array<string,mixed>> $sections
     * @param array<int,array<string,mixed>> $modules
     */
    private static function collectModulesFromSections( array $sections, array &$modules ): void {
        foreach ( $sections as $section ) {
            $columns = isset( $section['columns'] ) && is_array( $section['columns'] ) ? $section['columns'] : [];
            foreach ( $columns as $column ) {
                if ( ! is_array( $column ) ) {
                    continue;
                }
                $column_modules = isset( $column['modules'] ) && is_array( $column['modules'] ) ? $column['modules'] : [];
                foreach ( $column_modules as $module ) {
                    if ( is_array( $module ) && isset( $module['type'] ) ) {
                        $modules[] = $module;
                    }
                }
            }
            $nested = isset( $section['sections'] ) && is_array( $section['sections'] ) ? $section['sections'] : [];
            $nested_filtered = array_values( array_filter( $nested, 'is_array' ) );
            if ( $nested_filtered !== [] ) {
                self::collectModulesFromSections( $nested_filtered, $modules );
            }
        }
    }

    /**
     * @param array<int,array<string,mixed>> $sections
     * @return array<int,array<string,mixed>>
     */
    private static function sectionsToRenderableBlocks( array $sections ): array {
        $blocks = [];
        foreach ( $sections as $index => $section ) {
            if ( ! is_array( $section ) ) {
                continue;
            }

            $section_id = isset( $section['id'] ) && is_scalar( $section['id'] ) && trim( (string) $section['id'] ) !== ''
                ? trim( (string) $section['id'] )
                : ( 'section_' . (string) $index );
            $settings = isset( $section['settings'] ) && is_array( $section['settings'] ) ? $section['settings'] : [];
            $section_style = isset( $settings['style'] ) && is_array( $settings['style'] ) ? $settings['style'] : [];
            $max_width = isset( $settings['max_width'] ) && is_scalar( $settings['max_width'] )
                ? (string) $settings['max_width']
                : '100%';
            $align = isset( $settings['align'] ) && is_scalar( $settings['align'] )
                ? (string) $settings['align']
                : 'full';

            $columns = isset( $section['columns'] ) && is_array( $section['columns'] ) ? $section['columns'] : [];
            $column_lists = [];
            $ratios = [];
            foreach ( $columns as $column ) {
                if ( ! is_array( $column ) ) {
                    continue;
                }
                $mods = isset( $column['modules'] ) && is_array( $column['modules'] )
                    ? self::filterModules( $column['modules'] )
                    : [];
                $column_lists[] = $mods;
                $width = isset( $column['width'] ) && is_numeric( $column['width'] ) ? (float) $column['width'] : 1.0;
                if ( $width <= 0.0 ) {
                    $width = 1.0;
                }
                $ratios[] = $width;
            }

            $content_blocks = [];
            if ( count( $column_lists ) > 1 ) {
                $content_blocks[] = [
                    'id' => $section_id . '_grid',
                    'type' => 'grid',
                    'data' => [
                        'columns' => count( $column_lists ),
                        'ratios' => $ratios,
                        'gap' => '24px',
                        'col_blocks' => $column_lists,
                    ],
                    'style' => [],
                ];
            } elseif ( isset( $column_lists[0] ) ) {
                $content_blocks = $column_lists[0];
            }

            $nested = isset( $section['sections'] ) && is_array( $section['sections'] ) ? $section['sections'] : [];
            $nested_sections = array_values( array_filter( $nested, 'is_array' ) );
            if ( $nested_sections !== [] ) {
                $content_blocks = array_merge( $content_blocks, self::sectionsToRenderableBlocks( $nested_sections ) );
            }

            $blocks[] = [
                'id' => $section_id,
                'type' => 'container',
                'style' => $section_style,
                'data' => [
                    'blocks' => $content_blocks,
                    'max_width' => $max_width,
                    'align' => $align,
                ],
            ];
        }
        return $blocks;
    }

    /**
     * @param array<int,array<string,mixed>> $sections
     * @param array<int,array<string,mixed>> $errors
     */
    private static function validateSections( array $sections, int $depth, string $path, array &$errors ): void {
        if ( $depth > 2 ) {
            $errors[] = [
                'path' => $path,
                'message' => 'Sections can only be nested up to 2 levels.',
            ];
            return;
        }

        foreach ( $sections as $section_index => $section ) {
            if ( ! is_array( $section ) ) {
                $errors[] = [
                    'path' => $path . '.' . (string) $section_index,
                    'message' => 'Invalid section payload.',
                ];
                continue;
            }

            $section_path = $path . '.' . (string) $section_index;
            $columns = isset( $section['columns'] ) && is_array( $section['columns'] ) ? $section['columns'] : [];
            if ( $columns === [] ) {
                $errors[] = [
                    'path' => $section_path,
                    'message' => 'Each section must include at least one column.',
                ];
            }

            foreach ( $columns as $column_index => $column ) {
                $column_path = $section_path . '.columns.' . (string) $column_index;
                if ( ! is_array( $column ) ) {
                    $errors[] = [
                        'path' => $column_path,
                        'message' => 'Invalid column payload.',
                    ];
                    continue;
                }
                $modules = isset( $column['modules'] ) && is_array( $column['modules'] ) ? $column['modules'] : [];
                foreach ( $modules as $module_index => $module ) {
                    if ( ! is_array( $module ) || ! isset( $module['type'] ) ) {
                        $errors[] = [
                            'path' => $column_path . '.modules.' . (string) $module_index,
                            'message' => 'Invalid module payload.',
                        ];
                    }
                }
            }

            $nested = isset( $section['sections'] ) && is_array( $section['sections'] ) ? $section['sections'] : [];
            $nested_sections = array_values( array_filter( $nested, 'is_array' ) );
            if ( $nested_sections !== [] ) {
                self::validateSections( $nested_sections, $depth + 1, $section_path . '.sections', $errors );
            }
        }
    }

    /**
     * @return array{version:int,sections:array<int,array<string,mixed>>}
     */
    private static function emptyLayout(): array {
        return [
            'version' => 2,
            'sections' => [],
        ];
    }
}
