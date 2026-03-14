<?php
declare(strict_types=1);

namespace Metis\Hermes;

use Metis\Core\Application;

final class HermesExecutionEngine {
    public function execute( array $command, array $payload = [] ): array {
        $serviceCall = (array) ( $command['service'] ?? [] );
        $serviceName = \sanitize_key( (string) ( $serviceCall['service'] ?? '' ) );
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
}
