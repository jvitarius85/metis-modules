<?php
declare(strict_types=1);

namespace Metis\Modules\Hermes;

final class Support {
    public static function baseUrl(): string {
        return rtrim( \metis_portal_url( 'hermes' ), '/' );
    }
}
