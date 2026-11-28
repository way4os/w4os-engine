<?php

/**
 * The engine API over HTTP: the entry point a web server hands the requests to (`/api/...`), the engine alone, without the helpers.
 * Its users are the sites of this machine and whoever has a key (opensim api-key create); run it as the user of the instances to
 * let it change the grids (opensim web GRID api).
 */

declare(strict_types=1);

// Its own libraries, the ones of the project it is a dependency of, or the ones its pool names (a clone linked to a command line)
foreach ([dirname(__DIR__) . '/vendor/autoload.php', dirname(__DIR__, 3) . '/autoload.php', (string) getenv('W4OS_AUTOLOAD')] as $autoload) {
    if (is_file($autoload)) {
        require $autoload;

        break;
    }
}

W4OS\Engine\Api\Server::serve(W4OS\Engine\OpenSim::environment());
