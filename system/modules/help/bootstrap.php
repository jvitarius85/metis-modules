<?php
declare(strict_types=1);

if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

// Help routes and admin actions load the store on demand.
// Keeping the module bootstrap side-effect free avoids repeated global
// seeding/schema checks during unrelated requests.
