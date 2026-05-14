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

        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( $line === '' ) {
                self::closeList( $html, $list_type );
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
                $heading = self::cleanInline( $line );
                $outline[] = [ 'level' => 'article', 'title' => $heading ];
                $html .= '<section class="metis-board-bylaws-article">';
                $html .= '<h3>' . self::escape( $heading ) . '</h3>';
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
                $heading = self::cleanInline( $line );
                $outline[] = [ 'level' => 'section', 'title' => $heading ];
                $html .= '<section class="metis-board-bylaws-subsection">';
                $html .= '<h4>' . self::escape( $heading ) . '</h4>';
                continue;
            }

            $list = self::parseListItem( $line );
            if ( $list !== null ) {
                if ( $list_type !== $list['type'] ) {
                    self::closeList( $html, $list_type );
                    $list_type = $list['type'];
                    $html .= $list_type === 'ol'
                        ? '<ol class="metis-board-bylaws-list">'
                        : '<ul class="metis-board-bylaws-list">';
                }
                $html .= '<li>' . self::escape( $list['text'] ) . '</li>';
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
        $text = str_replace( [ "\r\n", "\r" ], "\n", $text );
        $text = preg_replace( "/[ \t]+\n/u", "\n", $text ) ?? $text;
        $text = preg_replace( "/\n{3,}/u", "\n\n", $text ) ?? $text;
        return trim( $text );
    }

    private static function cleanInline( string $text ): string {
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
     * @return array{type:string,text:string}|null
     */
    private static function parseListItem( string $line ): ?array {
        if ( preg_match( '/^(?:[-*•])\s+(.+)$/u', $line, $matches ) ) {
            return [ 'type' => 'ul', 'text' => self::cleanInline( (string) $matches[1] ) ];
        }
        if ( preg_match( '/^(?:\d+[\.)]|[a-zA-Z][\.)])\s+(.+)$/u', $line, $matches ) ) {
            return [ 'type' => 'ol', 'text' => self::cleanInline( (string) $matches[1] ) ];
        }
        return null;
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
