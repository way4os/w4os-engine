<?php

declare(strict_types=1);

/**
 * What the engine says outside its errors (a reminder, a note): a stable code and its values, for a site to say it in its language,
 * with the English text as its detail. The texts are in the catalog (`notice_<code>`, the values as {name}).
 */

use W4OS\Engine\I18n\Catalog;
use W4OS\Engine\Text\Notice;

describe('A notice', function () {
    test('has a code, its values and the English text made of them', function () {
        $notice = Notice::of('robust-restart-roles', ['key' => 'Region_Welcome', 'restart' => 'opensim restart demo']);

        expect($notice)
            ->toBe([
                'code' => 'robust-restart-roles',
                'params' => ['key' => 'Region_Welcome', 'restart' => 'opensim restart demo'],
                'detail' => 'Robust reads Region_Welcome when it starts: restart Robust (opensim restart demo) so it gives the region these roles when it registers again.',
            ])
            ->and(fn() => Notice::of('no-such-notice'))
            ->toThrow(InvalidArgumentException::class);
    });

    test('has a text in the catalog for every code, every value of it named there, in every language', function () {
        $missing = [];
        foreach (Notice::CODES as $code => $params) {
            $text = Catalog::MESSAGES[Notice::key($code)] ?? null;
            $text !== null || ($missing[] = $code);
            foreach ($params as $param) {
                $text === null || str_contains($text, '{' . $param . '}') || ($missing[] = "$code {{$param}}");
            }
        }

        expect($missing)->toBe([])
            ->and(Catalog::notice('fr', 'plan-reserves-nothing', []))
            ->not->toBe(Catalog::MESSAGES['notice_plan_reserves_nothing']);
    });
});
