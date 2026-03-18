<?php
declare(strict_types=1);

if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

if ( ! function_exists( 'metis_render_sidebar_module_layout' ) ) {
    function metis_render_sidebar_module_layout( array $args ): void {
        $class = trim( (string) ( $args['class'] ?? '' ) );
        $title = trim( (string) ( $args['title'] ?? '' ) );
        $subtitle = trim( (string) ( $args['subtitle'] ?? '' ) );
        $shell_class = trim( (string) ( $args['shell_class'] ?? '' ) );
        $sidebar_class = trim( (string) ( $args['sidebar_class'] ?? '' ) );
        $content_class = trim( (string) ( $args['content_class'] ?? '' ) );
        $header_actions = is_callable( $args['header_actions'] ?? null ) ? $args['header_actions'] : null;
        $sidebar = is_callable( $args['sidebar'] ?? null ) ? $args['sidebar'] : null;
        $content = is_callable( $args['content'] ?? null ) ? $args['content'] : null;

        echo '<section class="mw-module-layout ' . esc_attr( $class ) . '">';
        if ( $title !== '' || $subtitle !== '' || $header_actions ) {
            echo '<div class="mw-module-layout-header">';
            echo '<div class="mw-module-layout-heading">';
            if ( $title !== '' ) {
                echo '<h1 class="mw-page-title">' . esc_html( $title ) . '</h1>';
            }
            if ( $subtitle !== '' ) {
                echo '<p class="mw-subtitle">' . esc_html( $subtitle ) . '</p>';
            }
            echo '</div>';
            if ( $header_actions ) {
                echo '<div class="mw-module-layout-header-actions">';
                $header_actions();
                echo '</div>';
            }
            echo '</div>';
        }

        metis_render_sidebar_layout( [
            'class' => 'mw-module-layout-shell ' . $shell_class,
            'sidebar_class' => $sidebar_class,
            'content_class' => $content_class,
            'sidebar' => $sidebar,
            'content' => $content,
        ] );

        echo '</section>';
    }
}
