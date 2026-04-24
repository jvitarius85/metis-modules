<?php
declare(strict_types=1);

if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

if ( ! function_exists( 'metis_render_sidebar_layout' ) ) {
    function metis_render_sidebar_layout( array $args ): void {
        $class = trim( (string) ( $args['class'] ?? '' ) );
        $shell_class = trim( (string) ( $args['shell_class'] ?? '' ) );
        $sidebar_class = trim( (string) ( $args['sidebar_class'] ?? '' ) );
        $content_class = trim( (string) ( $args['content_class'] ?? '' ) );
        $sidebar = is_callable( $args['sidebar'] ?? null ) ? $args['sidebar'] : null;
        $content = is_callable( $args['content'] ?? null ) ? $args['content'] : null;

        echo '<div class="metis-sidebar-layout ' . metis_escape_attr( $class ) . '">';
        echo '<aside class="metis-sidebar-layout-sidebar ' . metis_escape_attr( $shell_class ) . '">';
        echo '<div class="metis-sidebar-layout-sidebar-inner ' . metis_escape_attr( $sidebar_class ) . '">';
        if ( $sidebar ) {
            $sidebar();
        }
        echo '</div>';
        echo '</aside>';
        echo '<div class="metis-sidebar-layout-content ' . metis_escape_attr( $content_class ) . '">';
        if ( $content ) {
            $content();
        }
        echo '</div>';
        echo '</div>';
    }
}
