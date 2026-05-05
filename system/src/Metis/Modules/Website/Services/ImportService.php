<?php
declare(strict_types=1);

namespace Metis\Modules\Website\Services;

/**
 * Import Service
 *
 * Website import helper service.
 *
 * Heavy import execution stays in the Import module; this service gives the Website
 * a clean readiness contract, guardrails, and post-import guidance.
 */
final class ImportService {
    /**
     * @return array{available:bool,url:string,message:string,steps:array<int,array{label:string,detail:string}>}
     */
    public static function readiness(): array {
        $import_url = function_exists( 'metis_portal_url' ) ? (string) \metis_portal_url( 'import', 'dashboard' ) : '';
        $module_available = self::moduleAppearsAvailable();

        return [
            'available' => $module_available,
            'url' => $import_url,
            'message' => $module_available
                ? 'Website imports are routed through the Import module so content can be staged, reviewed, and published intentionally.'
                : 'The Import module is not available. Website content can still be created manually.',
            'steps' => [
                [
                    'label' => 'Upload source content',
                    'detail' => 'Bring in the source file or supported feed through the Import module.',
                ],
                [
                    'label' => 'Map content',
                    'detail' => 'Confirm pages, posts, slugs, categories, media references, and redirects before anything is written.',
                ],
                [
                    'label' => 'Review drafts',
                    'detail' => 'Imported content should land as drafts unless a user explicitly chooses to publish.',
                ],
                [
                    'label' => 'Publish intentionally',
                    'detail' => 'Use Website publishing, menu, template, and redirect controls after the import is verified.',
                ],
            ],
        ];
    }

    /**
     * @return array<int,string>
     */
    public static function guardrails(): array {
        return [
            'Do not overwrite published pages without review.',
            'Preserve existing URLs unless redirects are planned.',
            'Validate media paths before enabling public routes.',
            'Use drafts for uncertain or incomplete content.',
        ];
    }

    private static function moduleAppearsAvailable(): bool {
        if ( function_exists( 'metis_module_is_enabled' ) ) {
            return (bool) \metis_module_is_enabled( 'import' );
        }

        $root = defined( 'METIS_ROOT' ) ? (string) METIS_ROOT : dirname( __DIR__, 6 );
        return is_file( $root . '/system/modules/import/module.json' )
            || is_file( $root . '/system/modules/import/Module.php' );
    }
}
