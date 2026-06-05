<?php
declare(strict_types=1);

namespace {
    if (PHP_SAPI !== 'cli') {
        fwrite(STDERR, "This test must be run from the command line.\n");
        exit(1);
    }

    define('METIS_ROOT', dirname(__DIR__, 2) . '/');

    final class Core_Settings_Service {
        public static function get(string $key, $default = null) {
            return $default;
        }
    }

    function metis_home_url(string $path = ''): string {
        return 'https://metis.example.test' . $path;
    }

    function metis_filename_clean(string $value): string {
        $value = trim(str_replace('\\', '/', html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if ($value === '') {
            return '';
        }
        $path = (string) (parse_url($value, PHP_URL_PATH) ?? '');
        $basename = basename($path !== '' ? $path : $value);
        return trim($basename);
    }
}

namespace Metis\Modules\Media {
    final class MediaLibraryService {
        public static function findByToken(string $token): ?array {
            if ($token === 'media_token_logo') {
                return ['token' => $token, 'url' => 'https://metis.example.test/media/media_token_logo'];
            }
            return null;
        }

        public static function findByFilename(string $filename): ?array {
            if ($filename === 'Mobilize-Waco_full-color_1000px.png') {
                return ['url' => 'https://metis.example.test/uploads/newsletter/Mobilize-Waco_full-color_1000px.png'];
            }
            return null;
        }
    }
}

namespace {
    require_once dirname(__DIR__) . '/modules/newsletter/services/document.php';

    $failures = [];
    $assertSame = static function (string $expected, string $actual, string $message) use (&$failures): void {
        if ($expected !== $actual) {
            $failures[] = $message . ' Expected [' . $expected . '] but received [' . $actual . '].';
        }
    };
    $assertContains = static function (string $needle, string $haystack, string $message) use (&$failures): void {
        if (strpos($haystack, $needle) === false) {
            $failures[] = $message . ' Missing [' . $needle . '] in [' . $haystack . '].';
        }
    };

    $assertSame(
        'https://metis.example.test/media/media_token_logo',
        metis_newsletter_clean_url('media_token_logo'),
        'Newsletter URL cleaning should resolve media tokens through the shared media library.'
    );

    $assertSame(
        'https://metis.example.test/uploads/newsletter/Mobilize-Waco_full-color_1000px.png',
        metis_newsletter_clean_url('Mobilize-Waco_full-color_1000px.png'),
        'Newsletter URL cleaning should resolve bare filenames through the shared media library.'
    );

    $normalized = metis_newsletter_clean_html('<p><img src="Mobilize-Waco_full-color_1000px.png" alt="Logo"></p>');
    $assertContains(
        'src="https://metis.example.test/uploads/newsletter/Mobilize-Waco_full-color_1000px.png"',
        $normalized,
        'Newsletter HTML cleaning should rewrite bare image filenames to resolved media URLs.'
    );

    if ($failures !== []) {
        fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
        exit(1);
    }

    fwrite(STDOUT, "Newsletter media normalization checks passed.\n");
}
