<?php
declare(strict_types=1);

namespace Metis\Hermes;

use Metis\Core\Application;

final class HermesExecutionEngine {
    public function execute( array $command, array $payload = [] ): array {
        $this->assertPayloadMatchesSchema( $payload, (array) ( $command['input_schema'] ?? [] ) );

        $serviceCall = (array) ( $command['service'] ?? [] );
        $serviceName = \metis_key_clean( (string) ( $serviceCall['service'] ?? '' ) );
        $method      = (string) ( $serviceCall['method'] ?? '' );

        if ( $serviceName === '' || $method === '' ) {
            throw new \RuntimeException( 'Hermes command service is not configured.' );
        }

        $service = Application::service( $serviceName );
        if ( ! is_object( $service ) || ! method_exists( $service, $method ) ) {
            throw new \RuntimeException( sprintf( 'Hermes command service [%s::%s] is unavailable.', $serviceName, $method ) );
        }

        $arguments = [];
        foreach ( (array) ( $serviceCall['arguments'] ?? [] ) as $argument ) {
            $arguments[] = $argument;
        }

        foreach ( (array) ( $serviceCall['arguments_from_payload'] ?? [] ) as $payloadKey ) {
            $arguments[] = $payload[ (string) $payloadKey ] ?? null;
        }

        /** @var callable $callable */
        $callable = [ $service, $method ];
        $result   = call_user_func_array( $callable, $arguments );

        return is_array( $result ) ? $result : [ 'status' => 'completed', 'result' => $result ];
    }

    private function assertPayloadMatchesSchema( array $payload, array $schema, string $path = 'payload' ): void {
        $type = \metis_key_clean( (string) ( $schema['type'] ?? 'object' ) );

        if ( $type === 'object' ) {
            $required = array_values( array_filter( array_map( 'strval', (array) ( $schema['required'] ?? [] ) ) ) );
            foreach ( $required as $field ) {
                if ( ! array_key_exists( $field, $payload ) ) {
                    throw new \RuntimeException( sprintf( 'Hermes command payload is missing required field [%s].', $path . '.' . $field ) );
                }
            }

            foreach ( (array) ( $schema['properties'] ?? [] ) as $field => $fieldSchema ) {
                if ( ! array_key_exists( (string) $field, $payload ) ) {
                    continue;
                }

                if ( is_array( $fieldSchema ) ) {
                    $this->assertValueMatchesSchema( $payload[ (string) $field ], $fieldSchema, $path . '.' . (string) $field );
                }
            }
        }
    }

    private function assertValueMatchesSchema( mixed $value, array $schema, string $path ): void {
        $type = \metis_key_clean( (string) ( $schema['type'] ?? '' ) );

        if ( $type === 'string' && ! is_string( $value ) ) {
            throw new \RuntimeException( sprintf( 'Hermes command payload field [%s] must be a string.', $path ) );
        }

        if ( $type === 'object' ) {
            if ( ! is_array( $value ) ) {
                throw new \RuntimeException( sprintf( 'Hermes command payload field [%s] must be an object.', $path ) );
            }

            $this->assertPayloadMatchesSchema( $value, $schema, $path );
        }
    }
}
