<?php
declare(strict_types=1);

namespace Metis\Modules\Board;

final class BylawsFormatter {
    /**
     * @return array{title:string,html:string,outline:array<int,array{level:string,title:string}>,word_count:int}
     */
    public static function format( string $source_text, string $title = 'Bylaws' ): array {
        $title = self::cleanInline( $title );
        if ( $title === '' ) {
            $title = 'Bylaws';
        }

        $lines = preg_split( '/\R/u', self::normalizeText( $source_text ) ) ?: [];
        $html = '<article class="metis-board-bylaws-document">';
        $html .= '<h2>' . self::escape( $title ) . '</h2>';

        $outline = [];
        $open_article = false;
        $open_section = false;
        $list_type = '';
        $article_index = 0;
        $section_index = 0;

        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( $line === '' ) {
                continue;
            }

            if ( self::isArticleHeading( $line ) ) {
                self::closeList( $html, $list_type );
                if ( $open_section ) {
                    $html .= '</section>';
                    $open_section = false;
                }
                if ( $open_article ) {
                    $html .= '</section>';
                }
                $open_article = true;
                $article_index++;
                $section_index = 0;
                $heading = self::cleanInline( $line );
                $outline[] = [ 'level' => 'article', 'title' => $heading ];
                $html .= '<section class="metis-board-bylaws-article">';
                $html .= '<h3 id="metis-bylaws-article-' . $article_index . '">' . self::escape( $heading ) . '</h3>';
                continue;
            }

            if ( self::isSectionHeading( $line ) ) {
                self::closeList( $html, $list_type );
                if ( $open_section ) {
                    $html .= '</section>';
                }
                if ( ! $open_article ) {
                    $html .= '<section class="metis-board-bylaws-article">';
                    $open_article = true;
                }
                $open_section = true;
                $section_index++;
                $heading = self::cleanInline( $line );
                $outline[] = [ 'level' => 'section', 'title' => $heading ];
                $html .= '<section class="metis-board-bylaws-subsection">';
                $html .= '<h4 id="metis-bylaws-article-' . max( 1, $article_index ) . '-section-' . $section_index . '">' . self::escape( $heading ) . '</h4>';
                continue;
            }

            $list = self::parseListItem( $line );
            if ( $list !== null ) {
                if ( $list_type !== $list['type'] ) {
                    self::closeList( $html, $list_type );
                    $list_type = $list['type'];
                    $html .= $list_type === 'ol'
                        ? '<ol class="metis-board-bylaws-list" type="1">'
                        : '<ul class="metis-board-bylaws-list">';
                }
                $value = isset( $list['value'] ) ? ' value="' . self::escape( (string) $list['value'] ) . '"' : '';
                $html .= '<li' . $value . '>' . self::escape( $list['text'] ) . '</li>';
                continue;
            }

            self::closeList( $html, $list_type );
            if ( ! $open_article ) {
                $html .= '<section class="metis-board-bylaws-article">';
                $open_article = true;
            }
            $html .= '<p>' . self::escape( self::cleanInline( $line ) ) . '</p>';
        }

        self::closeList( $html, $list_type );
        if ( $open_section ) {
            $html .= '</section>';
        }
        if ( $open_article ) {
            $html .= '</section>';
        }
        $html .= '</article>';

        return [
            'title'      => $title,
            'html'       => $html,
            'outline'    => $outline,
            'word_count' => self::wordCount( $source_text ),
        ];
    }

    private static function normalizeText( string $text ): string {
        $text = self::repairMojibake( $text );
        $text = str_replace( [ "\r\n", "\r" ], "\n", $text );
        $text = preg_replace( "/[ \t]+\n/u", "\n", $text ) ?? $text;
        $text = self::removeSourceTableOfContents( $text );
        $text = preg_replace( "/\n{3,}/u", "\n\n", $text ) ?? $text;
        return trim( $text );
    }

    private static function cleanInline( string $text ): string {
        $text = self::repairMojibake( $text );
        $text = preg_replace( '/[ \t]+/u', ' ', trim( $text ) ) ?? trim( $text );
        return trim( $text );
    }

    private static function isArticleHeading( string $line ): bool {
        return (bool) preg_match( '/^ARTICLE\s+(?:[IVXLCDM]+|\d+)\b(?:\s*[-.:]\s*|\s+.*|$)/iu', $line );
    }

    private static function isSectionHeading( string $line ): bool {
        return (bool) preg_match( '/^(?:SECTION|Sec\.)\s+\d+(?:\.\d+)?\b(?:\s*[-.:]\s*|\s+.*|$)/iu', $line );
    }

    /**
     * @return array{type:string,text:string,value?:int}|null
     */
    private static function parseListItem( string $line ): ?array {
        if ( preg_match( '/^(?:[-*•])\s+(.+)$/u', $line, $matches ) ) {
            return [ 'type' => 'ul', 'text' => self::cleanInline( (string) $matches[1] ) ];
        }
        if ( preg_match( '/^(\d+)[\.)]\s+(.+)$/u', $line, $matches ) ) {
            return [
                'type'  => 'ol',
                'value' => max( 1, (int) $matches[1] ),
                'text'  => self::cleanInline( (string) $matches[2] ),
            ];
        }
        if ( preg_match( '/^[a-zA-Z][\.)]\s+(.+)$/u', $line, $matches ) ) {
            return [ 'type' => 'ol', 'text' => self::cleanInline( (string) $matches[1] ) ];
        }
        return null;
    }

    private static function repairMojibake( string $text ): string {
        $text = str_replace(
            [
                "\xC3\xA2\xE2\x82\xAC\xE2\x84\xA2",
                "\xC3\xA2\xE2\x82\xAC\xCB\x9C",
                "\xC3\xA2\xE2\x82\xAC\xC5\x93",
                "\xC3\xA2\xE2\x82\xAC\xC2\x9D",
                "\xC3\xA2\xE2\x82\xAC\xC2\x9D",
                "\xC3\xA2\xE2\x82\xAC\xE2\x80\x9C",
                "\xC3\xA2\xE2\x82\xAC\xE2\x80\x9D",
                "\xC3\xA2\xE2\x82\xAC\xC2\xA6",
                "\xC3\xA2\xE2\x82\xAC\xC2\xA2",
                "\xC3\x82",
            ],
            [
                "'",
                "'",
                '"',
                '"',
                '"',
                '-',
                '-',
                '...',
                '-',
                '',
            ],
            $text
        );
        $replacements = [
            'â€™' => "'",
            'â€˜' => "'",
            'â€œ' => '"',
            'â€' => '"',
            'â€' => '"',
            'â€�' => '"',
            'â€³' => '"',
            'â€“' => '-',
            'â€”' => '-',
            'â€•' => '-',
            'â€¦' => '...',
            'â€¢' => '-',
            'â€‹' => '',
            'â€Œ' => '',
            'â€Ž' => '',
            'â€¯' => ' ',
            'â„¢' => '(TM)',
            'â„ ' => 'SM',
            'â€'  => '"',
            'Â '  => ' ',
            'Â '  => ' ',
            'Â'   => '',
            'Ã©'  => 'e',
            'Ã¨'  => 'e',
            'Ã¡'  => 'a',
            'Ã¢'  => 'a',
            'Ã±'  => 'n',
            'Ã¼'  => 'u',
            'Ã¶'  => 'o',
        ];

        $text = strtr( $text, $replacements );
        return preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text ) ?? $text;
    }

    private static function removeSourceTableOfContents( string $text ): string {
        $lines = preg_split( '/\n/u', $text ) ?: [];
        $toc_index = null;

        foreach ( $lines as $index => $line ) {
            $clean = self::cleanInlineForStructure( $line );
            if ( $clean === '' ) {
                continue;
            }
            if ( $index > 60 ) {
                break;
            }
            if ( preg_match( '/^(?:table\s+of\s+contents|contents)$/iu', $clean ) ) {
                $toc_index = $index;
                break;
            }
        }

        if ( $toc_index === null ) {
            return $text;
        }

        $first_article_key = '';
        $body_start = null;
        for ( $index = $toc_index + 1; $index < count( $lines ); $index++ ) {
            $clean = self::cleanInlineForStructure( $lines[ $index ] );
            if ( $clean === '' || ! self::isArticleHeading( $clean ) ) {
                continue;
            }
            if ( $first_article_key === '' ) {
                $first_article_key = self::headingKey( $clean );
                continue;
            }
            if ( self::headingKey( $clean ) === $first_article_key ) {
                $body_start = $index;
                break;
            }
        }

        if ( $body_start === null ) {
            return $text;
        }

        return implode( "\n", array_merge(
            array_slice( $lines, 0, $toc_index ),
            array_slice( $lines, $body_start )
        ) );
    }

    private static function headingKey( string $line ): string {
        $line = self::cleanInlineForStructure( $line );
        if ( preg_match( '/^ARTICLE\s+([IVXLCDM]+|\d+)/iu', $line, $matches ) ) {
            return 'article:' . strtoupper( (string) $matches[1] );
        }

        return strtolower( $line );
    }

    private static function cleanInlineForStructure( string $text ): string {
        $text = self::repairMojibake( $text );
        $text = preg_replace( '/[ \t]+/u', ' ', trim( $text ) ) ?? trim( $text );
        return trim( $text );
    }

    private static function closeList( string &$html, string &$list_type ): void {
        if ( $list_type === '' ) {
            return;
        }
        $html .= $list_type === 'ol' ? '</ol>' : '</ul>';
        $list_type = '';
    }

    private static function wordCount( string $text ): int {
        $plain = trim( preg_replace( '/\s+/u', ' ', $text ) ?? $text );
        if ( $plain === '' ) {
            return 0;
        }
        return str_word_count( $plain );
    }

    private static function escape( string $value ): string {
        return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
    }
}
