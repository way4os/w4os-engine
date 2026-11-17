<?php

/**
 * The engine API over HTTP: the entry point a web server hands the requests to (`/api/...`), the engine alone, without the helpers.
 * Its users are the sites of this machine and whoever has a key (opensim api-key create); run it as the user of the instances to
 * let it change the grids (opensim web GRID api).
 */

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

OpenSim\Api\Server::serve(OpenSim\OpenSim::environment());
