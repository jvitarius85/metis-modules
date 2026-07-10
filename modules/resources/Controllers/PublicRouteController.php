<?php
declare(strict_types=1);

namespace Metis\Modules\Resources\Controllers;

use Metis\Http\Request;
use Metis\Http\Response;
use Metis\Modules\Resources\Repository;
use Metis\Modules\Resources\Requests\PublicRouteRequest;

final class PublicRouteController {
    public static function handle( Request $request ): Response {
        $routeRequest = PublicRouteRequest::fromRequest( $request );

        $html = Repository::renderPublicRoute(
            $routeRequest->type(),
            $routeRequest->category(),
            $routeRequest->resource(),
            $routeRequest->query()
        );

        if ( $html === null ) {
            return new Response(
                404,
                [ 'Content-Type' => 'text/html; charset=utf-8' ],
                '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Not Found</title></head><body><main><h1>Not Found</h1><p>The requested resource could not be found.</p></main></body></html>'
            );
        }

        return new Response( 200, [ 'Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'public, max-age=300' ], $html );
    }
}
