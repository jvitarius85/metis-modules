<?php
declare(strict_types=1);

namespace Metis\Core\Services;

/**
 * Shared editor service.
 *
 * Legacy page-builder/grapes runtime has been removed.
 * The canonical runtime is simple-editor only.
 */
final class EditorService {

    /**
     * Enqueue shared editor assets for any editor context.
     *
     * @param string $context 'website' | 'newsletter' | 'post' | etc.
     * @param array<string,mixed> $config Additional context config.
     */
    public static function enqueue_assets( string $context = 'website', array $config = [] ): void {
        $base_url = defined( 'METIS_URL' ) ? METIS_URL : '';
        $version = metis_version();

        metis_runtime_enqueue_style(
            'metis-editor-simple',
            $base_url . 'assets/js/editor/simple-editor.css',
            [ 'metis-core' ],
            (string) @filemtime( METIS_ROOT . '/assets/js/editor/simple-editor.css' ) ?: $version
        );

        metis_runtime_enqueue_script(
            'metis-editor-simple',
            $base_url . 'assets/js/editor/simple-editor.js',
            [ 'metis-core' ],
            (string) @filemtime( METIS_ROOT . '/assets/js/editor/simple-editor.js' ) ?: $version,
            true
        );

        metis_runtime_localize_script( 'metis-editor-simple', 'metisEditorConfig', [
            'context' => $context,
            'config' => $config,
        ] );
    }

    /**
     * Backward-compatible entry point now mapped to simple editor assets.
     */
    public static function enqueue_shell_assets(): void {
        self::enqueue_assets( 'website', [] );
    }

    /**
     * Backward-compatible config method; grapes shell is no longer used.
     *
     * @return array<string,mixed>
     */
    public static function grapes_shell_config(): array {
        return [
            'plugins_enabled' => 0,
            'plugin_packs' => [],
            'plugins_opts' => [],
            'editor' => 'simple',
        ];
    }
}
