<?php
declare(strict_types=1);

namespace Metis\Core\Services;

final class RuntimeAssetService {
    public function renderJavascript(string $domain = '', string $view = ''): string {
        $assets = $this->collectAssets($domain, $view);
        $scripts = [];

        foreach ((array) ($assets['inline_scripts_before'] ?? []) as $entries) {
            foreach ((array) $entries as $script) {
                $scripts[] = trim((string) $script);
            }
        }

        foreach ((array) ($assets['inline_scripts_after'] ?? []) as $entries) {
            foreach ((array) $entries as $script) {
                $scripts[] = trim((string) $script);
            }
        }

        if (\function_exists('metis_accessibility_bootstrap_script')) {
            $accessibility = trim((string) \metis_accessibility_bootstrap_script());
            if ($accessibility !== '') {
                $scripts[] = $accessibility;
            }
        }

        return $scripts === [] ? '' : implode("\n", array_filter($scripts, static fn (string $script): bool => $script !== '')) . "\n";
    }

    public function renderStylesheet(string $domain = '', string $view = ''): string {
        $assets = $this->collectAssets($domain, $view);
        $styles = [];

        foreach ((array) ($assets['inline_styles'] ?? []) as $entries) {
            foreach ((array) $entries as $css) {
                $styles[] = trim((string) $css);
            }
        }

        return $styles === [] ? '' : implode("\n", array_filter($styles, static fn (string $css): bool => $css !== '')) . "\n";
    }

    private function collectAssets(string $domain, string $view): array {
        $this->ensureAssetHooksLoaded();

        $original_assets = is_array($GLOBALS['metis_assets'] ?? null) ? $GLOBALS['metis_assets'] : [];
        $original_query_vars = is_array($GLOBALS['metis_query_vars'] ?? null) ? $GLOBALS['metis_query_vars'] : [];

        $GLOBALS['metis_assets'] = [];
        $this->seedAssetBuckets();

        \metis_set_query_var('metis_domain', $domain);
        \metis_set_query_var('metis_view', $view);

        \metis_runtime_do_action('metis_assets_enqueue');

        $assets = is_array($GLOBALS['metis_assets'] ?? null) ? $GLOBALS['metis_assets'] : [];

        $GLOBALS['metis_assets'] = $original_assets;
        $GLOBALS['metis_query_vars'] = $original_query_vars;

        return $assets;
    }

    private function ensureAssetHooksLoaded(): void {
        if (!\function_exists('metis_get_modules')) {
            \metis_core_bootstrap('modules');
        }

        require_once \dirname(__DIR__) . '/AssetsRuntime.php';
    }

    private function seedAssetBuckets(): void {
        $defaults = [
            'scripts' => [],
            'styles' => [],
            'enqueued_scripts' => [],
            'enqueued_styles' => [],
            'inline_scripts_before' => [],
            'inline_scripts_after' => [],
            'inline_styles' => [],
            'scripts_printed' => [],
            'styles_printed' => [],
        ];

        foreach ($defaults as $key => $value) {
            if (!isset($GLOBALS['metis_assets'][$key]) || !\is_array($GLOBALS['metis_assets'][$key])) {
                $GLOBALS['metis_assets'][$key] = $value;
            }
        }
    }
}
