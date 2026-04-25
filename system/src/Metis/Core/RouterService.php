<?php
declare(strict_types=1);

namespace Metis\Core;

use Metis\Http\Request;
use Metis\Http\Response;
use Metis\Http\Router;

final class RouterService {
    private $builder;
    private ?Router $router = null;

    public function __construct( ?callable $builder = null ) {
        $this->builder = $builder;
    }

    public function set_builder( callable $builder ): void {
        if ( $this->builder === $builder ) {
            return;
        }

        $this->builder = $builder;
        $this->router  = null;
    }

    public function router(): Router {
        if ( $this->router instanceof Router ) {
            return $this->router;
        }

        if ( ! is_callable( $this->builder ) ) {
            throw new \RuntimeException( 'Router builder has not been configured.' );
        }

        if ( \class_exists( 'Profiler', false ) ) {
            \Profiler::mark( 'ROUTER_LOAD_ROUTES' );
        }
        $router = call_user_func( $this->builder );
        if ( \class_exists( 'Profiler', false ) ) {
            \Profiler::mark( 'ROUTER_LOAD_ROUTES_DONE' );
        }
        if ( ! $router instanceof Router ) {
            throw new \RuntimeException( 'Router builder did not return a Metis\Http\Router.' );
        }

        $this->router = $router;
        return $this->router;
    }

    public function dispatch( Request $request ): Response {
        if ( \class_exists( 'Profiler', false ) ) {
            \Profiler::mark( 'ROUTER_DISPATCH' );
        }

        if ( Application::has_service( 'error_kernel' ) ) {
            /** @var \Metis\Core\Error\ErrorKernel $kernel */
            $kernel = Application::service( 'error_kernel' );
            $kernel->captureRequest( $request );
        }

        return $this->router()->dispatch( $request );
    }
}
