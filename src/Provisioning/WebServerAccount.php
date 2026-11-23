<?php

declare(strict_types=1);

namespace W4OS\Engine\Provisioning;

/**
 * The account and group a web server of this machine serves a site as, read in its configs: Apache (AssignUserID of mpm-itk, a
 * pool of PHP-FPM, else its own account), nginx and Caddy (the pool of PHP-FPM their PHP goes to, else their own account).
 */
final class WebServerAccount
{
    /** @param string $etc the folder of the configs (/etc) */
    public function __construct(private readonly string $etc = '/etc') {}

    /**
     * The account the first of these hosts is served as.
     *
     * @param list<string> $hosts
     * @return array{user:string,group:string,server:string,file:string}|null null when no web server of this machine serves them
     */
    public function serving(array $hosts): ?array
    {
        foreach ($hosts as $host) {
            foreach ($this->sites() as [$server, $file, $names, $block]) {
                if (in_array(strtolower($host), $names, true) && ($account = $this->account($server, $block)) !== null) {
                    return $account + ['server' => $server, 'file' => $file];
                }
            }
        }

        return null;
    }

    /**
     * The sites the web servers of this machine serve: [server, file, its names, its config].
     *
     * @return list<array{0:string,1:string,2:list<string>,3:string}>
     */
    private function sites(): array
    {
        $sites = [];
        foreach ($this->files('apache2/sites-enabled/*') as $file) {
            preg_match_all('#<VirtualHost[^>]*>(.*?)</VirtualHost>#is', (string) file_get_contents($file), $found);
            foreach ($found[1] as $block) {
                preg_match_all('/^\s*Server(?:Name|Alias)\s+(.+)$/mi', $block, $names);
                $sites[] = ['apache', $file, self::names(implode(' ', $names[1])), $block];
            }
        }
        foreach ([...$this->files('nginx/sites-enabled/*'), ...$this->files('nginx/conf.d/*.conf')] as $file) {
            foreach (self::blocks((string) file_get_contents($file), '/(?:^|[;{}\s])server\s*\{/') as $block) {
                preg_match_all('/^\s*server_name\s+([^;]+);/m', $block, $names);
                $sites[] = ['nginx', $file, self::names(implode(' ', $names[1])), $block];
            }
        }
        foreach ([...$this->files('caddy/Caddyfile'), ...$this->files('caddy/*/*.caddyfile'), ...$this->files('caddy/*.caddyfile')] as $file) {
            foreach (self::blocks((string) file_get_contents($file), '/^([^\s#{][^{\n]*)\{/m', $addresses) as $i => $block) {
                $sites[] = ['caddy', $file, self::names((string) preg_replace('#[a-z]+://|:\d+#i', '', $addresses[$i])), $block];
            }
        }

        return $sites;
    }

    /** @return array{user:string,group:string}|null */
    private function account(string $server, string $site): ?array
    {
        if ($server === 'apache' && preg_match('/^\s*AssignUserID\s+(\S+)\s+(\S+)/mi', $site, $m)) {
            return ['user' => $m[1], 'group' => $m[2]];
        }
        // The pool of PHP-FPM its PHP goes to, by its socket
        if (preg_match('#(?:proxy:unix:|fastcgi_pass\s+unix:|php_fastcgi\s+unix/)([^\s|;"]+)#', $site, $m) && ($pool = $this->pool($m[1])) !== null) {
            return $pool;
        }
        $user = match ($server) {
            'apache' => self::value($this->text('apache2/envvars'), '/^\s*export\s+APACHE_RUN_USER=\$\{APACHE_RUN_USER:-([^}]+)\}|^\s*export\s+APACHE_RUN_USER=(\S+)/m'),
            'nginx' => self::value($this->text('nginx/nginx.conf'), '/^\s*user\s+([^;\s]+)/m'),
            default => '',
        };
        $group = match ($server) {
            'apache' => self::value($this->text('apache2/envvars'), '/^\s*export\s+APACHE_RUN_GROUP=\$\{APACHE_RUN_GROUP:-([^}]+)\}|^\s*export\s+APACHE_RUN_GROUP=(\S+)/m'),
            'nginx' => self::value($this->text('nginx/nginx.conf'), '/^\s*user\s+[^;\s]+\s+([^;\s]+)/m'),
            default => '',
        };
        $user = $user !== '' ? $user : ($server === 'caddy' ? 'caddy' : '');

        return $user === '' ? null : ['user' => $user, 'group' => $group !== '' ? $group : ServiceUnits::groupOf($user)];
    }

    /** @return array{user:string,group:string}|null the account of the pool of PHP-FPM that listens on this socket */
    private function pool(string $socket): ?array
    {
        foreach ($this->files('php/*/fpm/pool.d/*.conf') as $file) {
            $text = (string) file_get_contents($file);
            if (self::value($text, '/^\s*listen\s*=\s*(\S+)/m') === $socket && ($user = self::value($text, '/^\s*user\s*=\s*(\S+)/m')) !== '') {
                return ['user' => $user, 'group' => self::value($text, '/^\s*group\s*=\s*(\S+)/m') ?: ServiceUnits::groupOf($user)];
            }
        }

        return null;
    }

    /** @return list<string> */
    private function files(string $pattern): array
    {
        return array_values(array_filter(glob("$this->etc/$pattern") ?: [], 'is_file'));
    }

    private function text(string $file): string
    {
        return is_file("$this->etc/$file") ? (string) file_get_contents("$this->etc/$file") : '';
    }

    /** The first group a pattern captures, empty when it does not match. */
    private static function value(string $text, string $pattern): string
    {
        return preg_match($pattern, $text, $m) ? (string) (array_values(array_filter(array_slice($m, 1), 'strlen'))[0] ?? '') : '';
    }

    /** @return list<string> the names of a list of them (spaces or commas between), in small letters */
    private static function names(string $list): array
    {
        return array_values(array_filter(array_map('strtolower', preg_split('/[\s,]+/', trim($list)) ?: []), 'strlen'));
    }

    /**
     * The blocks of a config (between braces) that begin where a pattern matches, with what the pattern captured before each.
     *
     * @param list<string> $captured
     * @return list<string>
     */
    private static function blocks(string $text, string $pattern, ?array &$captured = []): array
    {
        $blocks = [];
        $captured = [];
        preg_match_all($pattern, $text, $found, PREG_OFFSET_CAPTURE);
        foreach ($found[0] as $i => [$match, $offset]) {
            $start = $offset + strlen($match);
            for ($depth = 1, $at = $start; $at < strlen($text) && $depth > 0; $at++) {
                $depth += $text[$at] === '{' ? 1 : ($text[$at] === '}' ? -1 : 0);
            }
            $blocks[] = substr($text, $start, $at - $start - 1);
            $captured[] = trim((string) ($found[1][$i][0] ?? ''));
        }

        return $blocks;
    }
}
