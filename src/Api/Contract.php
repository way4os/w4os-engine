<?php

declare(strict_types=1);

namespace OpenSim\Api;

use OpenSim\Forms\ItemForm;
use OpenSim\Assets\Logos;
use OpenSim\Assets\WebAssets;
use OpenSim\Data\Listing;
use OpenSim\Models\Service;
use Symfony\Component\Yaml\Yaml;

/**
 * The contract of the resources of Api, as an OpenAPI 3.1 document: what is asked, what comes back, what is written. It is also the
 * reference of the API: the descriptions here are the text of the documentation (`composer docs` makes the pages), so this is where
 * to write them. The tests check it against the models: an attribute a model gives and the contract does not know is a failure, and
 * so is the other way round.
 */
final class Contract
{
    /**
     * The resources: where they are, what they are for, their attributes (`name => [type, description]`, `?` after the type for a value
     * that may be null), the filters of their list and the values these take (unless their model describes its list: Contract::listing()),
     * and what can be written.
     *
     * `scope` is `grid` for the resources of a grid (`/grids/{grid}/<type>`), `machine` for the ones of the machine, `application`
     * for what the application that uses the engine provides (its tasks, the forms of its setup).
     */
    /** The resources whose list is whole: not searched, sorted or paged. */
    private const UNPAGED = ['tasks', 'forms', 'destinations', 'fonts', 'avatar-models'];

    public const RESOURCES = [
        'grids' => [
            'scope' => 'machine',
            'summary' => 'The grids of this machine',
            'description' =>
                'A grid run from this machine, only described here (a grid joined on another machine), or a standalone (one simulator that serves it all).',
            'attributes' => [
                'nick' => ['string', 'What names its folder, its databases and its instances (snake_case)'],
                'name' => ['string', 'The name shown'],
                'slug' => ['string', 'The name made for an address (kebab-case)'],
                'sources' => ['array', 'Where it is known from: folder, plan, described, announced'],
                'access' => ['array', 'What the engine reaches of it: `files` its config on this machine, `settings` where its settings are changed (its files, or the settings the application keeps for the engine), `databases` the databases its config sets, `http` an address of its own to ask. An operation that needs what a grid has not (`needs` of `x-access`) is unavailable for it (503, its reason): a site hides it'],
                'directory' => ['string?', 'Its folder'],
                'remote' => ['boolean', 'Its Robust is on another machine'],
                'standalone' => ['boolean', 'One simulator serves the grid itself, no Robust runs'],
                'planned' => ['boolean', 'Not made yet'],
                'enabled' => [
                    'boolean?',
                    'Its Robust (or its simulator, for a standalone) is enabled: null when it is not on this machine',
                ],
                'hypergrid' => ['boolean', 'Open to the other grids'],
                'uri' => ['string?', 'Its URI, host:port of its login, as viewers and other grids reach it'],
                'baseHostname' => ['string', 'The host its viewers log in to'],
                'publicPort' => ['integer', 'The port of the viewers and of the other grids'],
                'privatePort' => ['integer', 'The port of its simulators'],
                'center' => ['string?', 'The center of its map (x,y)'],
                'regionSpacing' => ['integer', 'The free blocks its regions leave between them'],
                'preset' => ['string?', 'The preset of the grid it joins (OSGrid...)'],
                'coreDirectory' => ['string?', 'The OpenSimulator release it runs'],
                'robustIni' => ['string?', 'The config of its Robust'],
                'connectors' => ['array', 'The services its Robust serves (the keys of [ServiceList])'],
                'regionFlags' => ['array', 'The roles its Robust gives to regions'],
                'roles' => ['array', 'The roles a region of the grid can have'],
                'missingRoles' => ['array', 'The roles no region has yet'],
                'databaseHost' => ['string', 'The host of its database'],
                'databaseName' => ['string', 'The name of its database'],
                'databaseUser' => ['string', 'The user of its database'],
                'webUrl' => ['string?', 'The web site of its helpers'],
                'logoUrl' => ['string?', 'The logo of the grid, for the top bar of its sites: `LogoURL` of `[Helpers]` (Helpers.ini)'],
                'allowUserProfileWebUrls' => ['boolean', 'Its avatars may give a web address in their profile: AllowUserProfileWebURLs of `[UserProfiles]` as its simulators read it (its OpenSim.ini, then the file of each), true when they say nothing, false when one says false; the field of the form that needs it says so (`setting`)'],
                'helpers' => ['boolean', 'Its helpers are installed'],
                'simulators' => ['array?', 'The names of its simulators'],
                'announcement' => ['object?', 'What the grid says of itself at get_grid_info'],
            ],
            'writable' => [
                'enabled' => ['boolean', 'Enable or disable the grid (its Robust); a running grid is not stopped'],
            ],
        ],
        'simulators' => [
            'scope' => 'machine',
            'summary' => 'The simulators of this machine, and the ones of other machines that run regions of its grids',
            'description' =>
                'A simulator is identified by its address (host:port); its slug has its grid (`vonda.welcome`). A simulator of another machine that runs regions of a grid of this one (registered with its Robust) is listed too, `local` false: known by its address only, running while one of its regions is online, every way to it `none`. `connections` says how the engine reaches a simulator (`console`, `screen`, `config`, `regions`, `mysql` or `sqlite`; the config of the Robust belongs to the grid), each `none`, `configured` (in a list: named in its config, not tried), `ok` or `failed` (alone: tried).',
            'attributes' => [
                'uri' => ['string', 'Its address, host:port of its HTTP port: what identifies it'],
                'name' => ['string', 'The name shown'],
                'slug' => ['string?', 'Its grid, an underscore, its own slug; none for a simulator of another machine'],
                'grid' => ['string?', 'The nick of its grid'],
                'ini' => ['string?', 'Its config'],
                'link' => ['string?', 'The link that enables it'],
                'enabled' => ['boolean?', 'Its link is there; unknown for a simulator of another machine'],
                'running' => ['boolean', 'Its process is running; for a simulator of another machine, one of its regions is online'],
                'state' => ['string', 'running, stopped, unresponsive (its process runs and does not answer: its HTTP port not bound in a list, no OK at /simstatus/ alone) or crashed (its screen session left without its process)'],
                'local' => ['boolean', 'It runs on this machine, from a config of one of its grids'],
                'missingOptions' => ['array', 'The options of its grid its config does not have (`profiles`, `groups-hypergrid`...: the ones on by default when the grid says none); the master of its grid holds them, made again when it starts'],
                'regions' => ['integer', 'The regions it loads (its region files that are enabled; for a simulator of another machine, its regions in the table of the grid)'],
                'area' => ['integer', 'Their area, in square meters'],
                'avatars' => ['integer?', 'The people in it: the members of the grid and the visitors of other grids, never a NPC (they do not reach Presence); null when it is not known'],
                'members' => ['integer?', 'The members of the grid in it'],
                'visitors' => ['integer?', 'The visitors of other grids in it'],
                'server' => ['string', 'The machine it runs on, by its real name: this one for its own addresses (SYSTEMIP, localhost), the reverse DNS of the others, else its host as written'],
                'serverKey' => ['string', 'The address its host resolves to: its aliases and addresses one server, what `filter[server]` and the sort take'],
                'cpu' => ['number?', 'The cpu its process takes, in percent of the machine, while it runs on this machine'],
                'memory' => ['number?', 'The memory its process takes, in percent of the machine, while it runs on this machine'],
                'connections' => ['object', 'How the engine reaches it: console, screen, config, regions, mysql, sqlite, each none, configured, ok or failed'],
                'httpPort' => ['integer', 'Its HTTP port, the first of its block of ten'],
                'consolePort' => ['integer?', 'The port of its console (x4 of the block)'],
                'console' => ['string?', 'How its console is reached: rest or screen'],
                'databaseHost' => ['string?', 'The host of its database'],
                'databaseName' => ['string?', 'The name of its database'],
                'databaseUser' => ['string?', 'The user of its database'],
                'estateDatabaseName' => ['string?', 'The database of its estates, when it has its own'],
                'estateName' => ['string?', 'The name of its estate'],
                'estateOwner' => ['string?', 'The owner of its estate'],
                'regionsDirectory' => ['string?', 'The folder of its regions'],
                'core' => ['string?', 'The OpenSimulator release it runs'],
            ],
            'writable' => [
                'enabled' => ['boolean', 'Enable or disable the simulator; a running simulator is not stopped'],
            ],
        ],
        'services' => [
            'scope' => 'machine',
            'summary' => 'The services of the grids of this machine',
            'description' =>
                "What the simulators and the users of a grid ask for: the assets, the profiles, the login, the search. A service is served by a Robust of the grid, by the simulator of a standalone, or by a web site (the helpers) at an address the config of the grid gives.\n\n`get-grid-info` is the /get_grid_info that Robust answers: the engine reads it, it does not provide one.\n\n`kind` says who fixes the format of a web service: `fixed` (OpenSimulator or the viewers: only the address is the grid's to choose, and the format is described in this contract as an endpoint of its own) or `standard` (the engine's own; `standard` is its address, `legacy` the script that answered it before). `endpoint` is where the engine serves it.",
            'attributes' => [
                'key' => ['string', 'What it is for: asset, inventory, profile, search...'],
                'name' => ['string', 'The name shown'],
                'slug' => ['string', 'Its grid, an underscore, its key'],
                'grid' => ['string', 'The nick of its grid'],
                'simulator' => [
                    'string?',
                    'The simulator that asks, when the service is shown as that simulator reaches it',
                ],
                'url' => ['string?', 'The link to the service, where it is reached'],
                'port' => [
                    'string',
                    'private (the simulators), public (the viewers and other grids) or web (an address on a web site)',
                ],
                'provider' => ['string?', 'The instance that serves it, null when none of this machine does'],
                'served' => ['boolean', 'A Robust of the grid, or the simulator of a standalone, serves it'],
                'external' => ['boolean', 'Declared for the simulators, served somewhere else'],
                'connectors' => ['array', 'The connectors of Robust that serve it'],
                'section' => ['string?', 'The section of the config of the simulators that gives its address'],
                'setting' => ['string?', 'The setting of the config that gives its address'],
                'database' => ['string?', 'The database it stores in'],
                'web' => ['boolean', 'An address on a web site, not a port of Robust'],
                'kind' => ['string?', 'fixed or standard, for a web service'],
                'legacy' => ['string?', 'The script that answered it before the standard'],
                'standard' => ['string?', 'Its address in the standard of the engine, when it has one'],
                'endpoint' => [
                    'string?',
                    'Where the engine serves it (the standard address, or the one of the fixed format), when it does',
                ],
                'source' => [
                    'string?',
                    'Where its address comes from: provider, simulator, live (the running grid), config, default (where the helpers answer)',
                ],
            ],
            'writable' => [],
        ],
        'archives' => [
            'scope' => 'machine',
            'summary' => 'The archives and the backups of the grids of this machine',
            'description' =>
                'The inventories (`iar`) and the regions (`oar`) the consoles of the simulators saved, in the data folder of their grid, and the backups of the setup (`backup`: the configuration, the data and the databases of a grid or of a simulator, `.tar.gz`). A file says what is in it by its name; they are read here, made by `opensim save iar|oar` and `opensim backup`.',
            'attributes' => [
                'name' => ['string', 'The name of the file'],
                'slug' => ['string', 'The grid, the kind and the name without its extension'],
                'grid' => ['string', 'The nick of its grid'],
                'kind' => ['string', 'iar, oar or backup'],
                'file' => ['string', 'Its path'],
                'size' => ['integer', 'Bytes'],
                'modified' => ['integer?', 'Unix time of the last change of the file'],
                'stamp' => ['integer?', 'Unix time the name says it was made'],
                'user' => ['string?', 'Whose inventory, for an iar'],
                'simulator' => ['string?', 'The simulator, for an oar or a backup of a simulator'],
                'region' => ['string?', 'The region, for an oar of one region'],
                'assets' => ['boolean?', 'With the assets (false when the name says noassets), for an iar or an oar'],
                'permissions' => ['string?', 'The permissions kept (`perm`), for an iar or an oar'],
                'publish' => ['boolean?', 'Saved to be published, for an oar'],
                'logs' => ['boolean?', 'With the logs, for a backup'],
                'archives' => ['boolean?', 'With the archives of the users, for a backup'],
                'source' => ['boolean?', 'The backup of a simulator of another install before its import (its databases, its files as they were), for a backup: not a backup of a grid, which a restore takes'],
            ],
            'create' => [
                'kind' => ['string', 'backup: an archive made here is a backup of the setup (an iar or an oar is saved by the console of a simulator)'],
                'grid' => ['string', 'The nick of the grid'],
                'simulator' => ['string', 'One simulator of the grid only'],
                'logs' => ['boolean', 'With the logs'],
                'archives' => ['boolean', 'With the archives of the users'],
                'keep' => ['integer', 'How many backups of the series are kept, the older ones removed'],
            ],
            'writable' => [],
        ],
        'avatars' => [
            'scope' => 'grid',
            'summary' => 'The avatars of a grid',
            'description' =>
                'Read from the databases of the grid, each in the one of its service. An avatar is made with its account, its password, its inventory and its default outfit, or the outfit of an avatar model. Its name does not change.',
            'attributes' => [
                'uuid' => ['string', 'Its UUID'],
                'name' => ['string', 'First Last'],
                'slug' => ['string', 'first-last'],
                'uri' => ['string?', 'first.last@host:port'],
                'firstName' => ['string', ''],
                'lastName' => ['string', ''],
                'grid' => ['string?', 'The grid of a visitor, null for an avatar of this grid'],
                'local' => ['boolean', 'An avatar of this grid'],
                'email' => ['string?', ''],
                'title' => ['string', 'Its title'],
                'created' => ['integer?', 'When the account was made (Unix time)'],
                'level' => ['integer', 'The user level, 200 and over are gods; the one it has back when it is active again (a sort by level sorts by it, so by role)'],
                'isGod' => ['boolean', ''],
                'published' => [
                    'boolean',
                    'Its profile is published: the box "show in search" of the viewer (the first bit of UserFlags); the web profile and the search of people show only these',
                ],
                'role' => [
                    'string',
                    'From the level, as w4os names it, by the levels of OpenSimulator (GOD_NOT, GOD_LIKE, GOD_CUSTOMER_SERVICE, GOD_LIAISON, GOD_FULL, GOD_MAINTENANCE: a level has every right of the ones below it): `resident` (0 and less), `god-like` (1 and over), `customer-service` (100), `liaison` (150), `god` (200), `maintenance` (250); a disabled avatar has the role of the level it gets back',
                ],
                'standardLevel' => [
                    'boolean',
                    'The level is the one of its role (0, 1, 100, 150, 200); false for one in between, which a site may show beside the role',
                ],
                'flags' => ['integer', 'The user flags (bit 12: waits for the confirmation of its email; bit 13: banned; both conventions of the engine)'],
                'active' => ['boolean', 'The account can log in (status is active)'],
                'status' => [
                    'string',
                    '`active`: it can log in; `disabled`: a manager disabled it; `pending`: it waits for the confirmation of its email; `banned`: a god banned it (a hard ban: only an unban lets it in again, its account, its name and its UUID kept). Robust refuses the login of an avatar that is not active (its UserLevel is kept below 0, the level it had kept with it)',
                ],
                'banned' => ['boolean', 'A hard ban (status banned)'],
                'type' => ['string', '`user`, `model` (a model of the grid, by its [Models] rule) or `service` (the account of the services of the grid)'],
                'estateBans' => ['array', 'The estates whose ban lists have it (their ids); the bans of a region or a parcel are not counted'],
                'estateBanned' => ['boolean', 'In the ban list of an estate at least: kept out of some regions, not of the grid'],
                'ban' => ['string?', 'The ban it has, the hard one first: `hard`, `estates`, or none'],
                'accountState' => ['string', 'Its status, `online` for an active avatar in the world: what a list shows in one icon'],
                'online' => ['boolean', 'In the world now'],
                'lastSeen' => ['integer?', 'The last login or logout (Unix time)'],
                'homeRegion' => ['string?', 'The UUID of its home region'],
                'lastRegion' => ['string?', 'The UUID of the region it was in'],
                'technical' => ['boolean', 'An account of the services, not a person'],
                'profilePage' => ['string?', 'The web page of its profile: `web_profile_url` of the grid with First.Last (Avatar::profilePage()); null when the grid has none, or for an avatar of another grid'],
                'profileImage' => ['string?', ''],
                'about' => ['string?', ''],
                'profileUrl' => ['string?', 'Its web address (the Web tab of the viewers): null when the grid does not allow it (AllowUserProfileWebURLs), a change of it then ignored'],
                'partner' => ['string?', 'The UUID of its partner'],
                'wants' => ['string?', 'The free text of the wants'],
                'wantsList' => [
                    'array',
                    'What it wants, the check boxes of its profile: build, explore, meet, group, buy, sell, be-hired, hire (`meta.profileFlags` of the list gives their bits and the keys of their texts)',
                ],
                'skills' => ['string?', 'The free text of the skills'],
                'skillsList' => [
                    'array',
                    'What it is good at, the check boxes of its profile: textures, architecture, event-planning, modeling, scripting, custom-characters',
                ],
                'languages' => ['string?', ''],
                'firstImage' => ['string?', ''],
                'firstLife' => ['string?', ''],
            ],
            'create' => [
                'name' => ['string', 'First Last (or firstName and lastName). All in small letters or all in capitals, each part gets a capital and the rest small letters (bart simpson and BART SIMPSON are Bart Simpson); capitals and small letters mixed are kept as typed (McFly, JeanPaul deLuxe), and so is a reservedName'],
                'firstName' => ['string', 'Its first name, with lastName, in place of name'],
                'lastName' => ['string', 'Its last name, with firstName'],
                'email' => ['string', ''],
                'password' => ['string', 'The password (or passwordHash)'],
                'passwordHash' => ['object', 'hash and salt of a password that is moved'],
                'uuid' => ['string', 'The UUID of an account that is moved'],
                'home' => ['string', 'The UUID of the home region'],
                'model' => [
                    'string',
                    'The UUID or the name of an avatar model (`/grids/{grid}/avatar-models`): the avatar wears a copy of its outfit instead of the default one',
                ],
                'active' => ['boolean', 'The account can log in (the default); false: disabled'],
                'confirmEmail' => [
                    'boolean',
                    'Nothing is made yet: everything is checked, the registration is kept (name, email, model, the password hashed) and mailed a token to confirm its email, which makes the avatar, active (see Avatar access). The answer is 202, a `registrations` resource; `meta.emailConfirmation` says `sent`, `not sent` (the mail failed) or `given`. Only name, email, password, model and reservedName are taken',
                ],
                'link' => ['string', 'With confirmEmail: the page that takes the token, `{token}` in it'],
                'send' => [
                    'boolean',
                    'With confirmEmail, false: the token is not mailed but given in `meta.confirmation` (`token`, `expires`, `link`, `email`), for whoever delivers it',
                ],
                'published' => [
                    'boolean',
                    'Its profile is public ("show in search" in the viewer): true by default for a new avatar, false for a reservedName unless it is said',
                ],
                'reservedName' => [
                    'boolean',
                    'The name may have a part w4os keeps (Default, Test, Admin, the name of the grid): an avatar model, made on purpose. Without it such a name is refused (`name-reserved`)',
                ],
            ],
            'writable' => [
                'email' => ['string', 'Changed at once, or once it is confirmed with confirmEmail'],
                'title' => ['string', ''],
                'level' => ['integer', 'The user level; with `role`, one of that role (else `role-level-conflict`)'],
                'role' => [
                    'string',
                    '`resident`, `god-like`, `customer-service`, `liaison`, `god` or `maintenance`: the level of the role (0, 1, 100, 150, 200, 250), unless a `level` of that role is given with it; a disabled avatar gets it once enabled. Another is refused (`role-unknown`)',
                ],
                'active' => ['boolean', 'true: active (a pending avatar too: its confirmation token is forgotten); false: disabled'],
                'status' => ['string', '`active`, `disabled`, `pending` or `banned`; a `pending` avatar that becomes another forgets its confirmation token; a banned one is refused another (`account-banned`) unless `banned` false comes with it'],
                'banned' => ['boolean', 'true: a hard ban (a god); false: the unban, the avatar active again'],
                'estateBanned' => ['boolean', 'true: in the ban lists of every estate of the grid; false: out of all of them (a god)'],
                'estateBans' => ['array', 'The ids of the estates whose ban lists have it, the others leaving it out (a god)'],
                'password' => ['string', 'A new password: 6 to 16 characters, without double quote, as at the registration (`password-invalid`)'],
                'published' => [
                    'boolean',
                    'Its profile is public, "show in search" in the viewer (the first bit of UserFlags); a viewer that has the avatar sees the change once the caches of its simulator let it go',
                ],
                'wantsList' => [
                    'array',
                    'The check boxes of what it wants: their names (build, explore, meet, group, buy, sell, be-hired, hire), or their number as the viewer keeps it',
                ],
                'skillsList' => [
                    'array',
                    'The check boxes of what it is good at: their names (textures, architecture, event-planning, modeling, scripting, custom-characters), or their number',
                ],
                'home' => ['string', 'The UUID of the home region'],
                'about' => ['string', 'Of its profile, made when the avatar has none yet'],
                'profileUrl' => ['string', ''],
                'wants' => ['string', ''],
                'skills' => ['string', ''],
                'languages' => ['string', ''],
                'firstLife' => ['string', ''],
                'confirmEmail' => [
                    'boolean',
                    'The new email is not changed but mailed a token to confirm it (see Avatar access), which changes it; the avatar stays as active as it is. `meta.emailConfirmation` says `sent`, `not sent` or `given`',
                ],
                'link' => ['string', 'With confirmEmail: the page that takes the token, `{token}` in it'],
                'send' => [
                    'boolean',
                    'With confirmEmail, false: the token is given in `meta.confirmation` instead of mailed',
                ],
            ],
            'deletable' => true,
        ],
        'avatar-models' => [
            'scope' => 'grid',
            'summary' => 'The avatar models of a grid',
            'description' =>
                "The avatars a new avatar can look like: it wears a copy of the outfit and the appearance of the model it is made from (`model` of a new avatar). The models are the active avatars the rule of `[Models]` picks: `Match` is `first` (the first name is `MatchPattern`), `last` (the last name is), `any` (either, the default) or `uuid` (the avatars of `List`); `MatchPattern` is `Default` by default. A model is made in the console of Robust, dressed in a viewer and given a profile picture; it is never the avatar of a person.\n\n`picture` is the asset to show (`/grids/{grid}/assets/{picture}.jpg`): its profile picture, else a placeholder.\n\nThe filters `match`, `pattern` and `list` (UUIDs separated by commas) ask the models of another rule than the grid's, what is not asked being the grid's: the preview of the settings `models` as they are changed, before they are saved.",
            'attributes' => [
                'uuid' => ['string', 'Its UUID'],
                'name' => ['string', 'First Last'],
                'slug' => ['string', 'first-last'],
                'firstName' => ['string', ''],
                'lastName' => ['string', ''],
                'label' => ['string', 'The name to show, without the pattern of the rule (Default Ruth is Ruth)'],
                'profileImage' => ['string?', 'The UUID of its profile picture'],
                'picture' => ['string', 'The UUID of the picture to show: its profile picture, else a placeholder'],
                'about' => ['string?', 'The text of its profile'],
            ],
            // A rule asked in place of the grid's, to see the models it picks before it is saved (the preview of the settings models)
            'filters' => ['match' => ['first', 'any', 'last', 'uuid'], 'pattern' => [], 'list' => []],
            'writable' => [],
        ],
        'regions' => [
            'scope' => 'grid',
            'summary' => 'The regions of a grid',
            'description' => 'The regions the grid knows (its table of regions) and the ones its simulators describe.',
            'attributes' => [
                'uuid' => ['string', ''],
                'name' => ['string', ''],
                'slug' => ['string', ''],
                'uri' => ['string?', 'Its URI, host:port/Region Name (the name as it is), as a viewer and another grid reach it; host:port:Region Name is read too. Its hop:// link is `teleport`'],
                'location' => ['string', 'x,y on the map, in regions'],
                'x' => ['integer', ''],
                'y' => ['integer', ''],
                'size' => ['string', 'width x height in meters'],
                'width' => ['integer', ''],
                'height' => ['integer', ''],
                'ip' => ['string?', ''],
                'port' => ['integer?', ''],
                'serverUri' => ['string?', ''],
                'server' => ['string?', 'The simulator that has it, as the grid knows it: host:port of serverUri; null for a link to another grid'],
                'serverName' => ['string?', 'The name of its simulator: the one of its files on this machine, else `server`'],
                'handle' => ['integer?', 'The handle of its location'],
                'owner' => ['string?', 'The UUID of its owner: the owner of its estate, as the grid has it since the region registered (owner_uuid)'],
                'ownerName' => ['string?', ''],
                'ownerProfile' => [
                    'string?',
                    'The web page of the profile of its owner: `web_profile_url` of the grid with its name; null when the grid has none',
                ],
                'flags' => ['integer', 'The flags of the grid'],
                'roles' => ['array', 'The roles its flags give: DefaultRegion, FallbackRegion...'],
                'flagNames' => ['array', 'The names of its flags, all but RegionOnline (the status, `online`): DefaultRegion, Hyperlink...'],
                'flagLabels' => ['array', ''],
                'isDefault' => ['boolean', ''],
                'isFallback' => ['boolean', ''],
                'online' => ['boolean', ''],
                'hyperlink' => ['boolean', 'A link to a region of another grid'],
                'lastSeen' => ['integer?', ''],
                'access' => ['integer?', 'The access level of the region'],
                'mapImage' => ['string?', 'The UUID of its map tile'],
                'avatars' => ['integer?', 'The people in it: the members of the grid and the visitors of other grids, never a NPC (they do not reach Presence); null when it is not known'],
                'members' => ['integer?', 'The members of the grid in it'],
                'visitors' => ['integer?', 'The visitors of other grids in it'],
                'teleport' => ['string?', 'Its hop:// address on the grid; null for a link to a region of another grid'],
                'sources' => ['array', 'The grid, the config of a simulator, the search'],
                'simulator' => ['string?', 'The simulator that has it'],
                'file' => ['string?', 'Its file in the simulator'],
                'internalPort' => ['integer?', ''],
                'local' => ['boolean', 'This machine has its file: whether it is enabled is known here, and can be changed'],
                'enabled' => [
                    'boolean?',
                    'Its file ends in .ini; null for a region of the grid with no file of this machine (`local` false)',
                ],
            ],
            'writable' => [
                'enabled' => ['boolean', 'Enable or disable the region (its file); the simulator has to restart. `config-not-writable` (403), nothing changed, when this process cannot rename its file'],
                'estate' => ['string', 'Put it in another estate of the database its simulator reads (its number, or its id `simulator:number`): through the console of its simulator when it runs (`estate link region`), else in the map of the estates, which the simulator reads when it starts'],
                'roles' => [
                    'array',
                    'The flags it is given, all of them (DefaultRegion, FallbackRegion, Persistent...; RegionOnline and Hyperlink are Robust\'s, left as they are): through the console of Robust, else its table of regions; the roles of a place of the grid (DefaultRegion, DefaultHGRegion, FallbackRegion) kept in Region_<Name> of Robust.ini too, `meta.reminders` (notices: `code`, `params`, the English `detail`) saying to restart Robust; when this process cannot write Robust.ini, the reminder gives the line to set there. `flag-unknown` for a name that is not a flag',
                ],
                'owner' => [
                    'string',
                    'The UUID of its new owner, the owner of its estate: through the console of its simulator, else the database of the estate when the simulator is stopped; the table of regions of the grid at once. `estate-shared` when its estate has other regions, `owner-unknown` for an avatar the grid does not have. Its name, its place and its size are its file\'s: `field-read-only`',
                ],
            ],
        ],
        'parcels' => [
            'scope' => 'grid',
            'summary' => 'The parcels of a grid',
            'description' => 'The land of every simulator of the grid, read in the database of each.',
            'attributes' => [
                'uuid' => ['string', ''],
                'name' => ['string', ''],
                'slug' => ['string', ''],
                'uri' => ['string?', 'Its URI, host:port/Region Name/x/y/z: the URI of its region and the point it is entered at, its landing point, else one it holds (it changes when the parcel changes)'],
                'teleport' => ['string?', 'Its hop:// address, to the point of its URI'],
                'description' => ['string?', ''],
                'region' => ['string', 'The UUID of its region'],
                'regionName' => ['string?', ''],
                'localId' => ['integer?', ''],
                'owner' => ['string?', ''],
                'ownerName' => ['string?', ''],
                'group' => ['string?', ''],
                'isGroupOwned' => ['boolean', ''],
                'area' => ['integer', 'In square meters'],
                'category' => ['integer?', ''],
                'claimed' => ['integer?', ''],
                'price' => ['integer', ''],
                'forSale' => ['boolean', ''],
                'flags' => ['integer', ''],
                'shownInSearch' => ['boolean', ''],
                'scripts' => ['boolean', 'The scripts of others run on it (ALLOW_SCRIPTS), as the DataSnapshot of OpenSimulator says it'],
                'build' => ['boolean', 'Anybody may build on it (ALLOW_CREATE_OBJECTS)'],
                'public' => ['boolean', 'Anybody may enter it: it has no access list (USE_ACCESS_LIST)'],
                'dwell' => ['number?', ''],
                'landing' => ['array?', 'The landing point: x, y, z'],
                'snapshot' => ['string?', ''],
                'music' => ['string?', ''],
                'media' => ['string?', ''],
            ],
            'writable' => [],
        ],
        'posts' => [
            'scope' => 'grid',
            'summary' => 'The posts of the streams of the avatars of a grid',
            'description' => 'What every avatar posted on its profile (Flux), for the admin list of the posts of w4os-wp\'s Flux: the post, its Author, its date, the newest first. An avatar writes and deletes its own under its profile (`/grids/{grid}/avatars/{id}/posts`); the customer service and above delete any here.',
            'attributes' => [
                'author' => ['string', 'The UUID of the avatar that wrote it'],
                'name' => ['string', 'The name of its author when it was written'],
                'authorProfile' => ['string?', 'The web profile of its author, where the grid has one'],
                'content' => ['string', 'Plain text, ' . \OpenSim\Flux\Posts::MAX . ' characters at most'],
                'created' => ['integer', 'Unix time'],
                'source' => ['string', 'Where it was brought from (`w4os-wp:<ID>`), empty for a post written here'],
            ],
            'writable' => [],
            'deletable' => true,
        ],
        'estates' => [
            'scope' => 'grid',
            'summary' => 'The estates of a grid',
            'description' => 'The estates of the grid when its Robust serves them, else the ones of each simulator. A new estate is made by the console of its simulator when it runs (`estate create`), else in its database with the settings OpenSimulator gives a new one; a region goes to another estate by its `estate` (PATCH of the region).',
            'create' => [
                'name' => ['string', 'Its name, 64 characters at most'],
                'owner' => ['string', 'The UUID of an avatar of the grid'],
                'simulator' => ['string', 'The simulator whose database of estates holds it, when the simulators keep their own'],
            ],
            'attributes' => [
                'number' => ['integer', 'Its number in its database'],
                'simulator' => ['string?', 'The simulator that has it, null for an estate of the grid'],
                'name' => ['string', ''],
                'slug' => ['string', ''],
                'owner' => ['string?', ''],
                'ownerName' => ['string?', ''],
                'parent' => ['integer?', ''],
                'public' => ['boolean', ''],
                'denyAnonymous' => ['boolean', ''],
                'denyIdentified' => ['boolean', ''],
                'denyTransacted' => ['boolean', ''],
                'denyMinors' => ['boolean', ''],
                'allowVoice' => ['boolean', ''],
                'allowDirectTeleport' => ['boolean', ''],
                'allowEnvironmentOverride' => ['boolean', ''],
                'resetHomeOnTeleport' => ['boolean', ''],
                'skipScripts' => ['boolean', ''],
                'taxFree' => ['boolean', ''],
                'pricePerMeter' => ['integer', ''],
                'billableFactor' => ['number', ''],
                'fixedSun' => ['boolean', ''],
                'useGlobalTime' => ['boolean', ''],
                'sunPosition' => ['number', ''],
                'abuseEmail' => ['string?', ''],
                'abuseEmailToOwner' => ['boolean', ''],
                'regions' => ['array', 'The UUIDs of its regions'],
                'regionCount' => ['integer', 'How many regions it has'],
                'managers' => ['array', ''],
                'users' => ['array', ''],
                'groups' => ['array', ''],
                'bans' => ['array', ''],
            ],
            'writable' => [],
        ],
        'assets' => [
            'scope' => 'grid',
            'summary' => 'The assets of a grid',
            'description' =>
                'What the grid keeps in its asset service: textures, sounds, notecards, scripts, objects... A record never carries its data: an image of any type (texture, TGA, JPEG) is asked as a picture (`/grids/{grid}/assets/{id}.{format}`, the grid keeps textures as JPEG 2000 and the engine converts them and keeps the result for a day), the other types as they are.',
            'attributes' => [
                'uuid' => ['string', ''],
                'name' => ['string', ''],
                'slug' => ['string', ''],
                'description' => ['string', ''],
                'type' => ['string', 'texture, sound, notecard, script, object, mesh...'],
                'typeNumber' => ['integer', 'The number OpenSimulator gives the type'],
                'mime' => ['string', 'The content type of its data'],
                'local' => ['boolean', 'Kept in the region that made it only'],
                'temporary' => ['boolean', 'Kept for a while only'],
                'flags' => ['integer', ''],
                'creator' => ['string?', 'The UUID of who made it'],
                'created' => ['integer?', 'Unix time'],
                'accessed' => ['integer?', 'Unix time'],
                'size' => ['integer?', 'Bytes of its data'],
            ],
            'writable' => [],
        ],
        'destinations' => [
            'scope' => 'grid',
            'summary' => 'The destinations of the guide of a grid',
            'description' =>
                'The places the guide of the grid shows, by category, in the order of its source (`GuideSource` of `[Helpers]`: a file or a URL of "Name|Region URL" lines). The same list feeds the page of the guide and the boards of the scripts.',
            'attributes' => [
                'name' => ['string', 'What the guide shows'],
                'category' => ['string', 'The group it is listed in'],
                'url' => [
                    'string',
                    'The region address it leads to, as the source gives it (grid and region, with the place when there is one)',
                ],
                'title' => ['string', 'What its card shows first: its name on the grid itself, its region on another grid (the name of a list there is often the one of the grid), its name for a grid without a region'],
                'label' => ['string', 'What its card shows under its title, as text: its region and its point on the grid itself (`Region Name/x/y/z`), its grid on another one (`host:port`)'],
                'gridName' => ['string?', 'The name its grid gives itself (`gridname` of its grid info, as the scheduler last asked it), null when it is not known'],
                'picture' => ['string?', 'The picture of its parcel (an asset UUID), as the search knows it: by the name of the destination, else its landing point, else the only parcel of its region; null when none is known'],
                'sectionPicture' => ['string?', 'The picture of its section (`category`): the first of its destinations that has one; null when none has (the site shows its placeholder)'],
            ],
            'filters' => [],
            'writable' => [],
        ],
        'tasks' => [
            'scope' => 'application',
            'summary' => 'The tasks the application runs now and then',
            'description' =>
                'Cron and pseudo cron: each task has a name and an interval, runs when due, never twice at once, and a failure waits for its next turn. Asked to run, a task runs now. The search registers two: `search-parse` (once a minute: reads the snapshots of the registered simulators that are due) and `events-parse` (every 55 minutes: reads the calendar of events), for each grid: the id of a task is its name and the grid after a dot (`search-parse.demo`), each with its own state, so an install that serves several grids reads each on its own schedule. A task is asked by its id, or by its name when one grid only has it.',
            'attributes' => [
                'name' => ['string', 'The name of the task (search-parse), the same for every grid'],
                'scope' => ['string', 'The grid it is for, empty for a task of the install'],
                'label' => ['string', 'What it is for'],
                'every' => ['integer', 'Seconds between two runs'],
                'lastRun' => ['integer?', 'Unix time of the last start'],
                'status' => ['string?', 'ran, failed or running'],
                'message' => ['string', 'What the last run said'],
                'duration' => ['number?', 'Seconds the last run took'],
                'next' => ['integer?', 'Unix time it is due'],
                'due' => ['boolean', 'It runs at the next pass of the cron'],
                'running' => ['boolean', 'Someone is running it now'],
            ],
            'filters' => [],
            'writable' => [],
        ],
        'forms' => [
            'scope' => 'application',
            'summary' => 'What a setup asks',
            'description' =>
                'The fields of the forms (grid, remote-grid, simulator, region), in the standard format of the forms: name, label, type, default, options, condition, follows, group. Whoever shows a form (the terminal, a web page) reads them from here; the engine itself uses them to work out what is not answered.',
            'attributes' => [
                'fields' => ['array', 'The fields, in the order they are asked'],
            ],
            'filters' => [],
            'writable' => [],
        ],
        'fonts' => [
            'scope' => 'application',
            'summary' => 'The fonts the machine has to draw a text',
            'description' => 'The names to give as `font` of a text image (`/text-images`).',
            'attributes' => ['name' => ['string', 'The name of the font']],
            'filters' => [],
            'writable' => [],
        ],
    ];

    /** @return array<string,array{0:string,1:string}> the attributes of a type of resource */
    public static function attributes(string $type): array
    {
        return self::RESOURCES[$type]['attributes'];
    }

    /** @return array<string,mixed> */
    public static function document(string $version = '1'): array
    {
        $paths = [];
        $schemas = [
            'Problem' => [
                'type' => 'object',
                'description' => 'RFC 9457 problem details',
                'required' => ['title', 'status', 'code'],
                'properties' => [
                    'type' => ['type' => 'string', 'description' => 'A URN that names the kind of problem'],
                    'title' => ['type' => 'string'],
                    'status' => ['type' => 'integer'],
                    'code' => [
                        'type' => 'string',
                        'enum' => array_keys(Problem::CODES),
                        'description' =>
                            'What went wrong, stable: what a site tells its people in its own language (the detail is the English one). ' .
                            implode(
                                '; ',
                                array_map(
                                    static fn(string $code, string $says): string => "`$code`: $says",
                                    array_keys(Problem::CODES),
                                    Problem::CODES,
                                ),
                            ),
                    ],
                    'detail' => ['type' => 'string', 'description' => 'What went wrong, in English'],
                    'instance' => ['type' => 'string', 'description' => 'The address that was asked'],
                    'resource' => [
                        'type' => 'string',
                        'description' => 'For `not-found`, the kind of resource that is not there, a type of the API (grids, avatars, regions, posts...): its text is `error_not_found_<the type in the singular>` of the catalog (avatars: error_not_found_avatar, avatar-models: error_not_found_avatar_model) when it has one, else the neutral `error_not_found` (`OpenSim\\I18n\\Catalog::errorKey($code, $resource)`)',
                    ],
                ],
            ],
        ];
        $tags = [];
        $scopes = ['machine' => 'Machine', 'grid' => 'Grid', 'application' => 'Application'];
        $groups = [];
        foreach (self::RESOURCES as $type => $resource) {
            $schema = self::schemaName($type);
            $one = self::schemaName(rtrim($type, 's'));
            $tag = ucfirst(str_replace('-', ' ', $type));
            $word = strtolower(str_replace('-', ' ', rtrim($type, 's')));
            $groups[$scopes[$resource['scope']]][] = $tag;
            $tags[] = ['name' => $tag, 'description' => $resource['description']];
            $schemas[$schema] = self::schema($type);
            $base = $resource['scope'] === 'grid' ? "/grids/{grid}/$type" : "/$type";
            $parameters = $resource['scope'] === 'grid' ? [self::gridParameter()] : [];
            $listing = self::listing($type);
            if (!in_array($type, self::UNPAGED, true)) {
                $paths[$base]['get'] = [
                    'operationId' => "list$schema",
                    'tags' => [$tag],
                    'summary' => "List $type",
                    'description' => $resource['summary'] . ', searched, filtered, sorted and paged.',
                    'parameters' => [...$parameters, ...self::listParameters($listing?->filterValues() ?? $resource['filters'] ?? [], $listing)],
                    'responses' => [
                        '200' => self::documentResponse("A page of the $type", $schema, true),
                        '400' => self::problem(
                            'A filter, a value of a filter or a sort the list does not take (`filter-unknown`, `filter-value-unknown`, `sort-unknown`): the detail names the ones it takes',
                        ),
                        '404' => self::problem('The grid is not known here'),
                        '503' => self::problem('What the list is read from is not available'),
                    ],
                ] + ($listing === null ? [] : ['x-list' => $listing->document()]);
            } else {
                $paths[$base]['get'] = [
                    'operationId' => "list$schema",
                    'tags' => [$tag],
                    'summary' => "List $type",
                    'description' => $resource['summary'] . '.',
                    // A list that has no pages takes its filters only
                    'parameters' => [...$parameters, ...self::filterParameters($resource['filters'] ?? [])],
                    'responses' => [
                        '200' => self::documentResponse("The $type", $schema, true),
                        '404' => self::problem('The application has none, or the grid is not known here'),
                    ],
                ];
            }
            $form = self::itemForm($type);
            $paths["$base/{id}"]['get'] = [
                'operationId' => "get$one",
                'tags' => [$tag],
                'summary' => "Get $word",
                'description' => 'One of the ' . $type . ' by its id.',
                'parameters' => [...$parameters, self::idParameter()],
                'responses' => [
                    '200' => self::documentResponse('The resource', $schema, false),
                    '404' => self::problem('There is none with this id'),
                    '503' => self::problem('What it is read from is not available: a database that does not answer is never an item that is not there'),
                ],
            ] + ($form === null ? [] : ['x-form' => self::formDocument($type, $form)]);
            if (isset($resource['create'])) {
                $paths[$base]['post'] = [
                    'operationId' => "create$one",
                    'tags' => [$tag],
                    'summary' => "Make $word",
                    'parameters' => $parameters,
                    'requestBody' => [
                        'required' => true,
                        'content' => [Response::JSON_API => ['schema' => self::input($resource['create'])]],
                    ],
                    'responses' => [
                        '201' => self::documentResponse('Made; its address is in the Location header', $schema, false),
                    ] + (isset($resource['create']['confirmEmail']) ? ['202' => self::registrationResponse()] : []) + [
                        '400' => self::problem(
                            'A value is wrong, `code` says which: a name that is not one, reserved or taken, no password or a wrong one, a wrong email, an unknown model',
                        ),
                        '503' => self::problem('A database cannot be reached'),
                    ],
                ] + (($creation = self::creationForm($type)) === null ? [] : ['x-form' => $creation]);
            }
            if ($resource['writable'] !== []) {
                $paths["$base/{id}"]['patch'] = [
                    'operationId' => "update$one",
                    'tags' => [$tag],
                    'summary' => "Change $word",
                    'parameters' => [...$parameters, self::idParameter()],
                    'requestBody' => [
                        'required' => true,
                        'content' => [Response::JSON_API => ['schema' => self::input($resource['writable'])]],
                    ],
                    'responses' => [
                        '200' => self::documentResponse('Changed', $schema, false),
                        '400' => self::problem('A value is wrong'),
                        '404' => self::problem('There is none with this id'),
                        '503' => self::problem('No channel can change it now'),
                    ],
                ];
            }
            if ($resource['deletable'] ?? false) {
                $paths["$base/{id}"]['delete'] = [
                    'operationId' => "delete$one",
                    'tags' => [$tag],
                    'summary' => "Delete $word",
                    'parameters' => [...$parameters, self::idParameter()],
                    'responses' => [
                        '204' => ['description' => 'Deleted'],
                        '404' => self::problem('There is none with this id'),
                        '503' => self::problem('No channel can delete it now'),
                    ],
                ];
            }
        }
        $paths['/grids/{grid}/assets/{id}.{format}']['get'] = [
            'operationId' => 'getAssetMedia',
            'tags' => ['Assets'],
            'summary' => 'Asset as a picture',
            'description' => 'An asset as a page shows it: an image of any type as a picture in the format asked.',
            'parameters' => [
                self::gridParameter(),
                self::idParameter(),
                [
                    'name' => 'format',
                    'in' => 'path',
                    'required' => true,
                    'schema' => ['type' => 'string', 'enum' => ['jpg', 'png', 'gif', 'webp']],
                ],
                [
                    'name' => 'width',
                    'in' => 'query',
                    'required' => false,
                    'description' => 'Resize the picture to this width, in pixels',
                    'schema' => ['type' => 'integer', 'minimum' => 1],
                ],
                [
                    'name' => 'default',
                    'in' => 'query',
                    'required' => false,
                    'description' => 'What a picture that is not there stands for: `place` (the default), or `person`, a profile picture: the silhouette of a person instead of the picture of nothing. The id `' . WebAssets::PERSON . '` is that silhouette, found, as `' . WebAssets::PLACEHOLDER . '` is the picture of nothing',
                    'schema' => ['type' => 'string', 'enum' => array_keys(WebAssets::DEFAULTS)],
                ],
            ],
            'responses' => [
                '200' => [
                    'description' => 'The picture, or the asset as it is',
                    'content' => ['image/*' => ['schema' => ['type' => 'string', 'format' => 'binary']]],
                ],
                '302' => [
                    'description' => 'This machine does not read JPEG 2000 and the grid sets an external web assets service (AssetsURL of [Helpers]): the picture there, `<AssetsURL>/<id>.<format>`',
                    'headers' => ['Location' => ['schema' => ['type' => 'string', 'format' => 'uri']]],
                ],
                '404' => [
                    'description' => 'There is no such asset, or it cannot be converted: the picture of nothing, or of a person (`default`)',
                    'content' => ['image/png' => ['schema' => ['type' => 'string', 'format' => 'binary']]],
                ],
            ],
        ];
        $paths['/grids/{grid}/motd']['get'] = [
            'operationId' => 'getMotd',
            'tags' => ['Message of the day'],
            'summary' => 'Message of the day',
            'description' =>
                'Plain text: `WelcomeMessage` of `[LoginService]`, else a welcome to the grid. `<USERNAME>` stands for the name of the avatar, replaced when `username` is given. This is what `MessageUrl` of the Robust config can point to.',
            'parameters' => [
                self::gridParameter(),
                [
                    'name' => 'username',
                    'in' => 'query',
                    'required' => false,
                    'description' => 'The name that replaces `<USERNAME>`',
                    'schema' => ['type' => 'string'],
                ],
            ],
            'responses' => [
                '200' => [
                    'description' => 'The message',
                    'content' => ['text/plain' => ['schema' => ['type' => 'string']]],
                ],
                '404' => self::problem('The grid is not known here'),
            ],
        ];
        $paths['/logos/{name}']['get'] = [
            'operationId' => 'getLogo',
            'tags' => ['Logos'],
            'summary' => 'A logo of w4os or OpenSimulator',
            'description' =>
                'A logo the engine ships for the sites, by its file name: the long logo of w4os for a footer, its square logo, its icon and its favicons, the logo of OpenSimulator. The logo of a grid is its own setting: `logo` of `/grids/{grid}/addresses`.',
            'parameters' => [
                [
                    'name' => 'name',
                    'in' => 'path',
                    'required' => true,
                    'schema' => ['type' => 'string', 'enum' => Logos::FILES],
                ],
            ],
            'responses' => [
                '200' => [
                    'description' => 'The logo',
                    'content' => ['image/png' => ['schema' => ['type' => 'string', 'format' => 'binary']]],
                ],
                '404' => self::problem('There is no logo with this name'),
            ],
        ];
        $paths['/text-images']['get'] = [
            'operationId' => 'getTextImage',
            'tags' => ['Text images'],
            'summary' => 'Text as an image',
            'description' =>
                'A 1024 pixels square PNG with the text centered, to put on a prim where dynamic textures are not allowed, or to lighten the simulator. The fonts the machine has are listed at `/fonts`; one it does not have is replaced by DejaVu-Sans.',
            'parameters' => [
                [
                    'name' => 'text',
                    'in' => 'query',
                    'required' => false,
                    'description' => 'The text (500 characters at most); the name of the font when there is none',
                    'schema' => ['type' => 'string'],
                ],
                [
                    'name' => 'font',
                    'in' => 'query',
                    'required' => false,
                    'schema' => ['type' => 'string', 'default' => 'Impact'],
                ],
                [
                    'name' => 'size',
                    'in' => 'query',
                    'required' => false,
                    'description' => 'The size of the font, up to 1000',
                    'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000, 'default' => 120],
                ],
                [
                    'name' => 'color',
                    'in' => 'query',
                    'required' => false,
                    'description' => 'The color of the text: a name or #rrggbb',
                    'schema' => ['type' => 'string', 'default' => 'white'],
                ],
                [
                    'name' => 'background',
                    'in' => 'query',
                    'required' => false,
                    'description' => 'The color of the background: a name or #rrggbb',
                    'schema' => ['type' => 'string', 'default' => 'black'],
                ],
                [
                    'name' => 'download',
                    'in' => 'query',
                    'required' => false,
                    'description' => 'Offer the picture as a file instead of showing it',
                    'schema' => ['type' => 'boolean', 'default' => false],
                ],
            ],
            'responses' => [
                '200' => [
                    'description' => 'The picture',
                    'content' => ['image/png' => ['schema' => ['type' => 'string', 'format' => 'binary']]],
                ],
                '400' => self::problem('The color or the background is not one'),
                '503' => self::problem('The machine cannot draw: php-imagick is missing'),
            ],
        ];
        $paths['/tasks/{id}/runs']['post'] = [
            'operationId' => 'runTask',
            'tags' => ['Tasks'],
            'summary' => 'Run a task',
            'description' => 'Run a task now, whether it is due or not.',
            'parameters' => [self::idParameter()],
            'responses' => [
                '200' => [
                    'description' => 'The task ran',
                    'content' => [
                        Response::JSON_API => [
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'data' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'status' => ['type' => 'string'],
                                            'message' => ['type' => 'string'],
                                            'duration' => ['type' => 'number'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                '404' => self::problem('There is no task with this name'),
                '500' => self::problem('The task failed: the message says why'),
            ],
        ];

        // The instances of this machine (a grid is its Robust), started and stopped as the command line does
        foreach (['start' => 'Start', 'stop' => 'Stop', 'restart' => 'Restart'] as $action => $verb) {
            $paths["/instances/{name}/$action"]['post'] = [
                'operationId' => "{$action}Instance",
                'tags' => ['Simulators'],
                'summary' => "$verb an instance",
                'description' =>
                    "$verb an instance of this machine by its name, as `opensim $action` takes it: a simulator (`vonda.welcome`), a grid (its Robust). " .
                    'Attributes of the body (`type` operations): `simulators` (the simulators of a grid too), `now` (no waiting for the visitors to leave). ' .
                    'Only the user that runs the instances may: another process (the web server of a site) is refused, nothing done (`instance-user-required`, 403).',
                'parameters' => [['name' => 'name', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]],
                'responses' => [
                    '200' => [
                        'description' => 'What became of each instance: `action`, `results` (instance, outcome: started, running, starting, stopped, not running, failed; ok; `code` of a failure: `port-taken` when a simulator that does not run has its port taken by another program, the simulator of an older install most often, else `instance-failed`; detail)',
                        'content' => [Response::JSON_API => ['schema' => ['type' => 'object']]],
                    ],
                    '403' => self::problem('This process is not the user that runs the instances (`instance-user-required`)'),
                    '404' => self::problem('There is no instance of this name'),
                    '500' => self::problem('An instance failed: its result says why'),
                ],
            ];
        }

        $endpoints = Endpoints::document();
        $tags = [...$tags, ...$endpoints['tags']];
        $paths = [...$paths, ...$endpoints['paths']];
        foreach ($endpoints['groups'] as $group => $names) {
            $groups[$group] = [...$groups[$group] ?? [], ...$names];
        }
        isset($paths['/grids/{grid}/destinations']['get']) &&
            ($paths['/grids/{grid}/destinations']['get'] = Endpoints::destinations(
                $paths['/grids/{grid}/destinations']['get'],
            ));

        // What needs no key is what the viewers, the simulators and the visitors call (Router::PUBLIC)
        foreach (Router::PUBLIC as $public) {
            [$method, $path] = explode(' ', $public, 2);
            isset($paths[$path][strtolower($method)]) && ($paths[$path][strtolower($method)]['security'] = []);
        }
        // Who may do it through a site: the lowest user level of OpenSimulator on what is not one's own, and a visitor for their own
        foreach ($paths as $path => $operations) {
            foreach ($operations as $method => $operation) {
                is_array($operation) && isset($operation['operationId']) &&
                    ($paths[$path][$method]['x-access'] = Access::of($method, (string) $path, (string) $operation['operationId']));
            }
        }

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'OpenSim engine',
                'version' => $version,
                'license' => ['name' => 'AGPL-3.0-or-later', 'identifier' => 'AGPL-3.0-or-later'],
                'description' =>
                    "The grids, the simulators and the services of an install, and what is in the databases of a grid, as JSON:API resources; the errors are problem details (RFC 9457).\n\nA list takes `search`, `sort` (`-` before a column for the descending order), `filter[<name>]`, `page[number]` and `page[size]`. The list operation of a model that describes its list says what a site shows of it in `x-list`: `columns` in their order (`name` the attribute, `label` a key of the catalog of texts, `type` how it shows: text, email, number, date, datetime (with its time: when it was created, last seen), boolean, picture, map, link, hop, size, status, role, list, indicators; `sort` the sort it takes; `bare` whether its value reads without its label (an email, a role, a status, a picture: the label of the others comes before their value where a list folds them under the name, `label_value`); `mirrors` the filter whose value it repeats (the region of the parcels of a region): a site leaves it out when that filter is fixed to one value; `priority` `primary` for a column shown at every width (the first one, the status), `secondary` for one folded under the name on a narrow screen; `indicators` for an `indicators` column, its icons in their order, each `name` the attribute it shows (`connections.console`: a value of an object), `icon` what it means (power, run, play, pause, online and offline: a globe with a check and a crossed one, console, screen, file, files, database, server, account, account-online, account-banned, ban: a site gives each the icon of its own library), `label` the key of its title, `states` (`value`, `label`: the key of a text that says it all on its own, shown alone, `tone`: ok green when all is well, off grey when it is off as it normally is, warning orange for a partial or degraded state (a ban of some estates), failed red for an error; a difference between states that matters always changes the icon or the text too, never the color alone; `icon` its own icon, when it is not the one of the indicator) and `actions`, the names of the actions of the list that change it: the one whose `when` the row meets is the button of its icon, not a button of the row; a value no state has shows no icon; `subtitle` the attributes shown under it, the first that has a value (its address, else its slug: a list shows them there, never in a column of their own), `subtitleLink` the attribute that gives the address it links to; `picture`, `tag` and `tagLabel`, `link` the attributes shown with it (`picture` an asset UUID, shown as a thumbnail beside the value from the open `GET /grids/{grid}/assets/{id}.{format}`, at an address the browsers reach: the locked bridge of the site, never the address of the API on the machine; the map tile of a region is its `mapImage`); `default` what a missing picture stands for, `person` or `place`, as `default` of the pictures of the assets; `values` the words of a `list` with their `label` and `description`, as the values of a filter), `sort` (`default`, `values`, `descending`), `search` (the attributes it looks in), `filters` (`name`, `label`, `values` with their `label`, or `format` for one that takes any value; `multiple` for one that takes several values separated by commas, keeping what has any of them; `dynamic` for one whose values that give something are in `meta.available.<name>` of the list: the values, or `value` and `label` (a text, not a key) for one without `values`, such as the simulators; the number of rows each value of a filter gives is in `meta.counts.<name>.<value>`, counted with the search and the other filters asked, not its own) `views` (the filters a site shows as the row of links above the list, as w4os-wp does: All (n) | Default Model (n) | ...; their names in their order, the first link the whole list, `all` its label, `meta.total` without a filter; each value of a view with the label of its filter and its count, `meta.counts.<filter>.<value>`, the values of `size` the ones the list has, `meta.available.size`; the avatars: `type`, `active`, `online`, whose counts are the user avatars' only, as w4os-wp counts them; the regions: `status`, `size`), its texts, keys of the catalog: `title`, `menu` (its label in a menu), `placeholder` (of its search), `empty` (what it says without a row), `count` (a text that changes with the number of its rows, `%d`); `create` the button above the list that makes an item, not an action of a row (`label`, `method`, `path`, `operation` the operation whose `x-form` is its form: New Avatar) and `actions` (`name`, `label`, the operation as `method` and `path`, `{id}` the row, its `body` with `{attribute}` of the row, `when` the values of attributes a row must have, `requires` the attributes it must have, `notSelf` never on the avatar of whoever acts, `ask` the key of a question the site asks before (an action that sends avatars away), a string, `link` the page of the site its mail links to, `reset` or `confirm`: the site adds `link` to the body, the address of that page with `{token}` in it, and may add `send` false to get the token instead of a mail). The operation that reads an item of such a model says what its page shows and changes in `x-form`: `groups` (`name`, `label`) in their order, `header` what the head of the page shows, as the row of the list does (`title` the attribute of its name, `picture`, `default`, `subtitle`, `subtitleLink` as on the name of the list, `indicators` the ones of its status), `fields` in the standard field format of the engine (`name`, `label`, `type`: a type of column or `string`, `text`, `integer`, `number`, `boolean`, `select`, `checkboxes`, `secret` (given back as `********` when it is set, which sent back keeps it), `url`, `email`, `avatar`, `connection-string` (a database as an object of its parts, its `fields`: host, port, database, user, password, the password masked as a secret), `path` (a path under a fixed address, `base` which: `site`, the web site of the grid, `meta.service.base`, shown before it, read-only), `note` (a text the page shows, its `description`, no value); `group`; `options` value => label for a select; `multiple` for one that takes several values, a list (the avatars of a list); `widget` how a site shows it when it is not the usual way of its type (`radio`: the choices of a select side by side, for a short list that stays short; a field without it shows as its type says); `placeholder` the key of the text shown in it while it is empty; `readOnly` for one that is only shown, the others being what the PATCH of the item takes; `writeOnly` for one the item does not give back; `condition` the values of other fields it is shown for; `access` `view` and `edit`, the roles `manager`, `self`, `owner` of whoever a site shows it to, the managers by default, and `level`, the lowest user level of a manager who changes it; `description`; `setting` the setting of the grid it needs true (`meta.settings` of the item, an attribute of the grid): when it is false, a site does not show the field and the PATCH ignores it (`profileUrl`, the web address of a profile, as the viewers do with AllowUserProfileWebURLs); `banner` for one the banner of a profile shows over all its tabs: its picture, its name, its birth, its role; `bare` for one whose value reads without its label, as in a list: its role) `actions` as in `x-list`, and `sections` what the page shows in its order, as tabs or one after the other as a site chooses (`name`, `label`, and `group` the fields of a group, or `items` a list of its own under the item: `posts`, `picks`, `classifieds`: the sections of a profile). The item itself gives in `meta.form` its `x-form` as its grid has it, without the fields its grid does not allow (`setting` false in `meta.settings`), nor the groups and sections left without them: the web address of a profile and its section when AllowUserProfileWebURLs is false; a site shows that one; a form that is not an item (the settings of a grid, `/grids/{grid}/settings/{group}`: read with GET, changed with PATCH) has its `title`, its `menu` (its short label in a menu, w4os-wp's), its `submit`, the label of its button, its `saved` (the message once it is saved); a page of a service of the helpers (economy, search, guide, offline, assets) starts with `provide`, Provide as w4os-wp has it (true when the grid sets no address of it, or one on its site): true, the site handles the service at `path` under its web site, false, another server at the whole address (its field after them); the fields the helpers need are shown when `provide` is true; the page is w4os-wp's (its fields in its order, its words, its conditions), its `description` the points of its sidebar, the `description` of a field the points of its help; `meta.fixed` the fields of any page this server cannot change for the grid (it cannot write the file the grid reads it in, or the grid has no files here; what only the helpers and the site read goes then to the settings the application keeps): by field, its `reason`, the `file` and the `text` to write there by hand, never a secret; a site shows such a field read-only with them; `meta.snippets` the lines of the files of the grid a field shows, by field, each `file` (null for a sample) and `text`, made for the grid, never with a secret; its `meta.service` says who handles the requests, for a site to route them: `key` the service, `base` the web site, `helpers` the base of the helpers, `handledBy` `helpers` (the address under their base), `site` (elsewhere on the site: a page of the site that shows the block `block` of the helpers, else the operation of the engine `endpoint`), `elsewhere` (Provide off), null without an address; the health then asks that page and wants its block (`data-helpers-block`), its help (`description`: the keys of its points, in their order) and the list of what its settings pick (`preview`: `label` its title, `empty` what it says when there is none, `path` the list, `filters` the fields given as its filters as they are changed, so that it is seen before it is saved: the avatar models a rule picks). The operation that makes an item says in `x-form` what a site shows to make one (an avatar: the fields of the creation of w4os-wp): its `title`, its help (`description`, the keys of its points), `fields` as above (`required`; `pattern` the regular expression the whole value matches; `maxLength`; `source` for a select whose choices are a list of the API: `path` the list, `value` and `label` the attributes of an item that give the value and its text, `picture` the one of its picture (an asset), `empty` the key of what it says when the list has none; a select that is not `required` may be left without a choice: the avatar models, the default outfit) and `submit`, the key of its button. The operation that adds a post to the stream of an avatar says in `x-form` what a site shows to write one: `fields` as above (`maxLength` the longest text, `required`), `submit` the key of its button and `actions` what its own posts take (`{id}` the avatar, `{post}` the post). The labels are keys of the catalog (`share/languages`), so the document is the same in every language.\n\nThe URI of a region is `host:port/Region Name` (the name as it is, its spaces too), the one of a parcel `host:port/Region Name/x/y/z` (the point it is entered at); a hop:// link (`teleport`) has its spaces as %20 (`hop://host:port/Region%20Name/x/y/z`), the form the viewer writes itself; in a viewer, a site links every destination, of the grid or of another one, as `secondlife://host:port/secondlife/Region%20Name/x/y/z` (`Destination::AUTO`, `Grid::link()`: the engine tells a viewer by the user agent); a hop:// link never has a colon before the region. The links HG (`secondlife://host:port:Region`) and V3HG are deprecated: they failed in every viewer tried. Both `host:port/Region` and `host:port:Region` are read. This is an important change in the canonical form 3.0 gives: not tested against 2.x, but in theory compatible, since 2.x already accepted both forms.\n\n## Who may do what through a site\n\nEvery operation says it in `x-access`, by the user levels of OpenSimulator (0 GOD_NOT, 1 GOD_LIKE, 100 GOD_CUSTOMER_SERVICE, 150 GOD_LIAISON, 200 GOD_FULL, 250 GOD_MAINTENANCE; a level has every right of the ones below it): `level`, the lowest level of a person who does it on what is not their own (none: anybody may), and `self`, whether a visitor may do it for their own account. `needs`, what of a grid it needs the engine to reach, among the `access` of the grid: `files` (its config on this machine: the repair of its options), `settings` (the change of its settings: in its files, or in the settings the application keeps, `Environment::system(settings:)`), `databases` (what they hold); a site hides or disables an operation whose needs the grid has not, as the engine answers it unavailable (503, its reason). The engine does its best with what it has: what its databases and its answers over HTTP give is given. Reading and the care of the accounts are for the customer service and above (100); what changes the grid itself (its instances, its regions, its setups, its backups and restores, the role of an avatar) for a god (200), as only GOD_FULL has rights of its own in OpenSimulator; 250 is never needed. What belongs to a site and not to the engine (its own settings, such as its theme) is changed by whom `x-site` says at the root of this document (`settings`: a god, as any change). A site asks `OpenSim\\Api\\Access` (or reads `x-access` and `x-site`), it never writes a level. The key of the site is what opens the API: the level is the one of the person the site acts for.\n\n## Authentication\n\nThe API is closed: what manages the machine and the grids (the resources, the tasks, the health) needs an API key, sent as `Authorization: Bearer <key>`. A key is made on the machine and shown once: `opensim api-key create <label>`; `opensim api-key list` lists them without what opens them, `opensim api-key revoke <label>` (or its id) closes one. A key opens everything for now. To try the API from this documentation, open the [test page](try.html), enter the address of the API and click Authorize.\n\nWhat the viewers, the simulators and the visitors of a site call needs none (the message of the day, the guide, the pictures, the status of the grid, the pages): the operations marked as open. The calls in a format of OpenSimulator and the viewers (search, registration, offline messages, currency, land tools) are protected by what the protocol has: the secure session of an avatar, the secret of a region, the key of the money server.",
            ],
            'servers' => [['url' => '/', 'description' => 'Where the application that uses the engine mounts the API']],
            // Closed unless an operation says it is open (`security: []`)
            'security' => [['ApiKey' => []]],
            'tags' => $tags,
            // The sections of the documentation: the machine, a grid, the application, the helpers, Robust
            'x-tagGroups' => array_map(
                static fn(string $name, array $tags): array => ['name' => $name, 'tags' => $tags],
                array_keys($groups),
                array_values($groups),
            ),
            'paths' => $paths,
            // Who may change the settings of a site (its own, not the engine's): Access::site()
            'x-site' => Access::site(),
            'components' => [
                'securitySchemes' => [
                    'ApiKey' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'osk_...',
                        'description' =>
                            'A key made on the machine with `opensim api-key create`, sent as `Authorization: Bearer <key>`.',
                    ],
                ],
                'schemas' => $schemas,
            ],
        ];
    }

    public static function json(string $version = '1'): string
    {
        return json_encode(
            self::document($version),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ) . "\n";
    }

    public static function yaml(string $version = '1'): string
    {
        return Yaml::dump(
            self::document($version),
            12,
            2,
            Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE,
        );
    }

    private static function schemaName(string $type): string
    {
        return str_replace(' ', '', ucwords(str_replace('-', ' ', $type)));
    }

    /** @return array<string,mixed> the resource as JSON:API shows it: type, id, attributes */
    private static function schema(string $type): array
    {
        $properties = [];
        foreach (self::RESOURCES[$type]['attributes'] as $name => [$kind, $description]) {
            $nullable = str_ends_with($kind, '?');
            $json = rtrim($kind, '?');
            $properties[$name] =
                ['type' => $nullable ? [$json, 'null'] : $json] +
                ($description !== '' ? ['description' => $description] : []);
        }

        return [
            'type' => 'object',
            'description' => self::RESOURCES[$type]['summary'],
            'required' => ['type', 'id', 'attributes'],
            'properties' => [
                'type' => ['const' => $type],
                'id' => ['type' => 'string'],
                'attributes' => ['type' => 'object', 'properties' => $properties],
                'links' => ['type' => 'object', 'properties' => ['self' => ['type' => 'string']]],
            ],
        ];
    }

    /**
     * @param array<string,array{0:string,1:string}> $fields
     * @return array<string,mixed>
     */
    private static function input(array $fields): array
    {
        $properties = [];
        foreach ($fields as $name => [$kind, $description]) {
            $properties[$name] = ['type' => $kind] + ($description !== '' ? ['description' => $description] : []);
        }

        return [
            'type' => 'object',
            'properties' => [
                'data' => [
                    'type' => 'object',
                    'properties' => ['attributes' => ['type' => 'object', 'properties' => $properties]],
                ],
            ],
        ];
    }

    /**
     * What the list of a resource shows, as its model describes it (Listing): `x-list` of its list operation. Null for a resource
     * whose model does not describe it.
     */
    public static function listing(string $type): ?Listing
    {
        $class = Api::RESOURCES[$type] ?? Api::GRID_RESOURCES[$type] ?? null;

        return $class !== null && method_exists($class, 'listing') ? $class::listing() : null;
    }

    /**
     * What the head of the page of an item shows, as its row in the list does (`header`): `title` the attribute of its name, with its
     * `picture`, `default`, `subtitle` and `subtitleLink`, and `indicators`, the ones of its status.
     *
     * @return array{header?:array<string,mixed>}
     */
    private static function header(string $type): array
    {
        $listing = self::listing($type);
        if ($listing === null || $listing->columns === []) {
            return [];
        }
        $name = $listing->columns[0];
        $status = array_values(array_filter($listing->columns, static fn(array $column): bool => $column['type'] === 'indicators'))[0] ?? null;

        return ['header' => ['title' => $name['name']]
            + array_intersect_key($name, ['picture' => 1, 'default' => 1, 'subtitle' => 1, 'subtitleLink' => 1])
            + ['indicators' => $status['indicators'] ?? []]];
    }

    /**
     * What a row of the health says (Health): a setting, a page or what an install needs, asked or shown.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function healthRow(): array
    {
        $field = static fn(string|array $type, string $description, array $more = []): array => ['type' => $type, 'description' => $description] + $more;

        return [
            'id' => $field('string', 'The id of the line, to check it again alone (`getHealthCheck`)'),
            'label' => $field('string', 'The setting as the ini file writes it (its key), the page, or what an install needs'),
            'kind' => $field('string', 'What the line is, which says its fields: `address` (asked: `url`, `expectedStatus`, `status`, `ms`...), `value` (a setting that is not an address, shown: `text`), `secret` (never shown, only whether it is `set`), `requirement` (an extension of PHP: `installed`, `version`), `folder` (`path`, `exists`, `writable`), `file` (`path`, `readable`), `option` (the options of the grid a simulator has: `missing`, `fix`)', ['enum' => ['address', 'value', 'secret', 'requirement', 'folder', 'file', 'option']]),
            'reason' => $field('string', 'Why a line is not asked, a key of the catalog: `health_unset`, not set, so neither served nor tested'),
            'url' => $field('string', 'The address asked (an address)'),
            'text' => $field('string', 'A value that is not an address, shown as it is'),
            'set' => $field('boolean', 'Whether a secret is set (a secret)'),
            'installed' => $field('boolean', 'Whether an extension is installed (a requirement)'),
            'version' => $field(['string', 'null'], 'Its version, null for a polyfill (a requirement)'),
            'path' => $field('string', 'Where it is (a folder, a file)'),
            'exists' => $field('boolean', 'Whether the folder is there (a folder)'),
            'writable' => $field('boolean', 'Whether this user writes in it, or can make it (a folder)'),
            'readable' => $field('boolean', 'Whether this user reads it (a file)'),
            'missing' => $field('array', 'The options of the grid the simulator misses (an option)'),
            'fix' => $field('string', 'The operation that repairs it (an option): `POST /grids/{grid}/options/repair`'),
            'display' => $field('string', 'The address as the config writes it, when the one asked differs (a profile with [AGENT_NAME], asked with the name of an avatar)'),
            'tag' => $field('string', 'How it was asked or what it is, translated (without data, with valid post data, Web search)'),
            'level' => $field('string', 'ok: it is as it should (an address answers as it should, a folder is writable...); slow: an address took longer than the timeout (3 s) and answered as it should when asked again, longer (10 s), a page made after a deploy; error: it is not; unset: not set, so neither served nor tested (`reason`); none: nothing to check (a value shown)', ['enum' => ['ok', 'slow', 'error', 'unset', 'none']]),
            'status' => $field('string', 'What was found, translated: the HTTP code or the error of curl (an address), installed 8.3 (a requirement), writable, readable, set, as the grid says, missing...'),
            'ms' => $field(['integer', 'null'], 'How long it took to answer, in milliseconds (an address)'),
            'detail' => $field('string', 'More on it: the error of curl, what get_grid_info says when it differs, the file or folder a row is about'),
            'expectedStatus' => $field('string', 'What the address must answer: `2xx` for a page, the codes expected of it (`400, 405` for a service asked without data, `418` for an address that is not a page), `3xx` for one that must send elsewhere'),
            'expectedLocation' => $field('string', 'Where an address that must send elsewhere must send to: the registration page, for the profile of a site asked signed out, as the check is'),
            'location' => $field('string', 'Where the address led (its redirections followed), or where it sends to (one that must send elsewhere), when it is not the address asked'),
            'context' => $field('object', 'The full context of a setting, which says what it is (the same key is several things: SearchURL of [LoginService] is the web search of the viewers, of [Search] the search the simulators ask): `instance` (robust, opensim, moneyserver; grid_info for what the grid says), `file` (the file of the grid it was read in), `section`, `key`'),
            'expected' => $field('string', 'What a wrong or missing address should be, as the options of the grid write it (its constants read): a site says should be this, is that'),
            'snippet' => $field('string', 'The line to write in the file of the group (`file`) for it, its section first, as the options of the grid write it (its constants as references)'),
            'name' => $field('string', 'What the setting or the page is, a key of the catalog (`setting_<name>`): a site shows it, with the key of the row in small'),
            'description' => $field('string', 'What it is for, a key of the catalog (`setting_<name>_help`)'),
            'thirdParty' => $field('boolean', 'A service that does not answer and that an application of its own serves (the helpers of the engine, or another one), not the helpers of the grid: `health_third_party` says it'),
            'checkedAt' => $field('integer', 'When this line was checked (Unix time): with all of them (`checkedAt` of the health), or alone later (`getHealthCheck`, which always checks it now)'),
            'cert' => $field('boolean', 'The certificate of the address is one this server does not trust (expired, self-signed, another name): told apart, it does not change the level, what counts is what the address answers'),
        ];
    }

    /** The `x-form` of an item of a resource: its form, the levels of its fields, the head of its page. */
    public static function formDocument(string $type, ItemForm $form): array
    {
        return self::fieldLevels($type, $form->document()) + self::header($type);
    }

    /**
     * The fields of a page that need more than the change of the page say it (`access.level`, Access::fieldLevel()).
     *
     * @param array<string,mixed> $form the x-form of the page
     * @return array<string,mixed>
     */
    private static function fieldLevels(string $type, array $form): array
    {
        foreach ($form['fields'] ?? [] as $i => $field) {
            if (($field['readOnly'] ?? false) === true) {
                continue;
            }
            $form['fields'][$i]['access'] = ($field['access'] ?? []) + ['level' => Access::fieldLevel($type, (string) $field['name'])];
        }

        return $form;
    }

    /**
     * What the page of an item of a resource shows and lets change, as its model describes it (ItemForm): `x-form` of the operation
     * that reads it. Null for a resource whose model does not describe it.
     */
    /**
     * What a site shows to make an item of a resource, as its model describes it (creationForm()): `x-form` of the operation that
     * makes one. Null for a resource whose model does not describe it.
     *
     * @return ?array<string,mixed>
     */
    public static function creationForm(string $type): ?array
    {
        $class = Api::RESOURCES[$type] ?? Api::GRID_RESOURCES[$type] ?? null;

        return $class !== null && method_exists($class, 'creationForm') ? $class::creationForm() : null;
    }

    public static function itemForm(string $type): ?ItemForm
    {
        $class = Api::RESOURCES[$type] ?? Api::GRID_RESOURCES[$type] ?? null;

        return $class !== null && method_exists($class, 'itemForm') ? $class::itemForm() : null;
    }

    /**
     * @param array<string,list<string>> $filters
     * @return list<array<string,mixed>>
     */
    private static function listParameters(array $filters, ?Listing $listing = null): array
    {
        $sorts = $listing?->sorts() ?? [];
        $parameters = [
            [
                'name' => 'search',
                'in' => 'query',
                'schema' => ['type' => 'string'],
            ] + ($listing === null || $listing->search === [] ? [] : ['description' => 'Looks in ' . implode(', ', $listing->search)]),
            [
                'name' => 'sort',
                'in' => 'query',
                'description' => 'A column, `-` before it for the descending order',
                'schema' => ['type' => 'string'] +
                    ($sorts === [] ? [] : ['enum' => [...$sorts, ...array_map(static fn(string $sort): string => "-$sort", $sorts)]]),
            ],
            ['name' => 'page[number]', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1]],
            ['name' => 'page[size]', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 0]],
        ];
        return [...$parameters, ...self::filterParameters($filters, $listing)];
    }

    /**
     * The parameters of the filters of a list.
     *
     * @param array<string,list<string>> $filters
     * @return list<array<string,mixed>>
     */
    private static function filterParameters(array $filters, ?Listing $listing = null): array
    {
        $parameters = [];
        foreach ($filters as $name => $values) {
            // A filter that takes several values: they are separated by commas
            $multiple = $listing?->multiple($name) ?? false;
            $parameters[] = [
                'name' => "filter[$name]",
                'in' => 'query',
                'schema' => ['type' => 'string'] + ($values === [] || $multiple ? [] : ['enum' => $values]),
            ] +
                ($multiple
                    ? [
                        'description' => 'One or more' . ($values === [] ? '' : ' of ' . implode(', ', $values)) .
                            ', separated by commas: what has any of them' .
                            ($values === [] ? " (`meta.available.$name` of the list gives the ones there are)" : ''),
                    ]
                    : []);
        }

        return $parameters;
    }

    /** @return array<string,mixed> */
    public static function gridParameter(): array
    {
        return [
            'name' => 'grid',
            'in' => 'path',
            'required' => true,
            'description' => 'The nick of the grid',
            'schema' => ['type' => 'string'],
        ];
    }

    /** @return array<string,mixed> */
    private static function idParameter(): array
    {
        return ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']];
    }

    /** @return array<string,mixed> */
    private static function documentResponse(string $description, string $schema, bool $list): array
    {
        $item = ['$ref' => "#/components/schemas/$schema"];

        return [
            'description' => $description,
            'content' => [
                Response::JSON_API => [
                    'schema' => [
                        'type' => 'object',
                        'properties' => $list
                            ? ['data' => ['type' => 'array', 'items' => $item], 'meta' => ['type' => 'object']]
                            : ['data' => $item],
                    ],
                ],
            ],
        ];
    }

    /**
     * The answer to a new avatar whose email is to be confirmed: the registration that waits for it, nothing made yet.
     *
     * @return array<string,mixed>
     */
    private static function registrationResponse(): array
    {
        $string = ['type' => 'string'];

        return [
            'description' =>
                'With confirmEmail: the registration is kept, the avatar is made once its email is confirmed (see Avatar access). `meta.emailConfirmation` says `sent`, `not sent` (the mail failed) or `given` (with `meta.confirmation`)',
            'content' => [
                Response::JSON_API => [
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'data' => [
                                'type' => 'object',
                                'properties' => [
                                    'type' => ['type' => 'string', 'enum' => ['registrations']],
                                    'id' => ['type' => 'string', 'description' => 'The UUID the avatar will have'],
                                    'attributes' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'avatar' => $string,
                                            'name' => $string,
                                            'firstName' => $string,
                                            'lastName' => $string,
                                            'email' => $string,
                                            'model' => ['type' => ['string', 'null'], 'description' => 'The name of the model'],
                                            'expires' => [
                                                'type' => 'integer',
                                                'description' => 'When it is forgotten unless its email is confirmed (Unix time)',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                            'meta' => ['type' => 'object'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string,mixed> */
    public static function problem(string $description): array
    {
        return [
            'description' => $description,
            'content' => [Problem::CONTENT_TYPE => ['schema' => ['$ref' => '#/components/schemas/Problem']]],
        ];
    }
}
