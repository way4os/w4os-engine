<?php

declare(strict_types=1);

/**
 * The website of a grid as a preset: the light site of the package w4os-site, in the folder its package makes.
 */

use W4OS\Engine\Provisioning\Presets;

describe('The preset of the website', function () {
    test('is the site of w4os-site, in /var/lib/w4os/www, and names no package of before', function () {
        $website = (new Presets())->options()['webroot-website'];
        $file = (string) file_get_contents(dirname(__DIR__, 2) . '/share/presets/webroot-website/option.conf');

        expect($website->paths())
            ->toBe(['/var/lib/w4os/www'])
            ->and($website->variables()['docroot'] ?? null)
            ->toBe('/var/lib/w4os/www')
            ->and($website->pathNote())
            ->toContain('w4os-site')
            ->and($file)
            ->not->toContain('opensim-web');
    });
});
