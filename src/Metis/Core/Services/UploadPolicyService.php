<?php
declare(strict_types=1);

namespace Metis\Core\Services;

final class UploadPolicyService {
    private const BLOCKED_EXTENSIONS = [
        'php',
        'php3',
        'php4',
        'php5',
        'php7',
        'php8',
        'phtml',
        'phar',
        'cgi',
        'pl',
        'py',
        'sh',
        'bash',
        'zsh',
        'exe',
        'dll',
        'com',
        'cmd',
        'bat',
        'msi',
        'jsp',
        'asp',
        'aspx',
    ];

    public function policies(): array {
        return [
            'avatars' => [
                'max_size' => 5 * 1024 * 1024,
                'mimes' => [
                    'jpg|jpeg|jpe' => 'image/jpeg',
                    'png' => 'image/png',
                    'webp' => 'image/webp',
                ],
            ],
            'newsletter_media' => [
                'max_size' => 8 * 1024 * 1024,
                'mimes' => [
                    'jpg|jpeg|jpe' => 'image/jpeg',
                    'png' => 'image/png',
                    'gif' => 'image/gif',
                    'webp' => 'image/webp',
                ],
            ],
            'media_library' => [
                'max_size' => 25 * 1024 * 1024,
                'mimes' => [
                    'jpg|jpeg|jpe' => 'image/jpeg',
                    'png' => 'image/png',
                    'gif' => 'image/gif',
                    'webp' => 'image/webp',
                    'svg' => 'image/svg+xml',
                    'mp4' => 'video/mp4',
                    'webm' => 'video/webm',
                    'mov' => 'video/quicktime',
                    'mp3' => 'audio/mpeg',
                    'wav' => 'audio/wav',
                    'pdf' => 'application/pdf',
                    'txt' => 'text/plain',
                    'csv' => 'text/csv',
                    'doc' => 'application/msword',
                    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'xls' => 'application/vnd.ms-excel',
                    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'ppt' => 'application/vnd.ms-powerpoint',
                    'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                ],
            ],
            'attachments' => [
                'max_size' => 15 * 1024 * 1024,
                'mimes' => [
                    'pdf' => 'application/pdf',
                    'txt' => 'text/plain',
                    'csv' => 'text/csv',
                    'doc' => 'application/msword',
                    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'xls' => 'application/vnd.ms-excel',
                    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'ppt' => 'application/vnd.ms-powerpoint',
                    'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                    'zip' => 'application/zip',
                    'jpg|jpeg|jpe' => 'image/jpeg',
                    'png' => 'image/png',
                    'gif' => 'image/gif',
                    'webp' => 'image/webp',
                ],
            ],
            'form_uploads' => [
                'max_size' => 10 * 1024 * 1024,
                'mimes' => [
                    'pdf' => 'application/pdf',
                    'txt' => 'text/plain',
                    'csv' => 'text/csv',
                    'jpg|jpeg|jpe' => 'image/jpeg',
                    'png' => 'image/png',
                    'webp' => 'image/webp',
                    'doc' => 'application/msword',
                    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                ],
            ],
        ];
    }

    public function policy( string $policy ): array {
        return $this->policies()[ $policy ] ?? [];
    }

    public function allowedExtensions( array $mimes ): array {
        $extensions = [];
        foreach ( $mimes as $key => $mime ) {
            foreach ( \preg_split( '/\|+/', (string) $key ) ?: [] as $extension ) {
                $extension = \metis_key_clean( $extension );
                if ( $extension !== '' ) {
                    $extensions[ $extension ] = true;
                }
            }
        }

        return \array_keys( $extensions );
    }

    public function normalizeOptions( array $overrides = [] ): array {
        $policy_name = isset( $overrides['policy'] ) ? \metis_key_clean( (string) $overrides['policy'] ) : '';
        $policy = $policy_name !== '' ? $this->policy( $policy_name ) : [];

        $mimes = isset( $overrides['mimes'] ) && \is_array( $overrides['mimes'] ) && $overrides['mimes'] !== []
            ? $overrides['mimes']
            : (array) ( $policy['mimes'] ?? [] );

        $max_size = isset( $overrides['max_size'] ) ? (int) $overrides['max_size'] : (int) ( $policy['max_size'] ?? 0 );
        $extensions = isset( $overrides['extensions'] ) && \is_array( $overrides['extensions'] ) && $overrides['extensions'] !== []
            ? \array_values( \array_filter( \array_map( 'metis_key_clean', $overrides['extensions'] ) ) )
            : $this->allowedExtensions( $mimes );

        return [
            'policy' => $policy_name,
            'mimes' => $mimes,
            'max_size' => $max_size,
            'extensions' => $extensions,
        ];
    }

    private function sanitizedName( string $name ): string {
        return trim( (string) \metis_filename_clean( $name ) );
    }

    private function hasBlockedExtension( string $extension ): bool {
        return \in_array( \metis_key_clean( $extension ), self::BLOCKED_EXTENSIONS, true );
    }

    private function hasBlockedDoubleExtension( string $name ): bool {
        $segments = \array_values(
            \array_filter(
                \explode( '.', \strtolower( $this->sanitizedName( $name ) ) ),
                static fn ( string $segment ): bool => trim( $segment ) !== ''
            )
        );

        if ( \count( $segments ) <= 2 ) {
            return false;
        }

        \array_pop( $segments );
        foreach ( $segments as $segment ) {
            if ( $this->hasBlockedExtension( $segment ) ) {
                return true;
            }
        }

        return false;
    }

    private function detectedMimeType( string $path ): string {
        if ( ! \is_file( $path ) ) {
            return '';
        }

        if ( \function_exists( 'finfo_open' ) ) {
            $finfo = \finfo_open( \FILEINFO_MIME_TYPE );
            if ( $finfo ) {
                $mime = \finfo_file( $finfo, $path );
                if ( \is_string( $mime ) && $mime !== '' ) {
                    return \strtolower( $mime );
                }
            }
        }

        if ( \function_exists( 'mime_content_type' ) ) {
            $mime = \mime_content_type( $path );
            if ( \is_string( $mime ) && $mime !== '' ) {
                return \strtolower( $mime );
            }
        }

        return '';
    }

    public function validateFile( array $file, array $overrides = [] ): array {
        $options = $this->normalizeOptions( $overrides );
        if ( $options['mimes'] === [] ) {
            return [
                'ok' => false,
                'error' => 'File uploads require an explicit allowlist.',
            ];
        }

        $name = isset( $file['name'] ) ? (string) $file['name'] : '';
        $sanitized_name = $this->sanitizedName( $name );
        $size = isset( $file['size'] ) ? (int) $file['size'] : 0;
        $extension = \metis_key_clean( (string) \pathinfo( $sanitized_name, \PATHINFO_EXTENSION ) );

        if ( $sanitized_name === '' ) {
            return [
                'ok' => false,
                'error' => 'Uploaded filename is invalid.',
            ];
        }

        if ( $this->hasBlockedExtension( $extension ) || $this->hasBlockedDoubleExtension( $sanitized_name ) ) {
            return [
                'ok' => false,
                'error' => 'Uploaded file type is not permitted.',
            ];
        }

        if ( $extension === '' || ! \in_array( $extension, $options['extensions'], true ) ) {
            return [
                'ok' => false,
                'error' => 'Uploaded file extension is not allowed.',
            ];
        }

        $tmp_path = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
        if ( $tmp_path !== '' && \is_file( $tmp_path ) ) {
            $detected_mime = $this->detectedMimeType( $tmp_path );
            if ( $detected_mime === '' || ! \in_array( $detected_mime, \array_values( $options['mimes'] ), true ) ) {
                return [
                    'ok' => false,
                    'error' => 'Uploaded file type is not allowed.',
                ];
            }
        }

        if ( $options['max_size'] > 0 && $size > $options['max_size'] ) {
            return [
                'ok' => false,
                'error' => 'Uploaded file exceeds the maximum allowed size.',
            ];
        }

        return [
            'ok' => true,
            'options' => $options,
        ];
    }

    public function validateBinary( string $filename, string $mime_type, int $size, array $overrides = [] ): array {
        $options = $this->normalizeOptions( $overrides );
        if ( $options['mimes'] === [] ) {
            return [
                'ok' => false,
                'error' => 'File uploads require an explicit allowlist.',
            ];
        }

        $sanitized_name = $this->sanitizedName( $filename );
        $extension = \metis_key_clean( (string) \pathinfo( $sanitized_name, \PATHINFO_EXTENSION ) );
        if ( $sanitized_name === '' ) {
            return [
                'ok' => false,
                'error' => 'Uploaded filename is invalid.',
            ];
        }

        if ( $this->hasBlockedExtension( $extension ) || $this->hasBlockedDoubleExtension( $sanitized_name ) ) {
            return [
                'ok' => false,
                'error' => 'Uploaded file type is not permitted.',
            ];
        }

        if ( $extension === '' || ! \in_array( $extension, $options['extensions'], true ) ) {
            return [
                'ok' => false,
                'error' => 'Uploaded file extension is not allowed.',
            ];
        }

        if ( ! \in_array( $mime_type, \array_values( $options['mimes'] ), true ) ) {
            return [
                'ok' => false,
                'error' => 'Uploaded file type is not allowed.',
            ];
        }

        if ( $options['max_size'] > 0 && $size > $options['max_size'] ) {
            return [
                'ok' => false,
                'error' => 'Uploaded file exceeds the maximum allowed size.',
            ];
        }

        return [
            'ok' => true,
            'options' => $options,
        ];
    }
}
