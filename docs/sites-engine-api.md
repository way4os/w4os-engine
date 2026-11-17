# The engine API for the sites of this machine

A site (the light site, the WordPress plugin) reads a grid with the engine it bundles, as its web server user (`www-data`). That is
enough to read, never to change what belongs to the grid: its files are its user's (`opensim:www-data 640`), its instances run as
that user. What a site changes (a region enabled or its roles, a backup, a restore, an instance started) goes through the engine API
run as the user of the instances: the same API, closed by keys, served on `127.0.0.1` for the sites of this machine only.

Nothing else is needed: no sudo, no folder the web server may write.

## How the API is served

The engine has its own entry point, `public/index.php` (`/usr/share/opensim-engine/public` with the package): a web server hands it
the requests, `Api\Server` answers `/api/...` with the Router, with the keys of the install (`opensim api-key create|list|revoke`,
`Authorization: Bearer`). It needs no framework and no helpers: the engine can be installed alone. `OPENSIM_GRID` names the grid of
the short paths (`/api/avatars`), `OPENSIM_API_BASE` another base than `/api`.

It runs as the user of the PHP pool the web server hands it to. As the web server (`www-data`), an answer the API cannot give as that
user is a clean refusal: `config-not-writable` (a file of the grid), `instance-user-required` (an instance, a backup, a restore),
never a 500.

The helpers also mount the API under their base (`/helpers/api`): the same Router, with the tasks of their search.

## Running it as the user of the instances

`opensim web GRID api caddy|nginx|apache` prints the two pieces:

1. a PHP-FPM pool, `opensim-api`, run as the user of the instances (`opensim`), its socket `/run/php/opensim-api.sock` reached by
   the group of the web server only (`--web-group`, `www-data` by default, `caddy` for Caddy):

   ```bash
   opensim web friendly_fowl api caddy > /tmp/api.txt
   ```

   The first part goes to `/etc/php/<version>/fpm/pool.d/opensim-api.conf`, then `systemctl reload php<version>-fpm`.

2. a listener on `127.0.0.1:8780` (`--port`) that serves the paths of the API and nothing else, through that pool, to the entry
   point of the engine (`--docroot` for another one): a site block
   of its own for Caddy, a server block for Nginx, a virtual host for Apache (PHP-FPM with proxy_fcgi, never mod_php, which runs as
   the web server).

The public site of the grid keeps its own pool: its pages, the services the viewers and the simulators call, are not run as the user
of the instances.

## The site

A key for it, made by the user of the instances:

```bash
opensim api-key create mini-site
```

In the site, the engine is remote, at that listener, with that key. For the WordPress plugin and the light site (grav-plugin-w4os):

```yaml
engine: remote
url: https://grid.example.org/helpers   # the public helpers, for the blocks
api_url: http://127.0.0.1:8780/api      # the engine API, run as the user of the instances
api_key: <the key>
```

The site then reads through the API too: it has one way to the grid, and the bundled engine is not needed. A site that would rather
read with its bundled engine and only write through the API needs both (`engine: bundled` for the reads, `api_url` and the key for
the writes): that split belongs to the bridge, the engine needs nothing for it.
