<?php

declare(strict_types=1);

namespace W4OS\Engine\Provisioning;

use W4OS\Engine\Config\ConfigReader;
use W4OS\Engine\Config\IniEditor;

/**
 * The accounts of an install that exists, taken as they are: the one its files belong to, which runs its instances, and the one
 * of the web server that serves its site. The suite adopts them (SystemUser, WebUser, WebGroup of w4os.conf) instead of its
 * defaults, and changes nothing of the install.
 */
final class InstallAccount
{
    /** The account the files of an install belong to: the owner of its config, its number when it has no name. */
    public static function owner(string $file): string
    {
        $uid = (int) @fileowner($file);

        return function_exists('posix_getpwuid') ? (string) (posix_getpwuid($uid)['name'] ?? $uid) : (string) $uid;
    }

    /**
     * The addresses of the site of a grid, by its Robust config: the hosts of its web address, its pages and its own name.
     *
     * @return list<string>
     */
    public static function hosts(string $robustIni): array
    {
        $config = ConfigReader::expand(ConfigReader::load($robustIni));
        $hosts = [];
        foreach ([['Const', 'WebURL'], ['GridInfoService', 'homepage'], ['GridInfoService', 'welcome'], ['GridInfoService', 'register'], ['Const', 'BaseURL']] as [$section, $key]) {
            $host = parse_url(trim((string) ($config[$section][$key] ?? ''), '"'), PHP_URL_HOST);
            is_string($host) && $host !== '' && $hosts[] = strtolower($host);
        }
        $base = trim((string) ($config['Const']['BaseHostname'] ?? ''), '"');
        $base === '' || $hosts[] = strtolower($base);

        return array_values(array_unique($hosts));
    }

    /**
     * What w4os.conf would change to adopt these accounts: key => [what it has, what it would have], only the keys that change.
     *
     * @param array{user:string,group:string}|null $web the account of the web server, null to leave it as it is
     * @return array<string,array{0:string,1:string}>
     */
    public static function changes(string $conf, string $owner, ?array $web): array
    {
        $ini = is_file($conf) ? IniEditor::load($conf) : new IniEditor('');
        $wanted = ['SystemUser' => $owner] + ($web === null ? [] : ['WebUser' => $web['user'], 'WebGroup' => $web['group']]);
        $changes = [];
        foreach ($wanted as $key => $value) {
            $now = trim((string) $ini->get('Defaults', $key), " \t\"");
            $now === $value || $changes[$key] = [$now, $value];
        }

        return $changes;
    }

    /**
     * Write these changes in w4os.conf.
     *
     * @param array<string,array{0:string,1:string}> $changes as changes() gives them
     */
    public static function adopt(string $conf, array $changes): void
    {
        if ($changes === []) {
            return;
        }
        $ini = is_file($conf) ? IniEditor::load($conf) : new IniEditor('');
        foreach ($changes as $key => [, $value]) {
            $ini->set('Defaults', $key, $value);
        }
        $ini->save($conf);
    }
}
