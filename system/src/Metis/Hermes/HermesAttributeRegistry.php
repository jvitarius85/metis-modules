<?php
declare(strict_types=1);

namespace Metis\Hermes;

final class HermesAttributeRegistry {
    private const DEFINITIONS = [
        'email' => [
            'label' => 'Email',
            'aliases' => [ 'email', 'email address', 'e-mail', 'e-mail address' ],
        ],
        'phone' => [
            'label' => 'Phone',
            'aliases' => [ 'phone', 'phone number', 'telephone', 'mobile', 'mobile number', 'cell' ],
        ],
        'address' => [
            'label' => 'Address',
            'aliases' => [ 'address', 'mailing address', 'street address', 'location' ],
        ],
        'role' => [
            'label' => 'Role',
            'aliases' => [ 'role', 'user role', 'roles', 'position' ],
        ],
        'status' => [
            'label' => 'Status',
            'aliases' => [ 'status', 'account status', 'active' ],
        ],
        'volunteer_status' => [
            'label' => 'Volunteer Status',
            'aliases' => [ 'volunteer', 'volunteer status' ],
        ],
        'groups' => [
            'label' => 'Groups',
            'aliases' => [ 'groups', 'group', 'workspace groups', 'google groups' ],
        ],
        'permissions' => [
            'label' => 'Permissions',
            'aliases' => [ 'permissions', 'permission', 'access', 'access level' ],
        ],
        'name' => [
            'label' => 'Name',
            'aliases' => [
                'name', 'full name', 'display name',
                'whose email is this', 'who\'s email is this', 'whose phone is this', 'who\'s phone is this',
                'whose number is this', 'who\'s number is this', 'whose address is this', 'who\'s address is this',
                'whose identifier is this', 'who is this email', 'who is this phone', 'who is this address',
            ],
        ],
        'created_at' => [
            'label' => 'Created At',
            'aliases' => [ 'created', 'created at', 'joined', 'join date' ],
        ],
        'last_login_at' => [
            'label' => 'Last Login',
            'aliases' => [ 'last login', 'last seen', 'last active' ],
        ],
        'birthday' => [
            'label' => 'Birthday',
            'aliases' => [ 'birthday', 'birth date', 'date of birth' ],
        ],
    ];

    public function definitions(): array {
        return self::DEFINITIONS;
    }

    public function definition( string $attribute ): ?array {
        $key = $this->resolve( $attribute );
        return $key !== null ? ( self::DEFINITIONS[ $key ] ?? null ) : null;
    }

    public function resolve( string $raw ): ?string {
        $key = strtolower( trim( $raw ) );
        if ( isset( self::DEFINITIONS[ $key ] ) ) {
            return $key;
        }

        foreach ( self::DEFINITIONS as $canonical => $definition ) {
            foreach ( (array) ( $definition['aliases'] ?? [] ) as $alias ) {
                if ( strtolower( trim( (string) $alias ) ) === $key ) {
                    return $canonical;
                }
            }
        }

        return null;
    }

    public function detectFromQuery( string $query ): ?string {
        $query = strtolower( trim( $query ) );
        $bestMatch = '';
        $bestAttribute = null;

        foreach ( self::DEFINITIONS as $canonical => $definition ) {
            foreach ( (array) ( $definition['aliases'] ?? [] ) as $alias ) {
                $alias = strtolower( trim( (string) $alias ) );
                if ( $alias === '' || ! str_contains( $query, $alias ) ) {
                    continue;
                }

                if ( strlen( $alias ) > strlen( $bestMatch ) ) {
                    $bestMatch = $alias;
                    $bestAttribute = $canonical;
                }
            }
        }

        return $bestAttribute;
    }

    public function supportedAttributes(): array {
        return array_keys( self::DEFINITIONS );
    }
}
