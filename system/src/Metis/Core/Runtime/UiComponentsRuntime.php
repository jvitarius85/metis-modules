<?php
declare(strict_types=1);

if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

if ( ! function_exists( 'metis_render_responsive_table' ) ) {
    function metis_render_responsive_table( string $table_html, array $args = [] ): void {
        $class = trim( (string) ( $args['class'] ?? '' ) );
        $label = trim( (string) ( $args['label'] ?? 'Responsive table' ) );
        $hint = trim( (string) ( $args['hint'] ?? 'Scroll horizontally to view all columns.' ) );

        echo '<div class="metis-table-wrap ' . metis_escape_attr( $class ) . '" role="region" aria-label="' . metis_escape_attr( $label ) . '" tabindex="0">';
        if ( $hint !== '' ) {
            echo '<div class="metis-table-wrap__hint">' . metis_escape_html( $hint ) . '</div>';
        }
        echo $table_html;
        echo '</div>';
    }
}

if ( ! function_exists( 'metis_render_empty_state' ) ) {
    function metis_render_empty_state( array $args = [] ): void {
        metis_render_standard_state( 'empty', $args );
    }
}

if ( ! function_exists( 'metis_render_error_state' ) ) {
    function metis_render_error_state( array $args = [] ): void {
        metis_render_standard_state( 'error', $args );
    }
}

if ( ! function_exists( 'metis_render_loading_state' ) ) {
    function metis_render_loading_state( array $args = [] ): void {
        metis_render_standard_state( 'loading', $args );
    }
}

if ( ! function_exists( 'metis_render_standard_state' ) ) {
    function metis_render_standard_state( string $type, array $args = [] ): void {
        $type = in_array( $type, [ 'empty', 'error', 'loading' ], true ) ? $type : 'empty';
        $class = trim( (string) ( $args['class'] ?? '' ) );
        $title = trim( (string) ( $args['title'] ?? '' ) );
        $message = trim( (string) ( $args['message'] ?? '' ) );
        $icon = trim( (string) ( $args['icon'] ?? '' ) );
        $actions = is_callable( $args['actions'] ?? null ) ? $args['actions'] : null;

        if ( $title === '' ) {
            $title = match ( $type ) {
                'error' => 'Something went wrong',
                'loading' => 'Loading',
                default => 'Nothing here yet',
            };
        }

        if ( $message === '' ) {
            $message = match ( $type ) {
                'error' => 'Try again or refresh the page.',
                'loading' => 'Please wait while Metis finishes loading this section.',
                default => 'There is no data to display yet.',
            };
        }

        echo '<section class="metis-state metis-state-' . metis_escape_attr( $type ) . ' ' . metis_escape_attr( $class ) . '">';
        if ( $type === 'loading' ) {
            echo '<span class="metis-loading-spinner" aria-hidden="true"></span>';
        } elseif ( $icon !== '' ) {
            echo '<div class="metis-state__icon" aria-hidden="true">' . $icon . '</div>';
        }
        echo '<div class="metis-state__body">';
        echo '<h2 class="metis-state__title">' . metis_escape_html( $title ) . '</h2>';
        echo '<p class="metis-state__message">' . metis_escape_html( $message ) . '</p>';
        if ( $actions ) {
            echo '<div class="metis-state__actions">';
            $actions();
            echo '</div>';
        }
        echo '</div>';
        echo '</section>';
    }
}

if ( ! function_exists( 'metis_render_mobile_section' ) ) {
    function metis_render_mobile_section( array $args = [] ): void {
        $title = trim( (string) ( $args['title'] ?? 'Section' ) );
        $class = trim( (string) ( $args['class'] ?? '' ) );
        $open = ! empty( $args['open'] );
        $summary_class = trim( (string) ( $args['summary_class'] ?? '' ) );
        $body_class = trim( (string) ( $args['body_class'] ?? '' ) );
        $content = $args['content'] ?? '';

        echo '<details class="metis-mobile-section ' . metis_escape_attr( $class ) . '"' . ( $open ? ' open' : '' ) . '>';
        echo '<summary class="metis-mobile-section__summary ' . metis_escape_attr( $summary_class ) . '">' . metis_escape_html( $title ) . '</summary>';
        echo '<div class="metis-mobile-section__body ' . metis_escape_attr( $body_class ) . '">';
        if ( is_callable( $content ) ) {
            $content();
        } else {
            echo (string) $content;
        }
        echo '</div>';
        echo '</details>';
    }
}
