<?php

declare(strict_types=1);

namespace Metis\Core\Services;

final class CredentialService {
    private const REGISTRY_KEY = 'settings_credentials_registry';
    private const OPENSSL_ALGO = 'AES-256-CBC';
    private const SODIUM_ALGO = 'SODIUM_SECRETBOX';

    private static function hasSettingsService(): bool {
        return class_exists( '\Core_Settings_Service' );
    }

    public static function storeCredential( string $type, string $label, string $secret, string $id = '' ): string {
        $type = metis_key_clean( $type );
        $label = trim( metis_text_clean( $label ) );
        $secret = trim( $secret );
        if ( $type === '' || $secret === '' ) {
            return '';
        }

        $registry = self::registry();
        if ( $id !== '' && isset( $registry[ $id ] ) && is_array( $registry[ $id ] ) ) {
            $credential_id = $id;
        } else {
            $credential_id = self::generateId( $type );
        }

        $encrypted = self::encrypt( $secret );
        if ( $encrypted['cipher'] === '' ) {
            return '';
        }

        $registry[ $credential_id ] = [
            'id' => $credential_id,
            'type' => $type,
            'label' => $label !== '' ? $label : strtoupper( str_replace( '_', ' ', $type ) ),
            'cipher' => $encrypted['cipher'],
            'iv' => $encrypted['iv'],
            'algo' => $encrypted['algo'],
            'updated_at' => function_exists( 'metis_current_time' ) ? metis_current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ),
        ];

        if ( self::hasSettingsService() ) {
            \Core_Settings_Service::set( self::REGISTRY_KEY, array_values( $registry ), false );
        }
        return $credential_id;
    }

    public static function getCredential( string $id ): string {
        $id = trim( $id );
        if ( $id === '' ) {
            return '';
        }

        $registry = self::registry();
        $row = $registry[ $id ] ?? null;
        if ( ! is_array( $row ) ) {
            return '';
        }

        return self::decrypt(
            (string) ( $row['cipher'] ?? '' ),
            (string) ( $row['iv'] ?? '' ),
            (string) ( $row['algo'] ?? 'AES-256-CBC' )
        );
    }

    public static function listCredentials( string $type = '' ): array {
        $registry = self::registry();
        if ( $type === '' ) {
            return array_values( $registry );
        }

        $type = metis_key_clean( $type );
        return array_values( array_filter( $registry, static function ( $row ) use ( $type ): bool {
            return is_array( $row ) && (string) ( $row['type'] ?? '' ) === $type;
        } ) );
    }

    public static function getBySetting( string $setting_key, string $legacy_key = '' ): string {
        if ( ! self::hasSettingsService() ) {
            return '';
        }

        $credential_id = trim( (string) \Core_Settings_Service::get( $setting_key . '_credential_id', '' ) );
        if ( $credential_id !== '' ) {
            $value = self::getCredential( $credential_id );
            if ( $value !== '' ) {
                return $value;
            }
        }

        $legacy = $legacy_key !== '' ? $legacy_key : $setting_key;
        return (string) \Core_Settings_Service::get( $legacy, '' );
    }

    private static function registry(): array {
        if ( ! self::hasSettingsService() ) {
            return [];
        }

        $raw = \Core_Settings_Service::get( self::REGISTRY_KEY, [] );
        $rows = is_array( $raw ) ? $raw : [];
        $indexed = [];
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $id = trim( (string) ( $row['id'] ?? '' ) );
            if ( $id === '' ) {
                continue;
            }
            $indexed[ $id ] = $row;
        }
        return $indexed;
    }

    private static function generateId( string $type ): string {
        return $type . '_' . strtolower( bin2hex( random_bytes( 8 ) ) );
    }

    private static function keyMaterial(): string {
        $seed = '';
        if ( defined( 'AUTH_KEY' ) ) {
            $seed = (string) AUTH_KEY;
        }
        if ( $seed === '' && function_exists( 'metis_home_url' ) ) {
            $seed = (string) metis_home_url( '/' );
        }
        if ( $seed === '' ) {
            $seed = __FILE__;
        }
        return hash( 'sha256', $seed, true );
    }

    private static function opensslAvailable(): bool {
        return function_exists( 'openssl_encrypt' )
            && function_exists( 'openssl_decrypt' )
            && function_exists( 'openssl_cipher_iv_length' )
            && function_exists( 'random_bytes' );
    }

    private static function sodiumAvailable(): bool {
        return function_exists( 'sodium_crypto_secretbox' )
            && function_exists( 'sodium_crypto_secretbox_open' )
            && defined( 'SODIUM_CRYPTO_SECRETBOX_NONCEBYTES' )
            && function_exists( 'random_bytes' );
    }

    private static function encryptionAlgorithm(): string {
        if ( self::sodiumAvailable() ) {
            return self::SODIUM_ALGO;
        }

        if ( self::opensslAvailable() ) {
            return self::OPENSSL_ALGO;
        }

        throw new \RuntimeException( 'Credential encryption requires libsodium or OpenSSL.' );
    }

    private static function encrypt( string $plaintext ): array {
        $algo = self::encryptionAlgorithm();

        if ( $algo === self::SODIUM_ALGO ) {
            $nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
            $cipher = sodium_crypto_secretbox( $plaintext, $nonce, self::keyMaterial() );
            if ( $cipher === false || $cipher === '' ) {
                throw new \RuntimeException( 'Credential encryption failed using libsodium.' );
            }

            return [
                'cipher' => base64_encode( $cipher ),
                'iv' => base64_encode( $nonce ),
                'algo' => self::SODIUM_ALGO,
            ];
        }

        $iv_length = openssl_cipher_iv_length( self::OPENSSL_ALGO );
        if ( ! is_int( $iv_length ) || $iv_length <= 0 ) {
            throw new \RuntimeException( 'Credential encryption failed to resolve an IV length.' );
        }

        $iv = random_bytes( $iv_length );
        $cipher = openssl_encrypt( $plaintext, self::OPENSSL_ALGO, self::keyMaterial(), OPENSSL_RAW_DATA, $iv );
        if ( ! is_string( $cipher ) || $cipher === '' ) {
            throw new \RuntimeException( 'Credential encryption failed using OpenSSL.' );
        }

        return [
            'cipher' => base64_encode( $cipher ),
            'iv' => base64_encode( $iv ),
            'algo' => self::OPENSSL_ALGO,
        ];
    }

    private static function decrypt( string $cipher_b64, string $iv_b64, string $algo ): string {
        if ( $cipher_b64 === '' ) {
            return '';
        }

        $normalized_algo = strtoupper( trim( $algo ) );
        if ( $normalized_algo === 'BASE64' ) {
            throw new \RuntimeException( 'Legacy BASE64 credential storage is no longer supported.' );
        }

        $cipher = base64_decode( $cipher_b64, true );
        if ( ! is_string( $cipher ) || $cipher === '' ) {
            return '';
        }

        if ( $normalized_algo === self::SODIUM_ALGO ) {
            if ( ! self::sodiumAvailable() ) {
                throw new \RuntimeException( 'Credential decryption requires libsodium for SODIUM_SECRETBOX payloads.' );
            }

            $nonce = base64_decode( $iv_b64, true );
            if ( ! is_string( $nonce ) || strlen( $nonce ) !== SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
                return '';
            }

            $plain = sodium_crypto_secretbox_open( $cipher, $nonce, self::keyMaterial() );
            return is_string( $plain ) ? $plain : '';
        }

        if ( $normalized_algo !== self::OPENSSL_ALGO ) {
            throw new \RuntimeException( 'Unsupported credential encryption algorithm.' );
        }

        if ( ! self::opensslAvailable() ) {
            throw new \RuntimeException( 'Credential decryption requires OpenSSL for AES-256-CBC payloads.' );
        }

        $iv = base64_decode( $iv_b64, true );
        $iv_length = openssl_cipher_iv_length( self::OPENSSL_ALGO );
        if ( ! is_string( $iv ) || ! is_int( $iv_length ) || strlen( $iv ) !== $iv_length ) {
            return '';
        }

        $plain = openssl_decrypt( $cipher, self::OPENSSL_ALGO, self::keyMaterial(), OPENSSL_RAW_DATA, $iv );
        return is_string( $plain ) ? $plain : '';
    }
}
