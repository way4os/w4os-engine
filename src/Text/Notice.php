<?php

declare(strict_types=1);

namespace OpenSim\Text;

use OpenSim\I18n\Catalog;

/**
 * What the engine says outside its errors (a reminder, a note of a plan, a message of the API), the way it says a problem: a stable
 * code and its values, for a site to say it in its language (Catalog::notice(), `notice_<code>` of the catalog, the values as
 * {name}), with the English text as its detail.
 *
 *     Notice::of('robust-restart-roles', ['key' => 'Region_Welcome', 'restart' => 'opensim restart demo']);
 *     // ['code' => 'robust-restart-roles', 'params' => [...], 'detail' => 'Robust reads Region_Welcome when it starts: ...']
 */
final class Notice
{
    /** The codes, with the names of their values. */
    public const CODES = [
        // The roles of a region (Region::update())
        'robust-restart-roles' => ['key', 'restart'],
        'robust-config-line' => ['file', 'section', 'line', 'restart'],
        'robust-config-remove' => ['file', 'section', 'key', 'restart'],
        'robust-config-elsewhere' => ['section', 'line'],
        // The plan of a setup file (SetupApply)
        'start-simulator' => ['slug', 'command'],
        'start-simulator-regions' => ['slug', 'command'],
        'region-roles-later' => ['region', 'roles', 'slug', 'restart', 'command'],
        'plan-reserves-nothing' => [],
        // The plan of an import (SimulatorImport)
        'import-read-access' => [],
        'import-stop-source' => ['regions'],
        'import-enable-start' => ['enable', 'start'],
        // The plan of a restore (Archives\BackupRestore)
        'restore-start' => ['instance', 'command'],
        // The messages of the API
        'mail-sent-if-known' => [],
        'mail-sent' => [],
        'registered' => ['name'],
        'registered-confirm' => ['email'],
        'registered-mail-failed' => ['email'],
    ];

    /**
     * A notice: its code, its values, its English text.
     *
     * @param array<string,string|int> $params by the names CODES gives
     * @return array{code:string,params:array<string,string|int>,detail:string}
     */
    public static function of(string $code, array $params = []): array
    {
        isset(self::CODES[$code]) || throw new \InvalidArgumentException("'$code' is not a notice");

        return ['code' => $code, 'params' => $params, 'detail' => Catalog::notice(Catalog::SOURCE, $code, $params)];
    }

    /** The key of its text in the catalog. */
    public static function key(string $code): string
    {
        return 'notice_' . str_replace('-', '_', $code);
    }
}
