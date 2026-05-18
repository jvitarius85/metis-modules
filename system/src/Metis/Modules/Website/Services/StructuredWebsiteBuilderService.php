<?php
declare(strict_types=1);

namespace Metis\Modules\Website\Services;

/**
 * Strict structured website schema and normalization service.
 */
final class StructuredWebsiteBuilderService {
    /** @var array<int,string> */
    private const PAGE_TYPES = [ 'homepage', 'page' ];

    /** @var array<int,string> */
    private const PAGE_SECTION_TYPES = [ 'heading', 'text', 'image', 'button', 'columns', 'hero', 'feature_grid', 'card_grid', 'cta', 'events', 'form', 'donation_form', 'donation_progress', 'campaign_summary', 'divider', 'spacer', 'posts_list', 'html' ];

    /** @var array<int,string> */
    private const POST_SECTION_TYPES = [ 'heading', 'text', 'image', 'button', 'columns', 'feature_grid', 'card_grid', 'cta', 'events', 'form', 'donation_form', 'donation_progress', 'campaign_summary', 'divider', 'spacer', 'posts_list', 'html', 'transcript' ];

    /** @var array<int,string> */
    private const TEMPLATE_KEYS = [
        'default',
    ];

    /** @var array<string,string> */
    private const TEMPLATE_LABELS = [
        'default' => 'Default',
    ];

    /** @var array<string,string> */
    private const PAGE_TYPE_DEFAULT_TEMPLATE = [
        'homepage' => 'default',
        'page' => 'default',
        'post' => 'default',
    ];

    /** @var array<int,int> */
    private const COLUMN_WIDTHS = [ 25, 33, 50, 100 ];

    /** @return array<int,string> */
    public static function pageTypes(): array {
        return self::PAGE_TYPES;
    }

    /** @return array<int,string> */
    public static function sectionTypes(): array {
        return self::PAGE_SECTION_TYPES;
    }

    /** @return array<int,string> */
    public static function postSectionTypes(): array {
        return self::POST_SECTION_TYPES;
    }

    /** @return array<int,string> */
    public static function templateKeys(): array {
        return self::TEMPLATE_KEYS;
    }

    /**
     * @return array<int,array{value:string,label:string}>
     */
    public static function templateOptions(): array {
        $out = [];
        foreach ( self::TEMPLATE_KEYS as $key ) {
            $out[] = [
                'value' => $key,
                'label' => self::TEMPLATE_LABELS[ $key ] ?? strtoupper( $key ),
            ];
        }
        return $out;
    }

    public static function templateLabel( string $template_key ): string {
        $normalized = self::normalizeTemplateCandidate( $template_key );
        return self::TEMPLATE_LABELS[ $normalized ] ?? strtoupper( $normalized );
    }

    public static function isStructuredTemplateKey( string $template_key ): bool {
        return in_array( self::normalizeTemplateCandidate( $template_key ), self::templateKeys(), true );
    }

    public static function defaultTemplateForPageType( string $page_type ): string {
        $is_post = metis_key_clean( $page_type ) === 'post';
        $type = self::normalizePageType( $page_type, $is_post );
        return self::PAGE_TYPE_DEFAULT_TEMPLATE[ $type ] ?? 'default';
    }

    public static function resolveTemplateKey( string $raw_template_key, string $page_type = 'page' ): string {
        $template = self::normalizeTemplateCandidate( $raw_template_key );
        if ( in_array( $template, self::TEMPLATE_KEYS, true ) ) {
            return $template;
        }
        return self::defaultTemplateForPageType( $page_type );
    }

    /**
     * @param array<string,mixed> $options
     * @return array{layout_json:string,page_type:string,template_key:string,template_override:bool,hero:array<string,mixed>,sections:array<int,array<string,mixed>>}
     */
    public static function normalizeLayout( mixed $raw_layout, array $options = [] ): array {
        $is_post = ! empty( $options['is_post'] );
        $decoded = self::decodeLayout( $raw_layout );
        $meta = self::structuredMetaFromDecodedLayout( $decoded );

        $requested_page_type = isset( $options['page_type'] ) ? (string) $options['page_type'] : (string) ( $meta['page_type'] ?? '' );
        $set_as_homepage = ! empty( $options['set_as_homepage'] );
        $page_type = self::normalizePageType( $requested_page_type, $is_post, $set_as_homepage );

        $requested_template = isset( $options['template_key'] ) ? (string) $options['template_key'] : (string) ( $meta['template_key'] ?? '' );
        $template_key = self::normalizeTemplateKey( $requested_template, $page_type );

        $sections_source = isset( $meta['sections'] ) && is_array( $meta['sections'] )
            ? $meta['sections']
            : [];
        $sections = self::normalizeSections( $sections_source, $is_post );
        $hero_source = isset( $options['hero'] ) && is_array( $options['hero'] )
            ? $options['hero']
            : ( isset( $meta['hero'] ) && is_array( $meta['hero'] ) ? $meta['hero'] : [] );
        $hero = self::normalizeHero( $hero_source, $page_type === 'homepage' && ! $is_post );

        $layout = self::buildLayoutArray( $sections, $page_type, $template_key, $hero );

        return [
            'layout_json' => self::encodeJson( $layout ),
            'page_type' => $page_type,
            'template_key' => $template_key,
            'template_override' => $template_key !== self::defaultTemplateForPageType( $page_type ),
            'hero' => $hero,
            'sections' => $sections,
        ];
    }

    /**
     * @param array<string,mixed> $decoded
     * @return array<string,mixed>
     */
    public static function structuredMetaFromDecodedLayout( array $decoded ): array {
        $meta = isset( $decoded['editor_meta'] ) && is_array( $decoded['editor_meta'] ) ? $decoded['editor_meta'] : [];
        $structured = isset( $meta['structured_builder'] ) && is_array( $meta['structured_builder'] ) ? $meta['structured_builder'] : [];
        return $structured !== [] ? $structured : [];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function sectionsFromLayout( mixed $raw_layout, bool $is_post = false ): array {
        $decoded = self::decodeLayout( $raw_layout );
        $meta = self::structuredMetaFromDecodedLayout( $decoded );
        $sections = isset( $meta['sections'] ) && is_array( $meta['sections'] )
            ? $meta['sections']
            : [];
        return self::normalizeSections( $sections, $is_post );
    }

    /**
     * @return array{enabled:bool,style:string,headline:string,subtext:string,primary_cta_label:string,primary_cta_link:string,media_url:string}
     */
    public static function heroFromLayout( mixed $raw_layout, bool $is_homepage ): array {
        $decoded = self::decodeLayout( $raw_layout );
        $meta = self::structuredMetaFromDecodedLayout( $decoded );
        $hero = isset( $meta['hero'] ) && is_array( $meta['hero'] ) ? $meta['hero'] : [];
        return self::normalizeHero( $hero, $is_homepage );
    }

    /**
     * @param array<int,mixed> $sections
     * @return array<int,array<string,mixed>>
     */
    private static function normalizeSections( array $sections, bool $is_post ): array {
        $allowed_types = $is_post ? self::POST_SECTION_TYPES : self::PAGE_SECTION_TYPES;
        $normalized = [];
        foreach ( $sections as $index => $section ) {
            if ( ! is_array( $section ) ) {
                continue;
            }
            $normalized[] = self::normalizeSection( $section, (int) $index, $allowed_types );
            if ( count( $normalized ) >= 24 ) {
                break;
            }
        }

        if ( $normalized === [] ) {
            $normalized[] = self::defaultSection( $is_post );
        }

        return $normalized;
    }

    /**
     * @param array<string,mixed> $section
     * @param array<int,string>   $allowed_types
     * @return array<string,mixed>
     */
    private static function normalizeSection( array $section, int $index, array $allowed_types ): array {
        $id = isset( $section['id'] ) && is_scalar( $section['id'] )
            ? trim( (string) $section['id'] )
            : '';
        if ( $id === '' ) {
            $id = 'section_' . (string) $index;
        }

        $type = metis_key_clean( (string) ( $section['type'] ?? '' ) );
        if ( ! in_array( $type, $allowed_types, true ) ) {
            $type = 'text';
            $section['type'] = 'text';
            if ( ! isset( $section['content'] ) || ! is_array( $section['content'] ) ) {
                $section['content'] = [];
            }
        }

        $header = self::sanitizeText( (string) ( $section['header'] ?? '' ) );
        $subtext = self::sanitizeText( (string) ( $section['subtext'] ?? '' ) );
        $content = self::normalizeSectionContent( $type, isset( $section['content'] ) && is_array( $section['content'] ) ? $section['content'] : [] );

        $normalized = [
            'id' => $id,
            'type' => $type,
            'header' => $header !== '' ? $header : null,
            'subtext' => $subtext !== '' ? $subtext : null,
            'content' => $content,
            'order' => isset( $section['order'] ) ? max( 0, (int) $section['order'] ) : $index,
            'metadata' => self::normalizeSectionMetadata( isset( $section['metadata'] ) && is_array( $section['metadata'] ) ? $section['metadata'] : [] ),
        ];

        $settings = self::normalizeSectionSettings( isset( $section['settings'] ) && is_array( $section['settings'] ) ? $section['settings'] : [] );
        if ( $settings !== [] ) {
            $normalized['settings'] = $settings;
        }

        return $normalized;
    }

    /**
     * @param array<string,mixed> $settings
     * @return array<string,mixed>
     */
    private static function normalizeSectionSettings( array $settings ): array {
        $background = metis_key_clean( (string) ( $settings['background'] ?? 'default' ) );
        if ( ! in_array( $background, [ 'default', 'surface', 'muted', 'primary_tint', 'accent_tint' ], true ) ) {
            $background = 'default';
        }
        return $background === 'default' ? [] : [ 'background' => $background ];
    }

    /**
     * @param array<string,mixed> $metadata
     * @return array<string,mixed>
     */
    private static function normalizeSectionMetadata( array $metadata ): array {
        $out = [
            'created_at' => isset( $metadata['created_at'] ) && is_scalar( $metadata['created_at'] ) ? self::sanitizeText( (string) $metadata['created_at'] ) : '',
            'updated_at' => isset( $metadata['updated_at'] ) && is_scalar( $metadata['updated_at'] ) ? self::sanitizeText( (string) $metadata['updated_at'] ) : '',
        ];
        return array_filter( $out, static fn( mixed $value ): bool => $value !== '' );
    }

    /**
     * @param array<string,mixed> $content
     * @return array<string,mixed>
     */
    private static function normalizeSectionContent( string $type, array $content ): array {
        if ( $type === 'heading' ) {
            $level = metis_key_clean( (string) ( $content['level'] ?? 'h2' ) );
            if ( ! in_array( $level, [ 'h1', 'h2', 'h3', 'h4' ], true ) ) {
                $level = 'h2';
            }
            $align = metis_key_clean( (string) ( $content['align'] ?? 'left' ) );
            if ( ! in_array( $align, [ 'left', 'center', 'right' ], true ) ) {
                $align = 'left';
            }
            $vertical_align = metis_key_clean( (string) ( $content['vertical_align'] ?? 'top' ) );
            if ( ! in_array( $vertical_align, [ 'top', 'middle', 'bottom' ], true ) ) {
                $vertical_align = 'top';
            }
            $variant = metis_key_clean( (string) ( $content['variant'] ?? 'default' ) );
            if ( ! in_array( $variant, [ 'default', 'section_header' ], true ) ) {
                $variant = 'default';
            }
            return [
                'text' => self::sanitizeText( (string) ( $content['text'] ?? 'Heading' ) ),
                'level' => $variant === 'section_header' ? 'h1' : $level,
                'align' => $variant === 'section_header' ? 'center' : $align,
                'vertical_align' => $variant === 'section_header' ? 'middle' : $vertical_align,
                'variant' => $variant,
            ];
        }

        if ( $type === 'text' ) {
            $body = self::sanitizeRichText( (string) ( $content['body'] ?? '' ) );
            if ( $body === '' ) {
                $body = '<p></p>';
            }
            return [ 'body' => $body ];
        }

        if ( $type === 'image' ) {
            return [
                'src' => self::normalizeOptionalUrl( (string) ( $content['src'] ?? '' ) ),
                'alt' => self::sanitizeText( (string) ( $content['alt'] ?? '' ) ),
                'caption' => self::sanitizeText( (string) ( $content['caption'] ?? '' ) ),
                'width' => self::sanitizeImageDimension( $content['width'] ?? '' ),
                'height' => self::sanitizeImageDimension( $content['height'] ?? '' ),
            ];
        }

        if ( $type === 'button' ) {
            return [
                'label' => self::sanitizeText( (string) ( $content['label'] ?? 'Learn more' ) ),
                'url' => self::normalizeUrl( (string) ( $content['url'] ?? '#' ) ),
                'align' => in_array( metis_key_clean( (string) ( $content['align'] ?? 'left' ) ), [ 'left', 'center', 'right' ], true )
                    ? metis_key_clean( (string) ( $content['align'] ?? 'left' ) )
                    : 'left',
            ];
        }

        if ( $type === 'hero' ) {
            return [
                'title' => self::sanitizeText( (string) ( $content['title'] ?? 'Hero Title' ) ),
                'subtitle' => self::sanitizeText( (string) ( $content['subtitle'] ?? '' ) ),
                'cta_label' => self::sanitizeText( (string) ( $content['cta_label'] ?? 'Learn More' ) ),
                'cta_url' => self::normalizeUrl( (string) ( $content['cta_url'] ?? '#' ) ),
                'image_src' => self::normalizeOptionalUrl( (string) ( $content['image_src'] ?? '' ) ),
            ];
        }

        if ( $type === 'html' ) {
            return [
                'html' => self::sanitizeRichText( (string) ( $content['html'] ?? '' ) ),
            ];
        }

        if ( $type === 'transcript' ) {
            $source = self::sanitizeTranscriptSource( (string) ( $content['source'] ?? '' ) );
            $rows = self::normalizeTranscriptRows( isset( $content['rows'] ) && is_array( $content['rows'] ) ? $content['rows'] : [] );
            if ( $rows === [] && $source !== '' ) {
                $rows = self::parseTranscriptSource( $source );
            }
            if ( $source === '' && $rows !== [] ) {
                $source = self::transcriptSourceFromRows( $rows );
            }
            return [
                'source' => $source,
                'rows' => $rows,
            ];
        }

        if ( $type === 'columns' ) {
            $cols = isset( $content['columns'] ) && is_array( $content['columns'] ) ? $content['columns'] : [];
            $out = [];
            foreach ( $cols as $column ) {
                if ( ! is_array( $column ) ) {
                    continue;
                }
                $width = self::normalizeColumnWidth( $column['width'] ?? '50%' );
                $out[] = [
                    'width' => $width,
                    'body' => self::sanitizeRichText( (string) ( $column['body'] ?? '' ) ),
                ];
                if ( count( $out ) >= 4 ) {
                    break;
                }
            }
            if ( $out === [] ) {
                $out[] = [ 'width' => '50%', 'body' => '<p></p>' ];
                $out[] = [ 'width' => '50%', 'body' => '<p></p>' ];
            }
            return [ 'columns' => $out ];
        }

        if ( $type === 'feature_grid' || $type === 'card_grid' ) {
            $cols = (int) ( $content['columns'] ?? 3 );
            if ( ! in_array( $cols, [ 2, 3, 4 ], true ) ) {
                $cols = 3;
            }
            $items = isset( $content['items'] ) && is_array( $content['items'] ) ? $content['items'] : [];
            $out = [];
            foreach ( $items as $item ) {
                if ( ! is_array( $item ) ) {
                    continue;
                }
                $cta = isset( $item['cta'] ) && is_array( $item['cta'] ) ? $item['cta'] : [];
                $out[] = [
                    'icon' => self::sanitizeText( (string) ( $item['icon'] ?? '' ) ),
                    'title' => self::sanitizeText( (string) ( $item['title'] ?? '' ) ),
                    'text' => self::sanitizeText( (string) ( $item['text'] ?? '' ) ),
                    'cta' => [
                        'label' => self::sanitizeText( (string) ( $cta['label'] ?? '' ) ),
                        'url' => self::normalizeUrl( (string) ( $cta['url'] ?? '#' ) ),
                    ],
                ];
                if ( count( $out ) >= 16 ) {
                    break;
                }
            }
            if ( $out === [] ) {
                $out[] = [ 'icon' => '', 'title' => 'Feature', 'text' => '', 'cta' => [ 'label' => '', 'url' => '#' ] ];
            }
            return [ 'columns' => $cols, 'items' => $out ];
        }

        if ( $type === 'cta' ) {
            $layout = metis_key_clean( (string) ( $content['layout'] ?? 'single' ) );
            if ( ! in_array( $layout, [ 'single', 'split' ], true ) ) {
                $layout = 'single';
            }
            $items = isset( $content['items'] ) && is_array( $content['items'] ) ? $content['items'] : [];
            $out = [];
            foreach ( $items as $item ) {
                if ( ! is_array( $item ) ) {
                    continue;
                }
                $button = isset( $item['button'] ) && is_array( $item['button'] ) ? $item['button'] : [];
                $out[] = [
                    'title' => self::sanitizeText( (string) ( $item['title'] ?? '' ) ),
                    'text' => self::sanitizeText( (string) ( $item['text'] ?? '' ) ),
                    'button' => [
                        'label' => self::sanitizeText( (string) ( $button['label'] ?? '' ) ),
                        'url' => self::normalizeUrl( (string) ( $button['url'] ?? '#' ) ),
                    ],
                ];
                if ( count( $out ) >= 2 ) {
                    break;
                }
            }
            if ( $out === [] ) {
                $out[] = [ 'title' => 'Call to Action', 'text' => '', 'button' => [ 'label' => 'Learn More', 'url' => '#' ] ];
            }
            if ( $layout === 'single' ) {
                $out = [ $out[0] ];
            }
            return [ 'layout' => $layout, 'items' => $out ];
        }

        if ( $type === 'events' ) {
            $source = metis_key_clean( (string) ( $content['source'] ?? 'calendar' ) );
            if ( ! in_array( $source, [ 'calendar', 'manual' ], true ) ) {
                $source = 'calendar';
            }
            $limit = (int) ( $content['limit'] ?? 5 );
            $limit = max( 1, min( 50, $limit ) );
            return [ 'source' => $source, 'limit' => $limit ];
        }

        if ( $type === 'form' ) {
            return [
                'form_id' => self::sanitizeText( (string) ( $content['form_id'] ?? '' ) ),
                'submit_label' => self::sanitizeText( (string) ( $content['submit_label'] ?? 'Submit' ) ),
            ];
        }

        if ( $type === 'donation_form' ) {
            $mode = metis_key_clean( (string) ( $content['mode'] ?? 'both' ) );
            if ( ! in_array( $mode, [ 'one_time', 'monthly', 'both' ], true ) ) {
                $mode = 'both';
            }
            return [
                'campaign_id' => self::sanitizeText( (string) ( $content['campaign_id'] ?? '' ) ),
                'preset_amounts' => self::sanitizePresetAmounts( $content['preset_amounts'] ?? [] ),
                'allow_custom_amount' => self::sanitizeBoolean( $content['allow_custom_amount'] ?? true ),
                'mode' => $mode,
                'show_name' => self::sanitizeBoolean( $content['show_name'] ?? true ),
                'show_email' => self::sanitizeBoolean( $content['show_email'] ?? true ),
                'show_phone' => self::sanitizeBoolean( $content['show_phone'] ?? false ),
            ];
        }

        if ( $type === 'donation_progress' ) {
            return [
                'campaign_id' => self::sanitizeText( (string) ( $content['campaign_id'] ?? '' ) ),
                'goal_amount' => self::sanitizeDecimalString( $content['goal_amount'] ?? '' ),
                'raised_amount' => self::sanitizeDecimalString( $content['raised_amount'] ?? '' ),
                'percent' => self::sanitizePercentString( $content['percent'] ?? '' ),
            ];
        }

        if ( $type === 'campaign_summary' ) {
            return [
                'campaign_id' => self::sanitizeText( (string) ( $content['campaign_id'] ?? '' ) ),
                'title' => self::sanitizeText( (string) ( $content['title'] ?? '' ) ),
                'content' => self::sanitizeRichText( (string) ( $content['content'] ?? '' ) ),
                'image' => self::normalizeOptionalUrl( (string) ( $content['image'] ?? '' ) ),
            ];
        }

        if ( $type === 'divider' ) {
            $style = metis_key_clean( (string) ( $content['style'] ?? 'solid' ) );
            if ( ! in_array( $style, [ 'solid', 'dashed', 'dotted' ], true ) ) {
                $style = 'solid';
            }
            return [
                'label' => self::sanitizeText( (string) ( $content['label'] ?? '' ) ),
                'style' => $style,
            ];
        }

        if ( $type === 'spacer' ) {
            $height = metis_key_clean( (string) ( $content['height'] ?? 'medium' ) );
            if ( ! in_array( $height, [ 'small', 'medium', 'large' ], true ) ) {
                $height = 'medium';
            }
            return [ 'height' => $height ];
        }

        if ( $type === 'posts_list' ) {
            $source = metis_key_clean( (string) ( $content['source'] ?? 'this_page' ) );
            if ( ! in_array( $source, [ 'this_page', 'specific_page' ], true ) ) {
                $source = 'this_page';
            }
            $specific_page = (int) ( $content['specific_page'] ?? 0 );
            if ( $specific_page < 1 ) {
                $specific_page = 0;
            }
            if ( $source !== 'specific_page' ) {
                $specific_page = 0;
            }
            $limit = (int) ( $content['limit'] ?? 5 );
            $limit = max( 1, min( 50, $limit ) );
            $category_ids = array_values( array_unique( array_filter( array_map( 'intval', is_array( $content['category_ids'] ?? null ) ? $content['category_ids'] : [] ), static fn( int $id ): bool => $id > 0 ) ) );
            $sort = metis_key_clean( (string) ( $content['sort'] ?? 'latest' ) );
            if ( $sort !== 'latest' ) {
                $sort = 'latest';
            }
            return [
                'source' => $source,
                'specific_page' => $specific_page,
                'category_ids' => $category_ids,
                'limit' => $limit,
                'sort' => $sort,
            ];
        }

        return [ 'body' => '<p></p>' ];
    }

    private static function normalizeColumnWidth( mixed $raw ): string {
        $value = trim( (string) $raw );
        $value = str_replace( '%', '', $value );
        $width = (int) $value;
        if ( ! in_array( $width, self::COLUMN_WIDTHS, true ) ) {
            $width = 50;
        }
        return (string) $width . '%';
    }

    private static function normalizePageType( string $raw, bool $is_post, bool $set_as_homepage = false ): string {
        if ( $is_post ) {
            return 'post';
        }
        if ( $set_as_homepage ) {
            return 'homepage';
        }
        $type = metis_key_clean( $raw );
        if ( ! in_array( $type, [ 'homepage', 'page' ], true ) ) {
            $type = 'page';
        }
        return $type;
    }

    private static function normalizeTemplateKey( string $raw, string $page_type ): string {
        return self::resolveTemplateKey( $raw, $page_type );
    }

    private static function normalizeTemplateCandidate( string $template_key ): string {
        $candidate = metis_key_clean( $template_key );
        if ( $candidate === '' ) {
            return '';
        }
        return $candidate;
    }

    /**
     * @param array<int,array<string,mixed>> $sections
     * @param array<string,mixed> $hero
     * @return array<string,mixed>
     */
    private static function buildLayoutArray( array $sections, string $page_type, string $template_key, array $hero ): array {
        $layout_sections = [];

        foreach ( $sections as $index => $section ) {
            $id = isset( $section['id'] ) ? (string) $section['id'] : ( 'section_' . (string) $index );
            $modules = self::sectionModules( $section, $id );
            $layout_sections[] = [
                'id' => $id,
                'type' => (string) ( $section['type'] ?? 'text' ),
                'order' => $index,
                'metadata' => isset( $section['metadata'] ) && is_array( $section['metadata'] ) ? $section['metadata'] : [],
                'columns' => [
                    [
                        'id' => $id . '_col_0',
                        'width' => 1.0,
                        'modules' => $modules,
                        'settings' => [],
                    ],
                ],
                'sections' => [],
                'settings' => [
                    'max_width' => '100%',
                    'align' => 'full',
                ],
            ];
        }

        return [
            'version' => 2,
            'editor_meta' => [
                'builder' => 'structured_v1',
                'saved_at' => gmdate( 'c' ),
                'section_count' => count( $sections ),
                'structured_builder' => [
                    'version' => 1,
                    'page_type' => $page_type,
                    'template_key' => $template_key,
                    'template_override' => $template_key !== self::defaultTemplateForPageType( $page_type ),
                    'hero' => $hero,
                    'sections' => $sections,
                ],
            ],
            'sections' => $layout_sections,
        ];
    }

    /**
     * @param array<string,mixed> $hero
     * @return array{enabled:bool,style:string,headline:string,subtext:string,primary_cta_label:string,primary_cta_link:string,media_url:string}
     */
    private static function normalizeHero( array $hero, bool $is_homepage ): array {
        $enabled = ! empty( $hero['enabled'] ) && $is_homepage;
        $style = metis_key_clean( (string) ( $hero['style'] ?? $hero['type'] ?? 'split' ) );
        if ( ! in_array( $style, [ 'split', 'centered', 'overlay' ], true ) ) {
            $style = 'split';
        }

        return [
            'enabled' => $enabled,
            'style' => $style,
            'headline' => self::sanitizeText( (string) ( $hero['headline'] ?? '' ) ),
            'subtext' => self::sanitizeText( (string) ( $hero['subtext'] ?? '' ) ),
            'primary_cta_label' => self::sanitizeText( (string) ( $hero['primary_cta_label'] ?? '' ) ),
            'primary_cta_link' => self::normalizeUrl( (string) ( $hero['primary_cta_link'] ?? '#' ) ),
            'media_url' => self::normalizeOptionalUrl( (string) ( $hero['media_url'] ?? '' ) ),
        ];
    }

    /**
     * @param array<string,mixed> $section
     * @return array<int,array<string,mixed>>
     */
    private static function sectionModules( array $section, string $section_id ): array {
        $modules = [];
        $header = isset( $section['header'] ) ? trim( (string) $section['header'] ) : '';
        $subtext = isset( $section['subtext'] ) ? trim( (string) $section['subtext'] ) : '';

        if ( $header !== '' ) {
            $modules[] = [
                'id' => $section_id . '_header',
                'type' => 'heading',
                'data' => [ 'content' => $header, 'level' => 'h2' ],
                'style' => [],
            ];
        }
        if ( $subtext !== '' ) {
            $modules[] = [
                'id' => $section_id . '_subtext',
                'type' => 'text',
                'data' => [ 'content' => '<p>' . metis_escape_html( $subtext ) . '</p>', 'tag' => 'div' ],
                'style' => [],
            ];
        }

        $type = (string) ( $section['type'] ?? 'text' );
        $content = isset( $section['content'] ) && is_array( $section['content'] ) ? $section['content'] : [];

        if ( $type === 'heading' ) {
            $modules[] = [
                'id' => $section_id . '_heading',
                'type' => 'heading',
                'data' => [
                    'content' => (string) ( $content['text'] ?? 'Heading' ),
                    'level' => (string) ( $content['level'] ?? 'h2' ),
                    'align' => (string) ( $content['align'] ?? 'left' ),
                    'vertical_align' => (string) ( $content['vertical_align'] ?? 'top' ),
                    'variant' => (string) ( $content['variant'] ?? 'default' ),
                ],
                'style' => [],
            ];
            return $modules;
        }

        if ( $type === 'text' ) {
            $modules[] = [ 'id' => $section_id . '_text', 'type' => 'text', 'data' => [ 'content' => (string) ( $content['body'] ?? '<p></p>' ), 'tag' => 'div' ], 'style' => [] ];
            return $modules;
        }

        if ( $type === 'image' ) {
            $modules[] = [
                'id' => $section_id . '_image',
                'type' => 'image',
                'data' => [
                    'src' => (string) ( $content['src'] ?? '' ),
                    'alt' => (string) ( $content['alt'] ?? '' ),
                ],
                'style' => [],
            ];
            if ( trim( (string) ( $content['caption'] ?? '' ) ) !== '' ) {
                $modules[] = [
                    'id' => $section_id . '_caption',
                    'type' => 'text',
                    'data' => [ 'content' => '<p>' . metis_escape_html( (string) $content['caption'] ) . '</p>', 'tag' => 'div' ],
                    'style' => [],
                ];
            }
            return $modules;
        }

        if ( $type === 'button' ) {
            $align = metis_key_clean( (string) ( $content['align'] ?? 'left' ) );
            $modules[] = [
                'id' => $section_id . '_button',
                'type' => 'button_group',
                'data' => [
                    'align' => in_array( $align, [ 'left', 'center', 'right' ], true ) ? $align : 'left',
                    'buttons' => [
                        [
                            'label' => (string) ( $content['label'] ?? 'Learn more' ),
                            'url' => self::normalizeUrl( (string) ( $content['url'] ?? '#' ) ),
                            'bgcolor' => '#485bc7',
                            'color' => '#ffffff',
                        ],
                    ],
                ],
                'style' => [],
            ];
            return $modules;
        }

        if ( $type === 'hero' ) {
            $modules[] = [
                'id' => $section_id . '_hero',
                'type' => 'hero',
                'data' => [
                    'title' => (string) ( $content['title'] ?? 'Hero Title' ),
                    'subtitle' => (string) ( $content['subtitle'] ?? '' ),
                    'cta_label' => (string) ( $content['cta_label'] ?? 'Learn More' ),
                    'cta_url' => self::normalizeUrl( (string) ( $content['cta_url'] ?? '#' ) ),
                    'image_src' => (string) ( $content['image_src'] ?? '' ),
                ],
                'style' => [],
            ];
            return $modules;
        }

        if ( $type === 'html' ) {
            $modules[] = [
                'id' => $section_id . '_html',
                'type' => 'text',
                'data' => [ 'content' => (string) ( $content['html'] ?? '' ), 'tag' => 'div' ],
                'style' => [],
            ];
            return $modules;
        }

        if ( $type === 'columns' ) {
            $columns = isset( $content['columns'] ) && is_array( $content['columns'] ) ? $content['columns'] : [];
            $ratios = [];
            $col_blocks = [];
            foreach ( $columns as $i => $column ) {
                if ( ! is_array( $column ) ) {
                    continue;
                }
                $width = self::normalizeColumnWidth( $column['width'] ?? '50%' );
                $ratio = (float) str_replace( '%', '', $width );
                if ( $ratio <= 0 ) {
                    $ratio = 50.0;
                }
                $ratios[] = $ratio;
                $col_blocks[] = [
                    [
                        'id' => $section_id . '_col_' . (string) $i,
                        'type' => 'text',
                        'data' => [ 'content' => (string) ( $column['body'] ?? '<p></p>' ), 'tag' => 'div' ],
                        'style' => [],
                    ],
                ];
            }
            if ( $col_blocks === [] ) {
                $col_blocks = [ [ [ 'id' => $section_id . '_col_0', 'type' => 'text', 'data' => [ 'content' => '<p></p>', 'tag' => 'div' ], 'style' => [] ] ] ];
                $ratios = [ 100.0 ];
            }
            $modules[] = [
                'id' => $section_id . '_columns',
                'type' => 'grid',
                'data' => [
                    'columns' => count( $col_blocks ),
                    'ratios' => $ratios,
                    'gap' => '24px',
                    'col_blocks' => $col_blocks,
                ],
                'style' => [],
            ];
            return $modules;
        }

        if ( $type === 'feature_grid' || $type === 'card_grid' ) {
            $cols = (int) ( $content['columns'] ?? 3 );
            if ( ! in_array( $cols, [ 2, 3, 4 ], true ) ) {
                $cols = 3;
            }
            $items = isset( $content['items'] ) && is_array( $content['items'] ) ? $content['items'] : [];
            $col_blocks = [];
            $ratios = [];
            foreach ( $items as $i => $item ) {
                if ( ! is_array( $item ) ) {
                    continue;
                }
                $item_modules = [];
                $icon = trim( (string) ( $item['icon'] ?? '' ) );
                if ( $icon !== '' ) {
                    $item_modules[] = [ 'id' => $section_id . '_feature_icon_' . (string) $i, 'type' => 'text', 'data' => [ 'content' => '<p>' . metis_escape_html( $icon ) . '</p>', 'tag' => 'div' ], 'style' => [] ];
                }
                $title = trim( (string) ( $item['title'] ?? '' ) );
                if ( $title !== '' ) {
                    $item_modules[] = [ 'id' => $section_id . '_feature_title_' . (string) $i, 'type' => 'heading', 'data' => [ 'content' => $title, 'level' => 'h3' ], 'style' => [] ];
                }
                $text = trim( (string) ( $item['text'] ?? '' ) );
                if ( $text !== '' ) {
                    $item_modules[] = [ 'id' => $section_id . '_feature_text_' . (string) $i, 'type' => 'text', 'data' => [ 'content' => '<p>' . metis_escape_html( $text ) . '</p>', 'tag' => 'div' ], 'style' => [] ];
                }
                $cta = isset( $item['cta'] ) && is_array( $item['cta'] ) ? $item['cta'] : [];
                $label = trim( (string) ( $cta['label'] ?? '' ) );
                if ( $label !== '' ) {
                    $item_modules[] = [
                        'id' => $section_id . '_feature_cta_' . (string) $i,
                        'type' => 'button',
                        'data' => [
                            'label' => $label,
                            'url' => self::normalizeUrl( (string) ( $cta['url'] ?? '#' ) ),
                            'bgcolor' => '#485bc7',
                            'color' => '#ffffff',
                        ],
                        'style' => [],
                    ];
                }
                if ( $item_modules === [] ) {
                    $item_modules[] = [ 'id' => $section_id . '_feature_empty_' . (string) $i, 'type' => 'text', 'data' => [ 'content' => '<p></p>', 'tag' => 'div' ], 'style' => [] ];
                }
                $col_blocks[] = $item_modules;
                $ratios[] = 100.0 / (float) $cols;
            }
            while ( count( $col_blocks ) < $cols ) {
                $i = count( $col_blocks );
                $col_blocks[] = [ [ 'id' => $section_id . '_feature_fill_' . (string) $i, 'type' => 'text', 'data' => [ 'content' => '<p></p>', 'tag' => 'div' ], 'style' => [] ] ];
                $ratios[] = 100.0 / (float) $cols;
            }
            $modules[] = [
                'id' => $section_id . '_feature_grid',
                'type' => 'grid',
                'data' => [
                    'columns' => $cols,
                    'ratios' => $ratios,
                    'gap' => '24px',
                    'col_blocks' => $col_blocks,
                ],
                'style' => [],
            ];
            return $modules;
        }

        if ( $type === 'cta' ) {
            $layout = metis_key_clean( (string) ( $content['layout'] ?? 'single' ) );
            $items = isset( $content['items'] ) && is_array( $content['items'] ) ? $content['items'] : [];
            if ( $layout === 'split' && count( $items ) > 1 ) {
                $col_blocks = [];
                $ratios = [];
                foreach ( $items as $i => $item ) {
                    if ( ! is_array( $item ) ) {
                        continue;
                    }
                    $btn = isset( $item['button'] ) && is_array( $item['button'] ) ? $item['button'] : [];
                    $col_blocks[] = [
                        [
                            'id' => $section_id . '_cta_split_' . (string) $i,
                            'type' => 'cta',
                            'data' => [
                                'title' => (string) ( $item['title'] ?? 'Call to Action' ),
                                'content' => (string) ( $item['text'] ?? '' ),
                                'button_label' => (string) ( $btn['label'] ?? 'Learn More' ),
                                'button_url' => self::normalizeUrl( (string) ( $btn['url'] ?? '#' ) ),
                            ],
                            'style' => [],
                        ],
                    ];
                    $ratios[] = 50.0;
                }
                $modules[] = [
                    'id' => $section_id . '_cta_split_grid',
                    'type' => 'grid',
                    'data' => [
                        'columns' => count( $col_blocks ),
                        'ratios' => $ratios,
                        'gap' => '24px',
                        'col_blocks' => $col_blocks,
                    ],
                    'style' => [],
                ];
                return $modules;
            }

            $first = isset( $items[0] ) && is_array( $items[0] ) ? $items[0] : [ 'title' => 'Call to Action', 'text' => '', 'button' => [ 'label' => 'Learn More', 'url' => '#' ] ];
            $button = isset( $first['button'] ) && is_array( $first['button'] ) ? $first['button'] : [];
            $modules[] = [
                'id' => $section_id . '_cta',
                'type' => 'cta',
                'data' => [
                    'title' => (string) ( $first['title'] ?? 'Call to Action' ),
                    'content' => (string) ( $first['text'] ?? '' ),
                    'button_label' => (string) ( $button['label'] ?? 'Learn More' ),
                    'button_url' => self::normalizeUrl( (string) ( $button['url'] ?? '#' ) ),
                ],
                'style' => [],
            ];
            return $modules;
        }

        if ( $type === 'events' ) {
            $modules[] = [
                'id' => $section_id . '_events',
                'type' => 'events_block',
                'data' => [
                    'count' => max( 1, min( 50, (int) ( $content['limit'] ?? 5 ) ) ),
                ],
                'style' => [],
            ];
            return $modules;
        }

        if ( $type === 'spacer' ) {
            $height = metis_key_clean( (string) ( $content['height'] ?? 'medium' ) );
            $px = match ( $height ) {
                'small' => 24,
                'large' => 72,
                default => 48,
            };
            $modules[] = [
                'id' => $section_id . '_spacer',
                'type' => 'spacer',
                'data' => [ 'height' => $px ],
                'style' => [],
            ];
            return $modules;
        }

        if ( $type === 'posts_list' ) {
            $source = metis_key_clean( (string) ( $content['source'] ?? 'this_page' ) );
            if ( ! in_array( $source, [ 'this_page', 'specific_page' ], true ) ) {
                $source = 'this_page';
            }
            $modules[] = [
                'id' => $section_id . '_posts_list',
                'type' => 'post_list',
                'data' => [
                    'count' => max( 1, min( 50, (int) ( $content['limit'] ?? 5 ) ) ),
                    'layout' => 'list',
                    'show_excerpt' => true,
                    'show_date' => true,
                    'source' => $source,
                    'parent_page_id' => max( 0, (int) ( $content['specific_page'] ?? 0 ) ),
                    'category_ids' => array_values( array_unique( array_filter( array_map( 'intval', is_array( $content['category_ids'] ?? null ) ? $content['category_ids'] : [] ), static fn( int $id ): bool => $id > 0 ) ) ),
                    'sort' => 'latest',
                ],
                'style' => [],
            ];
            return $modules;
        }

        if ( $type === 'form' ) {
            $modules[] = [
                'id' => $section_id . '_form',
                'type' => 'form',
                'data' => [
                    'form_id' => (string) ( $content['form_id'] ?? '' ),
                    'submit_label' => (string) ( $content['submit_label'] ?? 'Submit' ),
                ],
                'style' => [],
            ];
            return $modules;
        }

        if ( $type === 'donation_form' ) {
            $modules[] = [
                'id' => $section_id . '_donation_form',
                'type' => 'donation_form_block',
                'data' => [
                    'campaign_id' => (string) ( $content['campaign_id'] ?? '' ),
                    'preset_amounts' => self::sanitizePresetAmounts( $content['preset_amounts'] ?? [] ),
                    'allow_custom_amount' => self::sanitizeBoolean( $content['allow_custom_amount'] ?? true ),
                    'mode' => (string) ( $content['mode'] ?? 'both' ),
                    'show_name' => self::sanitizeBoolean( $content['show_name'] ?? true ),
                    'show_email' => self::sanitizeBoolean( $content['show_email'] ?? true ),
                    'show_phone' => self::sanitizeBoolean( $content['show_phone'] ?? false ),
                ],
                'style' => [],
            ];
            return $modules;
        }

        if ( $type === 'donation_progress' ) {
            $modules[] = [
                'id' => $section_id . '_donation_progress',
                'type' => 'progress_bar_block',
                'data' => [
                    'campaign_id' => (string) ( $content['campaign_id'] ?? '' ),
                    'goal_amount' => self::sanitizeDecimalString( $content['goal_amount'] ?? '' ),
                    'raised_amount' => self::sanitizeDecimalString( $content['raised_amount'] ?? '' ),
                    'percent' => self::sanitizePercentString( $content['percent'] ?? '' ),
                ],
                'style' => [],
            ];
            return $modules;
        }

        if ( $type === 'campaign_summary' ) {
            $modules[] = [
                'id' => $section_id . '_campaign_summary',
                'type' => 'campaign_description_block',
                'data' => [
                    'campaign_id' => (string) ( $content['campaign_id'] ?? '' ),
                    'title' => (string) ( $content['title'] ?? '' ),
                    'content' => (string) ( $content['content'] ?? '' ),
                    'image' => (string) ( $content['image'] ?? '' ),
                ],
                'style' => [],
            ];
            return $modules;
        }

        if ( $type === 'divider' ) {
            $modules[] = [
                'id' => $section_id . '_divider',
                'type' => 'divider',
                'data' => [
                    'label' => (string) ( $content['label'] ?? '' ),
                    'style' => (string) ( $content['style'] ?? 'solid' ),
                ],
                'style' => [],
            ];
            return $modules;
        }

        $modules[] = [ 'id' => $section_id . '_fallback', 'type' => 'text', 'data' => [ 'content' => '<p></p>', 'tag' => 'div' ], 'style' => [] ];
        return $modules;
    }

    private static function sanitizeText( string $value ): string {
        return metis_text_clean( trim( self::repairMojibakeText( $value ) ) );
    }

    private static function plainTextFromHtml( string $value ): string {
        $raw = trim( $value );
        if ( $raw === '' ) {
            return '';
        }
        $decoded = html_entity_decode( $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $text = trim( strip_tags( $decoded ) );
        return metis_text_clean( self::repairMojibakeText( $text ) );
    }

    private static function sanitizeRichText( string $value ): string {
        return self::sanitizeRichTextFragment( $value );
    }

    private static function sanitizeRichTextFragment( string $html ): string {
        $raw = trim( $html );
        if ( $raw === '' ) {
            return '';
        }
        if ( ! class_exists( \DOMDocument::class ) ) {
            $safe = function_exists( 'metis_runtime_kses_post' )
                ? (string) metis_runtime_kses_post( $raw )
                : strip_tags( $raw, '<p><br><strong><b><em><i><u><ul><ol><li><a><h1><h2><h3><h4><h5><h6><blockquote><span><div><figure><figcaption><img><table><thead><tbody><tr><th><td><hr><pre><code>' );
            return self::repairPublicHtmlText( $safe );
        }

        $previous = libxml_use_internal_errors( true );
        $document = new \DOMDocument( '1.0', 'UTF-8' );
        $document->loadHTML( '<?xml encoding="UTF-8"><div id="metis-rich-root">' . $raw . '</div>', LIBXML_HTML_NODEFDTD | LIBXML_HTML_NOIMPLIED );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );

        $root = $document->getElementById( 'metis-rich-root' );
        if ( ! $root instanceof \DOMElement ) {
            return '';
        }
        self::sanitizeRichTextNode( $root );

        $safe = '';
        foreach ( iterator_to_array( $root->childNodes ) as $child ) {
            $safe .= $document->saveHTML( $child ) ?: '';
        }
        return self::repairPublicHtmlText( $safe );
    }

    private static function sanitizeRichTextNode( \DOMNode $node ): void {
        foreach ( iterator_to_array( $node->childNodes ) as $child ) {
            if ( $child->nodeType === XML_COMMENT_NODE ) {
                $node->removeChild( $child );
                continue;
            }
            if ( $child instanceof \DOMElement ) {
                $tag = strtolower( $child->tagName );
                if ( in_array( $tag, [ 'script', 'style', 'iframe', 'object', 'embed', 'svg', 'math' ], true ) ) {
                    $node->removeChild( $child );
                    continue;
                }
                self::sanitizeRichTextNode( $child );
                if ( ! in_array( $tag, self::allowedRichTextTags(), true ) ) {
                    while ( $child->firstChild ) {
                        $node->insertBefore( $child->firstChild, $child );
                    }
                    $node->removeChild( $child );
                    continue;
                }
                self::sanitizeRichTextAttributes( $child, $tag );
            }
        }
    }

    /** @return array<int,string> */
    private static function allowedRichTextTags(): array {
        return [ 'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'ul', 'ol', 'li', 'a', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'span', 'div', 'figure', 'figcaption', 'img', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'hr', 'pre', 'code' ];
    }

    private static function sanitizeRichTextAttributes( \DOMElement $element, string $tag ): void {
        foreach ( iterator_to_array( $element->attributes ) as $attribute ) {
            $name = strtolower( $attribute->name );
            $value = trim( (string) $attribute->value );
            $keep = false;

            if ( $name === 'class' ) {
                $value = self::sanitizeRichTextClassList( $value );
                $keep = $value !== '';
            } elseif ( $name === 'style' ) {
                $value = self::sanitizeRichTextStyle( $value );
                $keep = $value !== '';
            } elseif ( $tag === 'a' && $name === 'href' ) {
                $keep = self::isSafeRichTextUrl( $value );
            } elseif ( $tag === 'a' && $name === 'target' ) {
                $keep = in_array( $value, [ '_blank', '_self' ], true );
            } elseif ( $tag === 'a' && $name === 'rel' ) {
                $value = preg_replace( '/[^a-z\s_-]/i', '', $value ) ?: '';
                $keep = $value !== '';
            } elseif ( $tag === 'img' && $name === 'src' ) {
                $keep = self::isSafeRichTextUrl( $value );
            } elseif ( in_array( $name, [ 'alt', 'title' ], true ) && in_array( $tag, [ 'a', 'img', 'figure', 'figcaption' ], true ) ) {
                $value = trim( strip_tags( $value ) );
                $keep = $value !== '';
            } elseif ( in_array( $name, [ 'width', 'height' ], true ) && $tag === 'img' ) {
                $value = preg_replace( '/[^0-9]/', '', $value ) ?: '';
                $keep = $value !== '' && (int) $value > 0 && (int) $value <= 4000;
            } elseif ( in_array( $name, [ 'colspan', 'rowspan' ], true ) && in_array( $tag, [ 'td', 'th' ], true ) ) {
                $value = preg_replace( '/[^0-9]/', '', $value ) ?: '';
                $keep = $value !== '' && (int) $value > 0 && (int) $value <= 20;
            } elseif ( $name === 'scope' && $tag === 'th' ) {
                $keep = in_array( $value, [ 'col', 'row', 'colgroup', 'rowgroup' ], true );
            }

            if ( $keep ) {
                $element->setAttribute( $name, $value );
            } else {
                $element->removeAttribute( $attribute->name );
            }
        }
    }

    private static function sanitizeRichTextClassList( string $classes ): string {
        $safe = [];
        foreach ( preg_split( '/\s+/', $classes ) ?: [] as $class ) {
            $class = trim( $class );
            if ( $class === '' ) {
                continue;
            }
            if ( preg_match( '/^metis-text-(size|color|weight|align)-[a-z0-9_-]+$/i', $class ) === 1 || preg_match( '/^metis-inline-(image|divider)$/i', $class ) === 1 || in_array( $class, [ 'is-small', 'is-medium', 'is-large', 'is-full' ], true ) ) {
                $safe[] = $class;
            }
        }
        return implode( ' ', array_values( array_unique( $safe ) ) );
    }

    private static function sanitizeRichTextStyle( string $style ): string {
        $safe = [];
        foreach ( explode( ';', $style ) as $rule ) {
            $parts = explode( ':', $rule, 2 );
            if ( count( $parts ) !== 2 ) {
                continue;
            }
            $property = strtolower( trim( $parts[0] ) );
            $value = trim( $parts[1] );
            if ( $property === 'color' && preg_match( '/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', $value ) === 1 ) {
                $safe[] = 'color: ' . strtolower( $value );
            } elseif ( $property === 'font-weight' && preg_match( '/^(normal|bold|[1-9]00)$/i', $value ) === 1 ) {
                $safe[] = 'font-weight: ' . strtolower( $value );
            } elseif ( $property === 'font-style' && in_array( strtolower( $value ), [ 'normal', 'italic' ], true ) ) {
                $safe[] = 'font-style: ' . strtolower( $value );
            } elseif ( $property === 'text-decoration' && in_array( strtolower( $value ), [ 'none', 'underline', 'line-through' ], true ) ) {
                $safe[] = 'text-decoration: ' . strtolower( $value );
            } elseif ( $property === 'text-align' && in_array( strtolower( $value ), [ 'left', 'center', 'right' ], true ) ) {
                $safe[] = 'text-align: ' . strtolower( $value );
            }
        }
        return implode( '; ', $safe );
    }

    private static function isSafeRichTextUrl( string $value ): bool {
        if ( $value === '' ) {
            return false;
        }
        return preg_match( '#^(https?:|mailto:|tel:|/|#)#i', $value ) === 1;
    }

    private static function repairPublicHtmlText( string $html ): string {
        if ( $html === '' ) {
            return '';
        }
        if ( strpos( $html, '<' ) === false ) {
            return self::repairMojibakeText( $html );
        }
        return preg_replace_callback(
            '/>([^<]+)</u',
            static function ( array $matches ): string {
                return '>' . self::repairMojibakeText( (string) ( $matches[1] ?? '' ) ) . '<';
            },
            $html
        ) ?? self::repairMojibakeText( $html );
    }

    private static function sanitizeImageDimension( mixed $value ): string {
        $raw = is_scalar( $value ) ? trim( (string) $value ) : '';
        if ( $raw === '' ) {
            return '';
        }
        $number = (int) preg_replace( '/[^0-9]/', '', $raw );
        if ( $number < 1 || $number > 4000 ) {
            return '';
        }
        return (string) $number;
    }

    /**
     * @return array<int,float|int>
     */
    private static function sanitizePresetAmounts( mixed $raw ): array {
        $source = [];
        if ( is_array( $raw ) ) {
            $source = $raw;
        } elseif ( is_scalar( $raw ) ) {
            $source = preg_split( '/[,\s]+/', (string) $raw ) ?: [];
        }

        $amounts = [];
        foreach ( $source as $value ) {
            if ( ! is_scalar( $value ) || ! is_numeric( (string) $value ) ) {
                continue;
            }
            $amount = round( (float) $value, 2 );
            if ( $amount <= 0 || $amount > 1000000 ) {
                continue;
            }
            $amounts[] = floor( $amount ) === $amount ? (int) $amount : $amount;
            if ( count( $amounts ) >= 8 ) {
                break;
            }
        }

        return $amounts !== [] ? array_values( array_unique( $amounts, SORT_REGULAR ) ) : [ 25, 50, 100 ];
    }

    private static function sanitizeBoolean( mixed $value ): bool {
        if ( is_bool( $value ) ) {
            return $value;
        }
        if ( is_int( $value ) || is_float( $value ) ) {
            return (int) $value === 1;
        }
        $raw = strtolower( trim( (string) $value ) );
        return in_array( $raw, [ '1', 'true', 'yes', 'on' ], true );
    }

    private static function sanitizeDecimalString( mixed $value ): string {
        if ( ! is_scalar( $value ) ) {
            return '';
        }
        $raw = trim( (string) $value );
        if ( $raw === '' || ! is_numeric( $raw ) ) {
            return '';
        }
        $number = max( 0.0, min( 1000000000.0, (float) $raw ) );
        return rtrim( rtrim( number_format( $number, 2, '.', '' ), '0' ), '.' );
    }

    private static function sanitizePercentString( mixed $value ): string {
        if ( ! is_scalar( $value ) ) {
            return '';
        }
        $raw = trim( (string) $value );
        if ( $raw === '' || ! is_numeric( $raw ) ) {
            return '';
        }
        $number = max( 0.0, min( 100.0, (float) $raw ) );
        return rtrim( rtrim( number_format( $number, 2, '.', '' ), '0' ), '.' );
    }

    private static function sanitizeTranscriptSource( string $value ): string {
        $value = self::repairMojibakeText( $value );
        $value = str_replace( [ "\r\n", "\r" ], "\n", $value );
        $lines = array_map(
            static fn( string $line ): string => trim( preg_replace( '/[ \t]+/', ' ', $line ) ?? $line ),
            explode( "\n", $value )
        );

        return trim( implode( "\n", $lines ) );
    }

    /**
     * @param array<int,mixed> $rows
     * @return array<int,array{type:string,speaker?:string,text:string}>
     */
    private static function normalizeTranscriptRows( array $rows ): array {
        $normalized = [];
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }

            $type = metis_key_clean( (string) ( $row['type'] ?? 'message' ) );
            if ( ! in_array( $type, [ 'message', 'cue' ], true ) ) {
                $type = 'message';
            }

            $text = self::sanitizeTranscriptSource( (string) ( $row['text'] ?? '' ) );
            if ( $text === '' ) {
                continue;
            }

            if ( $type === 'cue' ) {
                $normalized[] = [ 'type' => 'cue', 'text' => $text ];
                continue;
            }

            $speaker = self::sanitizeTranscriptSpeaker( (string) ( $row['speaker'] ?? '' ) );
            if ( $speaker === '' ) {
                continue;
            }

            $normalized[] = [
                'type' => 'message',
                'speaker' => $speaker,
                'text' => $text,
            ];
        }

        return array_slice( $normalized, 0, 800 );
    }

    /**
     * @return array<int,array{type:string,speaker?:string,text:string}>
     */
    private static function parseTranscriptSource( string $source ): array {
        $source = self::sanitizeTranscriptSource( $source );
        if ( $source === '' ) {
            return [];
        }

        $rows = [];
        $current = null;
        foreach ( explode( "\n", $source ) as $line ) {
            $trimmed = trim( $line );
            if ( $trimmed === '' ) {
                if ( is_array( $current ) ) {
                    $current['text'] .= "\n\n";
                }
                continue;
            }

            if ( preg_match( '/^\(([^()]{1,180})\)$/u', $trimmed, $cue_match ) === 1 ) {
                if ( is_array( $current ) ) {
                    $rows[] = [
                        'type' => 'message',
                        'speaker' => (string) $current['speaker'],
                        'text' => trim( (string) $current['text'] ),
                    ];
                    $current = null;
                }
                $cue = self::sanitizeTranscriptSource( (string) ( $cue_match[1] ?? '' ) );
                if ( $cue !== '' ) {
                    $rows[] = [ 'type' => 'cue', 'text' => $cue ];
                }
                continue;
            }

            if ( preg_match( '/^([A-Za-z0-9 .,&()\'"\/-]{1,48}):\s*(.*)$/u', $trimmed, $speaker_match ) === 1 ) {
                if ( is_array( $current ) ) {
                    $rows[] = [
                        'type' => 'message',
                        'speaker' => (string) $current['speaker'],
                        'text' => trim( (string) $current['text'] ),
                    ];
                }
                $speaker = self::sanitizeTranscriptSpeaker( (string) ( $speaker_match[1] ?? '' ) );
                if ( $speaker === '' ) {
                    $current = null;
                    continue;
                }
                $current = [
                    'speaker' => $speaker,
                    'text' => self::sanitizeTranscriptSource( (string) ( $speaker_match[2] ?? '' ) ),
                ];
                continue;
            }

            if ( is_array( $current ) ) {
                $current['text'] .= ( $current['text'] !== '' ? "\n" : '' ) . self::sanitizeTranscriptSource( $trimmed );
                continue;
            }

            $cue = self::sanitizeTranscriptSource( $trimmed );
            if ( $cue !== '' ) {
                $rows[] = [ 'type' => 'cue', 'text' => $cue ];
            }
        }

        if ( is_array( $current ) ) {
            $rows[] = [
                'type' => 'message',
                'speaker' => (string) $current['speaker'],
                'text' => trim( (string) $current['text'] ),
            ];
        }

        return self::normalizeTranscriptRows( $rows );
    }

    /**
     * @param array<int,array{type:string,speaker?:string,text:string}> $rows
     */
    private static function transcriptSourceFromRows( array $rows ): string {
        $lines = [];
        foreach ( $rows as $row ) {
            $type = (string) ( $row['type'] ?? 'message' );
            $text = self::sanitizeTranscriptSource( (string) ( $row['text'] ?? '' ) );
            if ( $text === '' ) {
                continue;
            }
            if ( $type === 'cue' ) {
                $lines[] = '(' . $text . ')';
                continue;
            }

            $speaker = self::sanitizeTranscriptSpeaker( (string) ( $row['speaker'] ?? '' ) );
            if ( $speaker === '' ) {
                continue;
            }
            $parts = explode( "\n", $text );
            $first = array_shift( $parts );
            $lines[] = $speaker . ': ' . (string) $first;
            foreach ( $parts as $part ) {
                $lines[] = $part;
            }
        }

        return trim( implode( "\n", $lines ) );
    }

    private static function sanitizeTranscriptSpeaker( string $speaker ): string {
        $speaker = self::repairMojibakeText( $speaker );
        $speaker = metis_text_clean( trim( preg_replace( '/\s+/u', ' ', $speaker ) ?? $speaker ) );
        if ( $speaker === '' || mb_strlen( $speaker ) > 48 ) {
            return '';
        }
        if ( preg_match( '/^[A-Za-z0-9 .,&()\'"\/-]+:?$/u', $speaker ) !== 1 ) {
            return '';
        }

        return rtrim( $speaker, ':' );
    }

	    private static function repairMojibakeText( string $text ): string {
	        $current = str_replace(
            [ 'Ã¢ÂÂ', 'Ã¢ÂÂ', 'Ã¢ÂÂ', 'Ã¢ÂÂ', 'Ã¢ÂÂ¦', 'Ã¢ÂÂ', 'Ã¢ÂÂ', 'â', 'â', 'â', 'â', 'â¦', 'â', 'â', 'ÃÂ ', 'Â ' ],
            [ '’', '‘', '“', '”', '…', '–', '—', '’', '‘', '“', '”', '…', '–', '—', ' ', ' ' ],
            $text
        );
        $current = preg_replace( '/([A-Za-z0-9])\x{FFFD}\?\?([A-Za-z0-9])/u', '$1’$2', $current ) ?? $current;
        $current = preg_replace( '/(^|[\s(\[{])\x{FFFD}\?\?([A-Za-z0-9])/u', '$1“$2', $current ) ?? $current;
        $current = preg_replace( '/([A-Za-z0-9?!.,])\x{FFFD}\?\?($|[\s)\]}.,;!?])/u', '$1”$2', $current ) ?? $current;

	        for ( $i = 0; $i < 3; $i++ ) {
            $candidate = function_exists( 'mb_convert_encoding' )
                ? @mb_convert_encoding( $current, 'UTF-8', 'Windows-1252' )
                : @iconv( 'Windows-1252', 'UTF-8//IGNORE', $current );
            if ( ! is_string( $candidate ) || $candidate === '' || $candidate === $current ) {
                break;
            }
            if ( substr_count( $candidate, 'Ã' ) + substr_count( $candidate, 'Â' ) + substr_count( $candidate, 'â' ) >= substr_count( $current, 'Ã' ) + substr_count( $current, 'Â' ) + substr_count( $current, 'â' ) ) {
                break;
            }
            $current = $candidate;
	        }

	        $current = str_replace( [ "\xc2\xa0", "\xa0" ], ' ', $current );
	        $current = preg_replace( '/Ã+(?=\s|$)/u', '', $current ) ?? $current;
	        $current = preg_replace( '/Ã(?=[A-Za-z0-9])/u', '', $current ) ?? $current;
	        $current = preg_replace( '/Â+(?=\s|$)/u', '', $current ) ?? $current;
	        $current = preg_replace( '/Â(?=[A-Za-z0-9])/u', '', $current ) ?? $current;
	        $current = preg_replace( '/\x{FFFD}+/u', '', $current ) ?? $current;
	        return $current;
	    }

    private static function normalizeUrl( string $value ): string {
        $url = trim( $value );
        if ( $url === '' ) {
            return '#';
        }
        if ( function_exists( 'metis_runtime_is_safe_url_value' ) && ! metis_runtime_is_safe_url_value( $url ) ) {
            return '#';
        }
        if ( $url === '#' ) {
            return '#';
        }
        if ( preg_match( '#^(https?:|mailto:|tel:)#i', $url ) === 1 ) {
            return $url;
        }
        if ( str_starts_with( $url, '/' ) ) {
            return $url;
        }
        return '/' . ltrim( $url, '/' );
    }

    private static function normalizeOptionalUrl( string $value ): string {
        $url = trim( $value );
        if ( $url === '' ) {
            return '';
        }
        return self::normalizeUrl( $url );
    }

    private static function closestAllowedWidth( float $ratio, float $ratio_total ): string {
        if ( $ratio_total <= 0 ) {
            return '50%';
        }
        $pct = ( $ratio / $ratio_total ) * 100;
        $allowed = self::COLUMN_WIDTHS;
        $best = 50;
        $best_diff = INF;
        foreach ( $allowed as $candidate ) {
            $diff = abs( $pct - (float) $candidate );
            if ( $diff < $best_diff ) {
                $best = $candidate;
                $best_diff = $diff;
            }
        }
        return (string) $best . '%';
    }

    /**
     * @return array<string,mixed>
     */
    private static function decodeLayout( mixed $raw_layout ): array {
        if ( is_array( $raw_layout ) ) {
            return $raw_layout;
        }
        if ( ! is_string( $raw_layout ) || trim( $raw_layout ) === '' ) {
            return [];
        }
        $decoded = json_decode( $raw_layout, true );
        return is_array( $decoded ) ? $decoded : [];
    }

    /**
     * @param array<string,mixed> $value
     */
    private static function encodeJson( array $value ): string {
        if ( function_exists( 'metis_json_encode' ) ) {
            return (string) metis_json_encode( $value );
        }
        $encoded = json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        return is_string( $encoded ) ? $encoded : '{}';
    }

    /**
     * @return array<string,mixed>
     */
    private static function defaultSection( bool $is_post = false ): array {
        return [
            'id' => 'section_0',
            'type' => 'text',
            'header' => null,
            'subtext' => null,
            'settings' => self::normalizeSectionSettings( [] ),
            'content' => [ 'body' => '<p></p>' ],
        ];
    }
}
