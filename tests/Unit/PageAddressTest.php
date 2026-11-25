<?php

declare(strict_types=1);

/**
 * The addresses of the pages a grid gives, each the kind its setting needs: the web search and the profiles a page, never the search
 * the simulators call nor Robust; the search in the world the script it calls. A migration and an upgrade put a wrong one right.
 */

use W4OS\Engine\Provisioning\Web\PageAddress;

describe('The address of a page', function () {
    test('is wrong at the search the simulators call or at Robust, right as a page, and not judged when empty or not read', function () {
        $robust = ['http://grid.example.org:8002', 'http://10.0.0.5:8003'];
        $why = static fn(string $section, string $key, string $address): ?string => PageAddress::invalid($section, $key, $address, $robust);

        expect($why('GridInfoService', 'search', 'https://grid.example.org/helper/query.php'))->toContain('XML-RPC')
            ->and($why('LoginService', 'SearchURL', 'http://grid.example.org:8002/'))->toContain('address of Robust')
            ->and($why('GridInfoService', 'web_profile_url', 'http://10.0.0.5:8003/profile/[AGENT_NAME]'))->toContain('address of Robust')
            ->and($why('GridInfoService', 'web_profile_url', 'https://grid.example.org/profile.php?name=[AGENT_NAME]'))->toContain('a script')
            // Right: a page, an older page in PHP for the search, nothing to judge
            ->and($why('GridInfoService', 'web_profile_url', 'https://grid.example.org/opensim/profile/[AGENT_NAME]'))->toBeNull()
            ->and($why('GridInfoService', 'search', 'https://grid.example.org/search.php'))->toBeNull()
            ->and($why('GridInfoService', 'search', ''))->toBeNull()
            ->and($why('GridInfoService', 'search', '${Const|WebURL}/helper/query.php'))->toBeNull()
            ->and($why('GridInfoService', 'welcome', 'https://grid.example.org/helper/query.php'))->toBeNull();
    });

    test('of the search in the world is the script of the helpers, never a page', function () {
        expect(PageAddress::invalid('Search', 'SearchURL', 'https://grid.example.org/helpers/query.php'))->toBeNull()
            ->and(PageAddress::invalid('Search', 'SearchURL', 'https://grid.example.org/search'))->toContain('query.php');
    });
});
