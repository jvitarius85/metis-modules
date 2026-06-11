<?php
declare(strict_types=1);

namespace Metis\Hermes\Nlu;

final class LanguagePackLoader {
    /** @var array<string,array<string,mixed>> */
    private static array $cache = [];

    public function __construct(
        private readonly string $basePath = '',
        private readonly string $customPath = ''
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function load( string $locale = 'en-US' ): array {
        $locale = trim( $locale ) !== '' ? trim( $locale ) : 'en-US';
        if ( isset( self::$cache[ $locale ] ) ) {
            return self::$cache[ $locale ];
        }

        $basePath = $this->basePath !== ''
            ? rtrim( $this->basePath, '/\\' )
            : $this->defaultBasePath();
        $localePath = $basePath . '/' . $locale;
        $pack = [
            'actions' => [],
            'entities' => [],
            'phrases' => [],
            'dates' => [],
            'money' => [],
            'misspellings' => [],
            'stopwords' => [],
        ];

        foreach ( array_keys( $pack ) as $section ) {
            $path = $localePath . '/' . $section . '.json';
            $decoded = $this->readJsonFile( $path );
            if ( is_array( $decoded ) ) {
                $pack[ $section ] = $decoded;
            }
        }

        $custom = $this->readJsonFile(
            $this->customPath !== ''
                ? $this->customPath
                : $this->defaultCustomPath()
        );
        if ( is_array( $custom ) ) {
            $customPack = $this->extractCustomLocalePack( $custom, $locale );
            if ( $customPack !== [] ) {
                $pack = $this->mergeRecursiveDistinct( $pack, $customPack );
            }
        }

        self::$cache[ $locale ] = $pack;
        return $pack;
    }

    private function defaultBasePath(): string {
        if ( defined( 'METIS_ROOT' ) ) {
            return rtrim( (string) METIS_ROOT, '/\\' ) . '/system/config/hermes/nlu/language-packs';
        }

        return rtrim( dirname( __DIR__, 5 ), '/\\' ) . '/system/config/hermes/nlu/language-packs';
    }

    private function defaultCustomPath(): string {
        if ( defined( 'METIS_ROOT' ) ) {
            return rtrim( (string) METIS_ROOT, '/\\' ) . '/storage/hermes/custom-language-pack.json';
        }

        return rtrim( dirname( __DIR__, 5 ), '/\\' ) . '/storage/hermes/custom-language-pack.json';
    }

    /**
     * @return array<string,mixed>
     */
    private function extractCustomLocalePack( array $custom, string $locale ): array {
        if ( isset( $custom[ $locale ] ) && is_array( $custom[ $locale ] ) ) {
            return (array) $custom[ $locale ];
        }

        if ( isset( $custom['language_packs'][ $locale ] ) && is_array( $custom['language_packs'][ $locale ] ) ) {
            return (array) $custom['language_packs'][ $locale ];
        }

        $knownSections = [ 'actions', 'entities', 'phrases', 'dates', 'money', 'misspellings', 'stopwords' ];
        foreach ( $knownSections as $section ) {
            if ( array_key_exists( $section, $custom ) ) {
                return $custom;
            }
        }

        return [];
    }

    /**
     * @return array<string,mixed>|array<int,mixed>|null
     */
    private function readJsonFile( string $path ): array|null {
        if ( ! is_file( $path ) || ! is_readable( $path ) ) {
            return null;
        }

        $raw = file_get_contents( $path );
        if ( $raw === false || trim( $raw ) === '' ) {
            return null;
        }

        $decoded = json_decode( $raw, true );
        return is_array( $decoded ) ? $decoded : null;
    }

    /**
     * @param array<string,mixed> $base
     * @param array<string,mixed> $override
     * @return array<string,mixed>
     */
    private function mergeRecursiveDistinct( array $base, array $override ): array {
        foreach ( $override as $key => $value ) {
            if ( is_array( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) ) {
                $isList = array_is_list( $value ) || array_is_list( $base[ $key ] );
                if ( $isList ) {
                    $base[ $key ] = array_values( array_unique( array_merge( (array) $base[ $key ], $value ), SORT_REGULAR ) );
                } else {
                    $base[ $key ] = $this->mergeRecursiveDistinct( (array) $base[ $key ], $value );
                }
                continue;
            }

            $base[ $key ] = $value;
        }

        return $base;
    }
}
