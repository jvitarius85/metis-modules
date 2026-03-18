<?php
declare(strict_types=1);

function sanitize_key( string $key ): string {
    $key = strtolower( $key );
    return preg_replace( '/[^a-z0-9_\-]/', '', $key ) ?? '';
}

function sanitize_text_field( mixed $value ): string {
    $value = is_scalar( $value ) ? (string) $value : '';
    $value = strip_tags( $value );
    return trim( preg_replace( '/[\r\n\t ]+/', ' ', $value ) ?? '' );
}

function sanitize_textarea_field( mixed $value ): string {
    $value = is_scalar( $value ) ? (string) $value : '';
    return trim( strip_tags( $value ) );
}

function sanitize_email( mixed $value ): string {
    return filter_var( (string) $value, FILTER_SANITIZE_EMAIL ) ?: '';
}

function is_email( string $email ): string|false {
    $validated = filter_var( $email, FILTER_VALIDATE_EMAIL );
    return $validated !== false ? (string) $validated : false;
}

function sanitize_title( mixed $value ): string {
    $value = strtolower( trim( (string) $value ) );
    $value = preg_replace( '/[^a-z0-9]+/', '-', $value ) ?? '';
    return trim( $value, '-' );
}

function sanitize_title_with_dashes( string $title, string $raw_title = '', string $context = 'display' ): string {
    return sanitize_title( $title );
}

function sanitize_file_name( string $filename ): string {
    $filename = preg_replace( '/[^A-Za-z0-9\.\-_]/', '-', $filename ) ?? 'file';
    return trim( $filename, '-.' ) ?: 'file';
}

function sanitize_hex_color( string $color ): string {
    return preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $color ) ? $color : '';
}

function metis_runtime_unslash( mixed $value ): mixed {
    if ( is_array( $value ) ) {
        return array_map( 'metis_runtime_unslash', $value );
    }

    return is_string( $value ) ? stripslashes( $value ) : $value;
}

function metis_runtime_parse_url( string $url, int $component = -1 ): mixed {
    return parse_url( $url, $component );
}

function metis_runtime_kses_post( string $html ): string {
    return $html;
}

function get_bloginfo( string $show ): string {
    return $show === 'charset' ? 'UTF-8' : '';
}

function metis_runtime_json_encode( mixed $value, int $flags = 0 ): string|false {
    return json_encode( $value, $flags | JSON_UNESCAPED_SLASHES );
}

function esc_html( mixed $value ): string {
    return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
}

function esc_attr( mixed $value ): string {
    return esc_html( $value );
}

function esc_textarea( mixed $value ): string {
    return esc_html( $value );
}

function esc_url( mixed $value ): string {
    return filter_var( (string) $value, FILTER_SANITIZE_URL ) ?: '';
}

function esc_sql( string $value ): string {
    return addslashes( $value );
}

function esc_js( mixed $value ): string {
    return addslashes( (string) $value );
}

function disabled( bool $disabled, bool $current = true, bool $display = true ): string {
    $result = $disabled === $current ? 'disabled' : '';
    if ( $display ) {
        echo $result;
    }
    return $result;
}

function selected( mixed $selected, mixed $current = true, bool $display = true ): string {
    $result = (string) $selected === (string) $current ? 'selected' : '';
    if ( $display ) {
        echo $result;
    }
    return $result;
}

function checked( mixed $checked, mixed $current = true, bool $display = true ): string {
    $result = (string) $checked === (string) $current ? 'checked' : '';
    if ( $display ) {
        echo $result;
    }
    return $result;
}
