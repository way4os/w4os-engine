<?php

declare(strict_types=1);

namespace OpenSim\Api;

/**
 * The endpoints of a grid that are not resources: what OpenSimulator and the viewers call in a format of their own (the search, the
 * currency, the land tools, the offline messages, the registration of the simulators), the pages of the helpers, and `get_grid_info`
 * of Robust. They are in the contract as endpoints of their own, with the format they have; their address is the grid's to choose, the
 * contract gives the one the engine serves them at.
 *
 * @see Formats
 */
final class Endpoints
{
    /**
     * @return array{paths:array<string,mixed>,tags:list<array{name:string,description:string}>,groups:array<string,list<string>>}
     */
    public static function document(): array
    {
        $paths = [];
        $tags = [
            [
                'name' => 'Search',
                'description' =>
                    'The search of the grid, made by the helpers: the simulators register to it and tell it their regions (a snapshot the helpers read), the viewers ask it for places, popular places, land for sale, events and classifieds. The format is the one of the module OpenSimSearch of the simulators; the address is the grid\'s to choose (`SearchURL` of `[Search]` and `DATA_SRV_*` of `[DataSnapshot]` in OpenSim.ini). A search can be shared by several grids: `gk`, the gatekeeper of the grid that asks, tells for which the search is made (the viewers do not send it). These calls need no API key: the viewers and the simulators do not send one.',
            ],
            [
                'name' => 'Economy',
                'description' =>
                    'The currency and the land tools of the grid, as the viewers and the money modules call them: XML-RPC at `currency.php` and `landtool.php` under the economy address the grid gives its viewers (`economy` of `[GridInfoService]`). Nothing is served without the key of the grid (`MoneyScriptAccessKey` of `[MoneyServer]`): a quote is tied to its purchase with it and to the address of who asked. An avatar proves who they are with their secure session, a region with its secret: no API key is needed, the viewers do not send one.',
            ],
            [
                'name' => 'Offline messages',
                'description' =>
                    'The messages for the avatars who are not there, with the module V1 of the simulators (`OfflineMessageModule = OfflineMessageModule`, `OfflineMessageURL` of `[Messaging]`), which posts them here in XML. The messages are kept in the table of the offline messages of Robust, the one its module V2 uses; an avatar who asked for it in their viewer also gets them by email. The other choice is the module V2 and the service of Robust, which needs none of this: with the helpers, the messages are mailed on request and none is lost; with Robust, nothing more is run, no mail is sent and 25 messages are kept per avatar.',
            ],
            [
                'name' => 'Avatar access',
                'description' =>
                    "What a site or the command line needs to let the people of the grid in, without holding any logic: sign an avatar in with its name (or its email) and its password, checked in the databases of the grid as Robust keeps them; a forgotten password; the confirmation of the email of a new avatar (`confirmEmail` of a new avatar: it is only a registration, nothing is written in the grid until its email is confirmed), or of a new email (`confirmEmail` of a change of an avatar: the email changes once it is confirmed).\n\nEvery problem has a `code` that says what went wrong, for a site to tell it in its own language: `sign-in-refused`, `account-disabled` (a manager disabled it), `email-unconfirmed` (it waits for the confirmation of its email), `name-reserved`, `name-taken`, `token-refused`... (see the Problem schema). The `detail` is the English one.\n\nA forgotten password and a confirmation are a one-time token, kept hashed in the database of the passwords (`avatar_tokens`, and `avatar_registrations` for a new avatar, with its password hashed as Robust keeps it; an expired registration is forgotten whenever another one comes or a token is checked), mailed to the avatar with a link to the page that takes it: the `link` the caller gives (`{token}` in it is replaced by the token, else `token=` is added), else the page of the grid (`password` of `[GridInfoService]` for a password, `register` for a confirmation). The mail gives the token as a code too. Whoever cannot mail it (no mail system, an administrator, an agent that delivers it otherwise) sends `send: false` and gets the token instead. The mails come from `MailSender` of `[Helpers]`, else no-reply@ the host of the grid.\n\nA token is valid once: a day for a password, two days for a confirmation (the avatar may ask for another one). An avatar has one token at a time for each.",
            ],
            [
                'name' => 'Health',
                'description' => 'Whether the grid and its web services answer as they should.',
            ],
            [
                'name' => 'Addresses',
                'description' =>
                    'Where the services of the grid are, and what the grid says of itself. The engine reads them from the files of the grid and from its `get_grid_info`, and gives them to the helpers, the manager and the plugins, which do not read the config again.',
            ],
            [
                'name' => 'Monitor',
                'description' =>
                    'The state of the grid at a glance: its Robust, its simulators (on this machine and elsewhere), its regions, who is in world. As data, as the lines of `opensim status`, or as a picture for a prim. The diagnosis, address by address, is the health.',
            ],
            [
                'name' => 'Message of the day',
                'description' => 'The text the viewers show at login.',
            ],
            [
                'name' => 'Text images',
                'description' => 'Pictures of a text, for the signs and the boards in world.',
            ],
            [
                'name' => 'Logos',
                'description' => 'The logos of w4os and OpenSimulator the engine ships for the sites; the logo of a grid is `logo` of its addresses.',
            ],
            [
                'name' => 'Pages',
                'description' =>
                    'The pages the helpers show to the people, in HTML, at the addresses the grid gives (`GridInfoService` and `LoginService` in Robust.ini). They are made from the resources of this API: the guide shows `/grids/{grid}/destinations`, the profile an avatar.',
            ],
            [
                'name' => 'Grid info',
                'description' => 'What Robust itself serves, which the engine reads.',
            ],
            [
                'name' => 'Setup',
                'description' =>
                    'The rules of the setup, for the tools that make a grid: from a few answers, the whole setup they stand for and what is wrong with them. Nothing is written, the tool shows the setup, asks again what is wrong and applies it.',
            ],
            [
                'name' => 'System',
                'description' => 'The resolved OpenSim system layout and its Unix identities.',
            ],
        ];

        $document = static fn(string $type, array $attributes): array => [
            Response::JSON_API => [
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'data' => [
                            'type' => 'object',
                            'properties' => [
                                'type' => ['const' => $type],
                                'id' => ['type' => 'string'],
                                'attributes' => ['type' => 'object', 'properties' => $attributes],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        // The settings of a grid by group, each with its form, the same fields for its reading and its change
        foreach (\OpenSim\Models\GridSettings::groups() as $group) {
            $form = \OpenSim\Models\GridSettings::form($group);
            $properties = [];
            foreach ($form['fields'] as $field) {
                $properties[$field['name']] = match (true) {
                    $field['multiple'] ?? false => ['type' => 'array', 'items' => ['type' => 'string']],
                    $field['type'] === 'connection-string' => ['type' => 'object', 'properties' => array_combine(
                        array_column($field['fields'], 'name'),
                        array_map(static fn(array $part): array => ['type' => $part['type'] === 'integer' ? 'integer' : 'string'], $field['fields']),
                    ) + (isset($field['fallback']) ? ['useDefault' => ['type' => 'boolean', 'description' => 'true: the database the grid uses by default, the setting removed']] : []), 'nullable' => true],
                    in_array($field['type'], ['integer', 'number', 'boolean'], true) => ['type' => $field['type']],
                    default => ['type' => 'string'] + (isset($field['options']) ? ['enum' => array_keys($field['options'])] : []),
                } + (($field['readOnly'] ?? false) ? ['readOnly' => true] : []);
            }
            $body = ['type' => 'object', 'properties' => ['data' => ['type' => 'object', 'properties' => ['type' => ['const' => 'settings'], 'id' => ['const' => $group], 'attributes' => ['type' => 'object', 'properties' => $properties]]]]];
            $name = ucfirst($group);
            $paths["/grids/{grid}/settings/$group"] = [
                'get' => [
                    'operationId' => "get{$name}Settings",
                    'tags' => ['Settings'],
                    'summary' => "The settings of the grid: $group",
                    'description' => \OpenSim\Models\GridSettings::description($group),
                    'parameters' => [Contract::gridParameter()],
                    'x-form' => $form,
                    'responses' => [
                        '200' => ['description' => 'The settings, as the grid says them, else their defaults'],
                        '404' => Contract::problem('The grid is not known here'),
                    ],
                ],
                'patch' => [
                    'operationId' => "update{$name}Settings",
                    'tags' => ['Settings'],
                    'summary' => "Change the settings of the grid: $group",
                    'description' => 'The settings given, checked against their field (`invalid-request`): each is written in the file of the grid that says it already (Robust, OpenSim.ini, Helpers.ini, MoneyServer.ini), else in the file of its instance (the registry of the settings, `OpenSim\\Config\\Settings`). A value null is no change; a field `readOnly` is set by the options of the grid, refused here unless it is the value it has; a secret is given back as `********` when it is set, and `********` sent back keeps it. The answer is the settings now.',
                    'parameters' => [Contract::gridParameter()],
                    'requestBody' => ['required' => true, 'content' => [Response::JSON_API => ['schema' => $body]]],
                    'responses' => [
                        '200' => ['description' => 'The settings now'],
                        '400' => Contract::problem('A value its field does not take, or a setting the group does not have (`invalid-request`)'),
                        '404' => Contract::problem('The grid is not known here'),
                    ],
                ],
            ];
        }
        $paths['/grids/{grid}/site-description']['get'] = [
            'operationId' => 'getSiteDescription',
            'tags' => ['Setup'],
            'summary' => 'The description of the grid for a site',
            'description' => 'What a site needs of the grid for its helpers, from the files of this machine: `grid` who it is (`nick`, `name`, `baseHostname`, `publicPort`, `standalone`), `config` the settings its helpers read by file (Robust, OpenSim, Helpers, MoneyServer) and section (the addresses, the names of the databases as `Data Source=;Database=<name>;`, the settings of the helpers, the database of the money server by its name), `destinations` the text of its list of destinations when it is a file of this machine (its `GuideSource` then empty), `checkedAt` when it was read, for a site to keep it. Never a secret: no password, no key, no server of a database (the site reaches them with its own access, `Environment::system(databases:)`). The engine a site bundles makes its grid of it with `OpenSim\\Models\\SiteDescription::grid()`, never of a folder.',
            'parameters' => [Contract::gridParameter()],
            'responses' => [
                '200' => ['description' => 'The description of the grid'],
                '404' => Contract::problem('The grid is not known here'),
                '503' => Contract::problem('The grid has no files on this machine (`channel-unavailable`)'),
            ],
        ];
        $paths['/grids/{grid}/options/repair']['post'] = [
            'operationId' => 'repairOptions',
            'tags' => ['Setup'],
            'summary' => 'Repair the options of the simulators',
            'description' => 'The options of the grid (the profiles, the groups...: the ones on by default when the grid says none) its simulators of this machine miss in their files, made before an option became a preset or by an older engine. `mode` `plan` (the default) says them by simulator (`simulators`), `apply` lays them again (`applied`, as `opensim enable` does: the simulators read them when they start; what Robust must carry is in `notes`). The health of the grid says them too (`options`).',
            'parameters' => [Contract::gridParameter()],
            'requestBody' => [
                'required' => false,
                'content' => [
                    Response::JSON_API => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'type' => ['const' => 'options'],
                                        'attributes' => ['type' => 'object', 'properties' => ['mode' => ['type' => 'string', 'enum' => ['plan', 'apply']]]],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'responses' => [
                '200' => ['description' => 'The options missed by simulator, the ones laid again, what is left to do'],
                '404' => Contract::problem('The grid is not known here'),
            ],
        ];
        $paths['/grids/{grid}/addresses']['get'] = [
            'operationId' => 'getAddresses',
            'tags' => ['Addresses'],
            'summary' => 'Addresses',
            'description' =>
                "The addresses of the grid: the ones it gives its viewers and its simulators, each with where it comes from (`provider`: a Robust or a simulator that serves it, `simulator`, `live`: what the grid says at `get_grid_info`, `config`, `helpers`: a tool of the helpers no setting gives, where the helpers the grid has are; an address the grid does not set it has not, whatever the helpers would answer by default). `helpers` is where the helpers are, the ones the grid sets (`HelpersURL` of Helpers.ini, else where its economy is), none when it sets none (a page under it is theirs, any other the site's: `servedBy`), `economy` the address `currency.php` and `landtool.php` are under, `search` the web search, `searchApi` the one the simulators ask (`query`), `logo` the logo of the grid for the top bar of its sites (`LogoURL` of `[Helpers]`). These are links, not identities: the identity of the grid is its `uri`.",
            'parameters' => [Contract::gridParameter()],
            'responses' => [
                '200' => [
                    'description' => 'The addresses',
                    'content' => $document('addresses', [
                        'login' => [
                            'type' => ['string', 'null'],
                            'description' => 'The login address, as the grid says it',
                        ],
                        'uri' => ['type' => ['string', 'null'], 'description' => 'Its host and port'],
                        'logo' => [
                            'type' => ['string', 'null'],
                            'description' => 'The address of the logo of the grid (`LogoURL` of `[Helpers]`), for the top bar of its sites',
                        ],
                        'helpers' => [
                            'type' => ['string', 'null'],
                            'description' => 'The base of the helpers: `HelpersURL` of `[Helpers]`, else the economy, else the web site and /helpers (its source is `helpers` of `sources`: config, economy or default). A page under it is served by the helpers, any other by the site',
                        ],
                        'economy' => ['type' => ['string', 'null']],
                        'search' => ['type' => ['string', 'null']],
                        'searchApi' => ['type' => ['string', 'null']],
                        'register' => ['type' => ['string', 'null']],
                        'offline' => ['type' => ['string', 'null']],
                        'addresses' => [
                            'type' => 'object',
                            'description' => 'Every address, by the key of its service',
                            'additionalProperties' => ['type' => 'string'],
                        ],
                        'services' => [
                            'type' => 'array',
                            'description' => 'Each service the grid has, what it is and where its address is set, sorted file by file, then section by section: `key`, `url`, `source` (as above), `usage` (`robust-simulators` and `robust-viewers`: a service of Robust, used by the simulators or by the viewers and the other grids; `helper-simulators` and `helper-viewers`: a helper the simulators or the viewer call, query.php, currency.php; `page`: a web page the viewer or the site shows; `web-tool`: a web tool used with a script in-world, textgen.php; `task`: a task of the grid; `web-assets`: the web assets of the sites; `usage_<usage>` its name in the catalog), `file` (`Robust.HG.ini` or `Robust.ini`, `OpenSim.ini` for what the simulators are given, `Helpers.ini`), `section` and `setting` (its exact key: `[AssetService] AssetServerURI`; the connector of `[ServiceList]` for a service the simulators are not given), `info` (the key of get_grid_info that tells it), `label` and `description` (keys of the catalog)',
                            'items' => ['type' => 'object'],
                        ],
                        'servedBy' => [
                            'type' => 'object',
                            'description' => 'Who serves each page for people (search, profile, guide, welcome, status, directory): `helpers` when its address is under the base of the helpers, else `site`; null for a page without an address',
                            'additionalProperties' => ['type' => ['string', 'null'], 'enum' => ['helpers', 'site', null]],
                        ],
                        'sources' => [
                            'type' => 'object',
                            'description' => 'Where each comes from',
                            'additionalProperties' => ['type' => ['string', 'null']],
                        ],
                    ]),
                ],
                '404' => Contract::problem('The grid is not known here'),
            ],
        ];
        $items = static fn(string $type, array $attributes): array => [
            Response::JSON_API => [
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'data' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'type' => ['const' => $type],
                                    'id' => ['type' => 'string', 'description' => 'Its UUID'],
                                    'attributes' => ['type' => 'object', 'properties' => $attributes],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $avatar = [
            'name' => 'id',
            'in' => 'path',
            'required' => true,
            'description' => 'The UUID or the name of the avatar',
            'schema' => ['type' => 'string'],
        ];
        $picture = [
            'type' => ['string', 'null'],
            'description' => 'The UUID of the asset of its picture (`/grids/{grid}/assets/{picture}.jpg` shows it), null without one',
        ];
        // What the viewer shows of a pick and of a classified
        $place = [
            'name' => ['type' => 'string'],
            'description' => ['type' => 'string'],
            'region' => ['type' => ['string', 'null'], 'description' => 'The name of its region'],
            'parcel' => ['type' => ['string', 'null'], 'description' => 'The name of its parcel'],
            'position' => [
                'type' => ['array', 'null'],
                'items' => ['type' => 'integer'],
                'description' => 'x, y, z in the region, in meters',
            ],
            'teleport' => [
                'type' => ['string', 'null'],
                'description' => 'The link a viewer follows to go there (hop://host:port/Region%20Name/x/y/z)',
            ],
        ];
        $paths['/grids/{grid}/avatars/{id}/picks']['get'] = [
            'operationId' => 'listAvatarPicks',
            'tags' => ['Avatars'],
            'summary' => 'Picks of an avatar',
            'description' =>
                'The picks the profile of the avatar shows (the enabled ones, in the order the avatar gave them), from the profiles database of the grid. Who may see them is the site\'s to decide: the engine only asks for its API key. None when the profiles cannot be reached.',
            'parameters' => [Contract::gridParameter(), $avatar],
            'responses' => [
                '200' => [
                    'description' => 'The picks',
                    'content' => $items('picks', [
                        ...$place,
                        'gatekeeper' => [
                            'type' => ['string', 'null'],
                            'description' => 'The gatekeeper of the grid of its region, as the viewer wrote it',
                        ],
                        'picture' => $picture,
                    ]),
                ],
                '404' => Contract::problem('The grid or the avatar is not known here'),
            ],
        ];
        $paths['/grids/{grid}/avatars/{id}/classifieds']['get'] = [
            'operationId' => 'listAvatarClassifieds',
            'tags' => ['Avatars'],
            'summary' => 'Classifieds of an avatar',
            'description' =>
                'The classifieds of the avatar that have not expired, the newest first, from the profiles database of the grid. Who may see them is the site\'s to decide: the engine only asks for its API key. None when the profiles cannot be reached.',
            'parameters' => [Contract::gridParameter(), $avatar],
            'responses' => [
                '200' => [
                    'description' => 'The classifieds',
                    'content' => $items('classifieds', [
                        ...$place,
                        'picture' => $picture,
                        'created' => ['type' => 'integer', 'description' => 'When it was made, Unix time'],
                        'expires' => ['type' => 'integer', 'description' => 'When it expires, Unix time'],
                    ]),
                ],
                '404' => Contract::problem('The grid or the avatar is not known here'),
            ],
        ];
        $paths['/grids/{grid}/avatars/{id}/groups']['get'] = [
            'operationId' => 'listAvatarGroups',
            'tags' => ['Avatars'],
            'summary' => 'Groups of an avatar',
            'description' =>
                'The groups the profile of the avatar shows (the ones it chose to show in the viewer), by name, from the groups tables of the grid (the module Groups of the core). Who may see them is the site\'s to decide: the engine only asks for its API key. None when the grid has no groups.',
            'parameters' => [Contract::gridParameter(), $avatar],
            'responses' => [
                '200' => [
                    'description' => 'The groups',
                    'content' => $items('groups', [
                        'name' => ['type' => 'string'],
                        'title' => [
                            'type' => ['string', 'null'],
                            'description' => 'The title the avatar wears in the group (the one of its role), null without one',
                        ],
                        'picture' => [
                            'type' => ['string', 'null'],
                            'description' => 'The UUID of the asset of the insignia of the group (`/grids/{grid}/assets/{picture}.jpg` shows it), null without one',
                        ],
                    ]),
                ],
                '404' => Contract::problem('The grid or the avatar is not known here'),
            ],
        ];
        // The stream of an avatar (w4os-flux): its posts, on its profile and in the Feed tab of the viewers
        $post = [
            'avatar' => ['type' => 'string', 'description' => 'The UUID of its author'],
            'name' => ['type' => 'string', 'description' => 'The name of its author when it was written'],
            'content' => ['type' => 'string', 'description' => 'Plain text, 1000 characters at most'],
            'created' => ['type' => 'integer', 'description' => 'When it was written (Unix time)'],
            'source' => ['type' => 'string', 'description' => 'Where it was brought from (`w4os-wp:<ID>`), empty for a post written here'],
        ];
        $paths['/grids/{grid}/avatars/{id}/posts']['get'] = [
            'operationId' => 'listAvatarPosts',
            'tags' => ['Avatars'],
            'summary' => 'Posts of an avatar',
            'description' =>
                'The stream of the avatar (w4os-flux): its posts, newest first (100 at most), kept by the engine in `avatar_posts` of the profiles database of the grid. Its profile page shows them, which the Feed tab of the viewers opens (`web_profile_url`).',
            'parameters' => [Contract::gridParameter(), $avatar],
            'responses' => [
                '200' => ['description' => 'The posts', 'content' => $items('posts', $post)],
                '404' => Contract::problem('The grid or the avatar is not known here'),
            ],
        ];
        $paths['/grids/{grid}/avatars/{id}/posts']['post'] = [
            'operationId' => 'createAvatarPost',
            'tags' => ['Avatars'],
            'summary' => 'Post on the stream of an avatar',
            'description' => 'A post on the profile of the avatar, as the avatar itself or a manager: `content`, plain text (its tags are taken out), 1 to 1000 characters (`post-invalid`).',
            'parameters' => [Contract::gridParameter(), $avatar],
            'requestBody' => [
                'required' => true,
                'content' => [
                    Response::JSON_API => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'type' => ['const' => 'posts'],
                                        'attributes' => ['type' => 'object', 'properties' => ['content' => $post['content']], 'required' => ['content']],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            // What a site shows to write one, and to delete one (its own stream, `self`)
            'x-form' => [
                'fields' => [['name' => 'content', 'label' => 'post_label', 'type' => 'text', 'maxLength' => \OpenSim\Flux\Posts::MAX, 'required' => true, 'description' => 'post_help']],
                'submit' => 'action_post',
                'actions' => [['name' => 'deletePost', 'label' => 'action_delete_post', 'method' => 'DELETE', 'path' => '/grids/{grid}/avatars/{id}/posts/{post}', 'ask' => 'ask_delete_post']],
            ],
            'responses' => [
                '201' => ['description' => 'The post', 'content' => $items('posts', $post)],
                '400' => Contract::problem('The post is empty or too long (`post-invalid`)'),
                '404' => Contract::problem('The grid or the avatar is not known here'),
            ],
        ];
        $paths['/grids/{grid}/avatars/{id}/posts/{post}']['delete'] = [
            'operationId' => 'deleteAvatarPost',
            'tags' => ['Avatars'],
            'summary' => 'Delete a post of an avatar',
            'description' => 'As its author or a manager. A post of another avatar is not there (404).',
            'parameters' => [
                Contract::gridParameter(),
                $avatar,
                ['name' => 'post', 'in' => 'path', 'required' => true, 'description' => 'The id of the post', 'schema' => ['type' => 'string']],
            ],
            'responses' => [
                '204' => ['description' => 'Deleted'],
                '404' => Contract::problem('The grid, the avatar or its post is not known here'),
            ],
        ];
        $paths['/grids/{grid}/info']['get'] = [
            'operationId' => 'getInfo',
            'tags' => ['Addresses'],
            'summary' => 'What the grid says',
            'description' =>
                '**Every** element the grid gave at its `get_grid_info`, the free ones included (what is not standard is passed on), as it said them when it was last verified; `live` is false when the grid was not verified and the elements are what its config says. Nothing is asked here: a verification is joining a grid, starting it, or `Grid::refreshInfo()`.',
            'parameters' => [Contract::gridParameter()],
            'responses' => [
                '200' => [
                    'description' => 'The elements of the grid',
                    'content' => $document('info', [
                        'live' => ['type' => 'boolean'],
                        'login' => ['type' => ['string', 'null']],
                        'uri' => ['type' => ['string', 'null']],
                        'logo' => [
                            'type' => ['string', 'null'],
                            'description' => 'The address of the logo of the grid (`LogoURL` of `[Helpers]`), null when it has none',
                        ],
                        'elements' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
                    ]),
                ],
                '404' => Contract::problem('The grid is not known here'),
            ],
        ];
        $paths['/grids/{grid}/monitor']['get'] = [
            'operationId' => 'getMonitor',
            'tags' => ['Monitor'],
            'summary' => 'Status of the grid',
            'description' =>
                'The state of the grid at a glance. `level` is the one of the own services of the grid: `ok`, `degraded` (a Robust of several does not run, or the database of its grid service does not answer), `down` (its Robust does not run; the simulator of a standalone, which is its grid) or `unknown`; a simulator stopped and regions offline happen and leave the grid ok, they have their own counts (`simulators`, `regions`). The instances are the ones of this machine, with what they use; the simulators add the ones that run elsewhere, which the regions of the grid tell about. `format=text` gives the lines of `opensim status`, `format=png` a picture of them (a square, the text colored by the level) to put on a prim; it takes the same `/text-images` fonts and colors.',
            'parameters' => [
                Contract::gridParameter(),
                [
                    'name' => 'format',
                    'in' => 'query',
                    'required' => false,
                    'description' => 'The form of the answer',
                    'schema' => ['type' => 'string', 'enum' => ['json', 'text', 'png'], 'default' => 'json'],
                ],
                [
                    'name' => 'size',
                    'in' => 'query',
                    'required' => false,
                    'description' => 'png: the size of the font, from the width of the lines by default',
                    'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000],
                ],
                [
                    'name' => 'color',
                    'in' => 'query',
                    'required' => false,
                    'description' => 'png: the color of the text, from the level by default',
                    'schema' => ['type' => 'string'],
                ],
                [
                    'name' => 'background',
                    'in' => 'query',
                    'required' => false,
                    'description' => 'png: the color of the background',
                    'schema' => ['type' => 'string', 'default' => 'black'],
                ],
            ],
            'responses' => [
                '200' => [
                    'description' => 'The status',
                    'content' => $document('monitor', [
                        'grid' => ['type' => 'string'],
                        'name' => ['type' => 'string'],
                        'level' => ['type' => 'string', 'enum' => ['ok', 'degraded', 'down', 'unknown']],
                        'login' => ['type' => ['string', 'null']],
                        'instances' => [
                            'type' => 'array',
                            'items' => ['type' => 'object'],
                            'description' => 'name, type (robust or simulator), running, pid, cpu, memory, rss',
                        ],
                        'simulators' => [
                            'type' => 'array',
                            'items' => ['type' => 'object'],
                            'description' => 'name (the one to show: "Test Sim"; host:port for a simulator elsewhere), slug (its instance, `friendly_fowl.test_sim`, null elsewhere), uri, local, running, regions, online',
                        ],
                        'regions' => ['type' => 'object', 'description' => 'total and online'],
                        'users' => [
                            'type' => ['object', 'null'],
                            'description' => 'The numbers of the grid (members, in world...)',
                        ],
                        'up' => ['type' => 'integer'],
                        'down' => ['type' => 'integer'],
                        'summary' => ['type' => 'string'],
                    ]) + [
                        'text/plain' => [
                            'schema' => ['type' => 'string'],
                            'examples' => [
                                'status' => [
                                    'value' =>
                                        "OK - Demo World\nNAME        TYPE       STATE  REGIONS  %CPU  %MEM\ndemo        robust     up     -        0.3   1.2\ndemo.alpha  simulator  up     3/3      2.1   6.4\n2 up - regions 3/3 - in world 4 - cpu 2.4% - mem 7.6% - rss 812345\n",
                                ],
                            ],
                        ],
                        'image/png' => ['schema' => ['type' => 'string', 'format' => 'binary']],
                    ],
                ],
                '404' => Contract::problem('The grid is not known here'),
                '503' => Contract::problem('The machine cannot draw: php-imagick is missing (png)'),
            ],
        ];
        $paths['/grids/{grid}/health/{check}']['get'] = [
            'operationId' => 'getHealthCheck',
            'tags' => ['Health'],
            'summary' => 'One line of the health, checked now',
            'description' => 'One line of the health of the grid (its `id` in `getHealth`), checked now: the address asked, what it must answer and what it answered, as a row of `getHealth`, with when it was checked (`checkedAt`), always now, never the last check kept. The last check keeps it. A site shows the lines of `getHealth` at once, then checks them one by one, a few at a time: the pages of a site call back the engine, so never all at once.',
            'parameters' => [
                Contract::gridParameter(),
                ['name' => 'check', 'in' => 'path', 'required' => true, 'description' => 'The id of the line', 'schema' => ['type' => 'string']],
            ],
            'responses' => [
                '200' => [
                    'description' => 'The line, checked now',
                    'content' => [
                        Response::JSON_API => [
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'data' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'type' => ['const' => 'health-checks'],
                                            'id' => ['type' => 'string'],
                                            'attributes' => ['type' => 'object', 'properties' => Contract::healthRow()],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                '404' => Contract::problem('The grid, or a line of its health with this id, is not known here'),
            ],
        ];
        $paths['/grids/{grid}/health']['get'] = [
            'operationId' => 'getHealth',
            'tags' => ['Health'],
            'summary' => 'Health of the grid',
            'description' =>
                'The addresses the config of the grid gives (the services of Robust, the web services, the pages), what the grid says of itself at `get_grid_info`, and the ones that are expected and not given, asked now: a service that must refuse a call without data refuses it, and answers one that makes sense. The pages for people (each answers, the base of the helpers answers, the helpers claim no page of the site), the PHP extensions the engine needs, the folders it writes (`cache`: `assets`, the pictures; `tasks`, the state of the tasks, `TasksRoot`: without it no task runs and the search index never fills) and the files it reads come with them. `level` is `error` when something does not answer as it should, `ok` when all that is asked does, `none` when there is nothing to ask. The groups are the sections of the files of the grid; each row has the address, the status (the HTTP code, or the error), the time in milliseconds and its own level. A check takes the time of the slowest address: the last one is given again with its time (`checkedAt`, `cached` true), kept by the task of the grid that checks it every 15 minutes (`health`); `refresh` checks it now. Each line has its `id`: a site shows them at once, then checks them one by one (`getHealthCheck`), a few at a time.',
            'parameters' => [
                Contract::gridParameter(),
                ['name' => 'refresh', 'in' => 'query', 'required' => false, 'description' => 'Check everything now (1, true), never the last check kept: every address asked again, two at a time, which takes the time of the slowest; `cached` false and `checkedAt` now say it was. Without it, the last check kept is given at once (`cached` true)', 'schema' => ['type' => 'string']],
            ],
            'responses' => [
                '200' => [
                    'description' => 'The health of the grid',
                    'content' => [
                        Response::JSON_API => [
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'data' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'type' => ['const' => 'health'],
                                            'id' => ['type' => 'string'],
                                            'attributes' => [
                                                'type' => 'object',
                                                'properties' => [
                                                    'checkedAt' => ['type' => 'integer', 'description' => 'When it was checked, all of it (Unix time); each line has its own (`checkedAt` of a line), a line checked alone later'],
                                                    'cached' => ['type' => 'boolean', 'description' => 'The last check kept, not one made for this request'],
                                                    'level' => [
                                                        'type' => 'string',
                                                        'enum' => ['ok', 'slow', 'error', 'none'],
                                                        'description' => 'error when a row is, else slow when one is, else ok when one is, else none (nothing to ask)',
                                                    ],
                                                    'groups' => [
                                                        'type' => 'array',
                                                        'items' => [
                                                            'type' => 'object',
                                                            'properties' => [
                                                                'file' => [
                                                                    'type' => 'string',
                                                                    'description' => 'The file the section is in',
                                                                ],
                                                                'title' => [
                                                                    'type' => 'string',
                                                                    'description' => 'The section',
                                                                ],
                                                                'rows' => [
                                                                    'type' => 'array',
                                                                    'items' => ['type' => 'object', 'properties' => Contract::healthRow()],
                                                                ],
                                                            ],
                                                        ],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                '404' => Contract::problem('The grid is not known here'),
            ],
        ];
        $paths['/grids/{grid}/search/register']['get'] = [
            'operationId' => 'registerSimulator',
            'tags' => ['Search'],
            'summary' => 'Register a simulator',
            'description' =>
                'What the module of search of a simulator calls (`DATA_SRV_<grid>` of `[DataSnapshot]` is the address): a GET with the host and the port **of the simulator**, not those of the grid. The answer is nothing: the simulator waits for it to go on starting. The helpers read its snapshot once the answer is sent, after a pause for its regions to load, and again soon after; one that does not answer is read less and less often, then forgotten. `offline` forgets it and what it told.',
            'parameters' => [Contract::gridParameter(), ...self::query(Formats::REGISTER)],
            'responses' => [
                '200' => ['description' => 'Registered (or unregistered); nothing is said'],
                '400' => self::text('The host, the port or the service is missing or wrong'),
                '404' => self::text('The grid is not known here'),
                '503' => self::text('The grid has no search database, or it cannot be reached'),
            ],
        ];

        $paths['/grids/{grid}/search/query']['post'] = self::xmlRpc(
            'searchQuery',
            'Search',
            'Search questions',
            'The questions of the viewers and of the simulators to the search. XML-RPC, as the module OpenSimSearch speaks it: the body is a method call with one struct, the answer a struct with `success`, `errorMessage` and the rows in `data` (nothing in `data` when there is no result or it failed). The grid has the status of a failure only when the request is not understood or the search is not there.',
            Formats::SEARCH,
            [Contract::gridParameter(), self::gatekeeper()],
            true,
        );
        $paths['/grids/{grid}/currency.php']['post'] = self::xmlRpc(
            'currency',
            'Economy',
            'Currency',
            'Quotes, purchases, balances and transfers. XML-RPC at `currency.php` of the economy address of the grid. The viewer asks the quote and buys; the simulators ask the balance of the avatars who are on them, move the money of a purchase, and claim the avatars that arrive. The answer is a struct with `success`, and the reason (`errorMessage`, `errorURI`) when it is false.',
            Formats::CURRENCY,
            [Contract::gridParameter()],
            false,
        );
        $paths['/grids/{grid}/landtool.php']['post'] = self::xmlRpc(
            'landTool',
            'Economy',
            'Land tools',
            'The purchase of land: what it costs and the currency it needs. XML-RPC at `landtool.php` of the economy address of the grid. The land itself is paid afterwards, by the region.',
            Formats::LAND,
            [Contract::gridParameter()],
            false,
        );

        foreach (Formats::OFFLINE as $method => $format) {
            $paths["/grids/{grid}/offline-messages/$method"]['post'] = [
                'operationId' => "offline$method",
                'tags' => ['Offline messages'],
                'summary' => $method === 'SaveMessage' ? 'Save a message' : 'Retrieve messages',
                'description' => "{$format['summary']}. What the module V1 of the simulators posts to `<OfflineMessageURL>/$method/` (the last slash is theirs, it is accepted with or without).\n\n**Request**: {$format['request']}.\n\n**Answer**: {$format['answer']}.",
                'parameters' => [Contract::gridParameter()],
                'requestBody' => [
                    'required' => true,
                    'content' => [
                        'text/xml' => [
                            'schema' => ['type' => 'string', 'description' => 'XML'],
                            'examples' => ['example' => ['value' => $format['example']]],
                        ],
                    ],
                ],
                'responses' => [
                    '200' => [
                        'description' => $format['answer'],
                        'content' => ['text/xml' => ['schema' => ['type' => 'string']]],
                    ],
                    '400' => self::xmlProblem('The body is empty, or is not XML'),
                    '404' => self::xmlProblem('The grid is not known here, or the method is not one of the module'),
                    '503' => self::xmlProblem(
                        'The grid has no database for the offline messages, or it cannot be reached',
                    ),
                ],
            ];
        }

        $page = static fn(string $id, string $summary, string $description, array $parameters = []): array => [
            'operationId' => $id,
            'tags' => ['Pages'],
            'summary' => $summary,
            'description' => $description,
            'security' => [],
            'parameters' => $parameters,
            'responses' => [
                '200' => [
                    'description' => 'The page',
                    'content' => ['text/html' => ['schema' => ['type' => 'string']]],
                ],
                '404' => ['description' => 'The grid does not serve this page'],
            ],
        ];
        $paths['/search']['get'] = $page(
            'pageSearch',
            'Web search',
            'The page the viewers open for the search. The address is `search` of `[GridInfoService]` and `SearchURL` of `[LoginService]`, which the setup keeps equal.',
        );
        $paths['/welcome']['get'] = $page(
            'pageWelcome',
            'Welcome',
            'The page the viewers show at login. The address is `welcome` of `[GridInfoService]`.',
        );
        $paths['/guide']['get'] = $page(
            'pageGuide',
            'Destination guide',
            'The page the viewers open for the destinations (`DestinationGuide` of `[LoginService]`), made from `/grids/{grid}/destinations`.',
        );
        $paths['/profile/{name}']['get'] = $page(
            'pageProfile',
            'Profile',
            'The page the viewers open for a profile (`web_profile_url` of `[GridInfoService]`, `[AGENT_NAME]` standing for the name).',
            [
                [
                    'name' => 'name',
                    'in' => 'path',
                    'required' => true,
                    'description' => 'The name of the avatar',
                    'schema' => ['type' => 'string'],
                ],
            ],
        );
        $paths['/health']['get'] = $page(
            'pageHealth',
            'Health page',
            'A management page: the same checks as `/grids/{grid}/health`, for a person to read. It is not a page of the viewers, and not public.',
        );
        $paths['/directory_info']['get'] = $page(
            'pageDirectoryInfo',
            'Directory info',
            'The grids whose simulators registered in the search of this one, with their regions and parcels.',
        );
        $paths['/blocks/{name}']['get'] = $page(
            'pageBlock',
            'Block',
            'A piece of a page (`grid-info`, `grid-status`, `motd`, `destination_guide`, `popular_places`, `directory_info`, `search`, `profile`, `health`) as an HTML fragment to embed in another site, or its data with `?format=json`.',
            [
                [
                    'name' => 'name',
                    'in' => 'path',
                    'required' => true,
                    'description' => 'The name of the block',
                    'schema' => ['type' => 'string'],
                ],
            ],
        );
        $paths['/assets/{file}']['get'] = $page(
            'pageAsset',
            'Asset',
            'A file the pages use (style sheet, script, image, font): the theme of the grid first, then the helpers\'. The file is the rest of the path.',
            [
                [
                    'name' => 'file',
                    'in' => 'path',
                    'required' => true,
                    'description' => 'The path of the file in the assets',
                    'schema' => ['type' => 'string'],
                ],
            ],
        );
        $paths['/grid_status']['get'] = $page(
            'pageGridStatus',
            'Grid status',
            'A light page, in HTML or plain text, from which the viewer takes one line of global status by keyword (ONLINE green, OFFLINE red, DEGRADED or MAINTENANCE orange). The address is `GridStatus` of `[GridInfoService]`.',
        );
        $paths['/grid_status.rss']['get'] = [
            'operationId' => 'feedGridStatus',
            'tags' => ['Pages'],
            'summary' => 'Grid status feed',
            'security' => [],
            'description' =>
                'The RSS feed the viewers read for the news of the grid (`GridStatusRSS` of `[GridInfoService]`).',
            'responses' => [
                '200' => [
                    'description' => 'The feed',
                    'content' => ['application/rss+xml' => ['schema' => ['type' => 'string']]],
                ],
                '404' => ['description' => 'The grid has no feed'],
            ],
        ];
        $paths['/get_grid_info']['get'] = [
            'operationId' => 'getGridInfo',
            'tags' => ['Grid info'],
            'summary' => 'Grid info',
            'security' => [],
            'description' =>
                'Served by Robust at its public port, not by the engine, which reads it (`Grid::gridInfo()`). The elements are the keys of `[GridInfoService]` of Robust.ini, **every one**, the free ones included: `gridname`, `gridnick`, `login` (the address of the grid, which is the truth about it whatever address it was reached at), `economy`, `search`, `welcome`... What a grid says is kept for a day when it is verified.',
            'servers' => [
                [
                    'url' => '{login}',
                    'description' => 'The login address of the grid',
                    'variables' => ['login' => ['default' => 'http://grid.example.org:8002']],
                ],
            ],
            'responses' => [
                '404' => ['description' => 'The address is not the login of a grid'],
                '200' => [
                    'description' => 'The elements of `[GridInfoService]`',
                    'content' => [
                        'text/xml' => [
                            'schema' => ['type' => 'string'],
                            'examples' => [
                                'example' => [
                                    'value' =>
                                        "<?xml version=\"1.0\"?>\n<gridinfo><gridname>Demo World</gridname><gridnick>demo</gridnick><login>http://grid.example.org:8002/</login><economy>https://web.example.org/helpers</economy><search>https://web.example.org/search</search></gridinfo>",
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $paths['/layout']['get'] = [
            'operationId' => 'getLayout',
            'tags' => ['System'],
            'summary' => 'System layout',
            'description' => 'The resolved `opensim.conf` layout, the configured SystemUser identity, and the current process identity. This operator endpoint is protected by the API key.',
            'responses' => [
                '200' => [
                    'description' => 'The resolved layout and identities',
                    'content' => [
                        Response::JSON_API => [
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'data' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'type' => ['const' => 'layout'],
                                            'id' => ['const' => 'system'],
                                            'attributes' => [
                                                'type' => 'object',
                                                'properties' => [
                                                    'systemUser' => ['type' => 'string'],
                                                    'systemUid' => ['type' => 'integer'],
                                                    'systemGroup' => ['type' => 'string'],
                                                    'systemGid' => ['type' => 'integer'],
                                                    'currentUser' => ['type' => 'string'],
                                                    'currentUid' => ['type' => 'integer'],
                                                    'currentGroup' => ['type' => 'string'],
                                                    'currentGid' => ['type' => 'integer'],
                                                    'etcRoot' => ['type' => 'string'],
                                                    'varRoot' => ['type' => 'string'],
                                                    'logsRoot' => ['type' => 'string'],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $paths['/setups/files']['post'] = [
            'operationId' => 'applySetupFile',
            'tags' => ['Setup'],
            'summary' => 'Setup file',
            'description' =>
                'A setup file (the format of the setup files: JSON or YAML) applied to a grid of this machine, `grid.nick` or the only one, without a question: the simulators it names are made with their regions, or, when the grid has them, given the regions it names (name, location, size, roles, port). What the file does not say is what the planners propose; two simulators or regions of the file never get one port or one place. `mode` `plan` (the default) writes nothing and says what would be written and where (`steps`, `writes`), `apply` writes it: the database of a new simulator is made when it is missing, nothing is started (`reminders`). A file with `problems` (by the place of the answer in the file, `simulators.0.regions.1.size`) is not applied (400). A file that makes a grid is the setup of a grid (400).',
            'requestBody' => [
                'required' => true,
                'content' => [
                    Response::JSON_API => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'type' => ['const' => 'files'],
                                        'attributes' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'text' => ['type' => 'string', 'description' => 'The setup file, as it is written'],
                                                'format' => ['type' => 'string', 'enum' => ['json', 'yaml'], 'description' => 'When its first character does not tell'],
                                                'setup' => ['type' => 'object', 'description' => 'The setup, as an object, instead of text'],
                                                'mode' => ['type' => 'string', 'enum' => ['plan', 'apply']],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'responses' => [
                '200' => [
                    'description' => 'The plan, or what was written: `grid`, `steps` (`kind` simulator or region, `simulator`, `slug`, `config`, `ports`, `database`, `estate` (name, owner: the estate its regions join, made when none has that name; an owner the simulator names gets `<Owner> Estate` unless `estate.name` is given), `region`: name, location, size, roles, port, file), `writes`, `problems`, `reminders` and `notes` (notices: `code`, `params`, the English `detail`; a site says them in its language with `notice_<code>` of the catalog, the params as {name}), `applied`',
                    'content' => [Response::JSON_API => ['schema' => ['type' => 'object']]],
                ],
                '400' => Contract::problem('A file that cannot be read, that makes a grid, that names no grid, or that has problems'),
                '404' => Contract::problem('The grid of the file is not known here'),
            ],
        ];
        $paths['/setups/imports']['post'] = [
            'operationId' => 'importSetup',
            'tags' => ['Setup'],
            'summary' => 'Import',
            'description' =>
                'What another install brings into this one. `kind` `simulator`: a simulator of an older install (its config, an OpenSim.ini or its file of opensim.d with its includes, and the regions it names) joins a grid of this machine: its database is kept as it is (the new config uses the same, nothing is copied), its regions are copied with their UUID and their place, its ports and the settings that differ from the defaults of the core are kept. `mode` `plan` (the default) writes nothing and says what would be written and where (`writes`), `apply` writes it, the simulator disabled: the old one is stopped by its operator (`reminders`), then the new one enabled and started. Refused (400) when the grid has the simulator, or a region of its name or UUID, already. `kind` `flux`: the posts of the stream of a site of w4os-wp (its `flux_post` posts, published, with their author `_avatar_uuid`) brought into the stream of the `grid`, each once; `file` the wp-config.php of the site, which says its database and the prefix of its tables. The plan says how many posts (`posts`), by avatar (`avatars`), the ones it skips and why (`skipped`), and how many were brought before (`imported`). `kind` `grid-settings`: the settings of a grid of another install (`file`, its OpenSim.ini, with the files it includes, as OpenSimulator reads them: its constants replaced, the last word of each key) brought into the OpenSim.ini of the `grid` of this machine, for the sections of its options (search and data services, economy and Gloebit, offline messages, profiles, groups): what the grid has already is never replaced, a folder of a simulator and the money of DTL/NSL are left out; the plan says the `settings` of its OpenSim.ini and the `files` laid (OpenSim.ini, and the Gloebit.ini of the grid, the one place of the settings of Gloebit, which the simulators include), a secret masked (a key, a secret, a password, a connection string), the constants kept as references (the ones this machine lacks, the site of the grid `WebURL`, defined in the `[Const]` of the grid), the `target` file and the `notes` (what is left out and why: the money of DTL/NSL is not handled; DATA_SRV_MISearch is DATA_SRV_<nick> now). One place for the settings of the simulators (`targets`): the OpenSim.ini of a grid that has a master (the master gives it to every simulator), else each simulator of the grid, whose config is whole (a grid that runs elsewhere, its simulators made without a master), the constants they lack defined in each; the repair of the options copies none of them again, and never changes a valid value.',
            'requestBody' => [
                'required' => true,
                'content' => [
                    Response::JSON_API => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'type' => ['const' => 'imports'],
                                        'attributes' => [
                                            'type' => 'object',
                                            'required' => ['grid'],
                                            'properties' => [
                                                'kind' => ['type' => 'string', 'enum' => ['simulator', 'flux', 'grid-settings']],
                                                'file' => ['type' => 'string', 'description' => 'The config of the simulator, on this machine (grid-settings: the OpenSim.ini of the grid of the other install)'],
                                                'simulators' => [
                                                    'type' => 'array',
                                                    'items' => ['type' => 'object', 'properties' => ['file' => ['type' => 'string'], 'name' => ['type' => 'string'], 'core' => ['type' => 'string']]],
                                                    'description' => 'Several simulators instead of one (`file`, `name`, `core` each), planned together and checked between them (slugs, ports, region names and UUIDs, places of the map), written in their order; the answer has their `steps`, `problems` by the place of the simulator (simulators.1), `writes`, `reminders`',
                                                ],
                                                'grid' => ['type' => 'string', 'description' => 'The nick of the grid of this machine it joins'],
                                                'name' => ['type' => 'string', 'description' => 'Its name, when it is not the one of its config (SimName of [Launch], else its file)'],
                                                'core' => ['type' => 'string', 'description' => 'The core it runs with, when it is not the one of the grid'],
                                                'scripts' => ['type' => 'boolean', 'description' => 'Copy the state of its scripts (its ScriptEngines folders) into the folder of the new simulator: true by default; false loses it, every script starts again'],
                                                'mode' => ['type' => 'string', 'enum' => ['plan', 'apply']],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'responses' => [
                '200' => [
                    'description' => 'The plan, or what was written: `simulator`, `slug`, `config`, `database` (kept), `ports`, `regions` (from, to, name, uuid, location), `backup` (the way back, written before anything else: the dump of each database its config names and its files as they are, `<grid>/<simulator>/<grid>-<simulator>-<stamp>-source.tar.gz` of the backup root; its `file`, `databases`, `files`, `size`; a database several simulators of one import name is dumped once, each backup holding a copy), `scripts` (the state of its scripts: `from`, `to`, `size`, `copied`), `settings` kept, `dropped` (the settings of the original not kept: section, key, value, `reason` same, replaced, default, layout, include or empty, `replacement` the value the new simulator has instead of a folder of the old install; only a secret masked), `writes`, `report`, `reminders` (notices: `code`, `params`, the English `detail`), `applied`',
                    'content' => [Response::JSON_API => ['schema' => ['type' => 'object']]],
                ],
                '400' => Contract::problem('A file that cannot be read or imported, a simulator or a region the grid has already'),
                '404' => Contract::problem('The grid is not known here'),
            ],
        ];
        $paths['/setups/restores']['post'] = [
            'operationId' => 'restoreBackup',
            'tags' => ['Setup'],
            'summary' => 'Restore a backup',
            'description' =>
                'A backup (`POST /archives`, `opensim backup`: a grid or one of its simulators) put back on this machine. Its configuration goes to the etc root of this install, the links that enable its instances pointing there, its data to the data folder of its grid; each database is made again from its dump, in the database its config names (made when it is missing, with the administrator of the session), before any file is written. `mode` `plan` (the default) writes nothing and says what would be written (`writes`, `kept`: the files of the grid a simulator shares, kept as they are here), `apply` writes it, by the user that runs the instances only (`instance-user-required`, 403). What this machine has already (the grid, the simulator, a database with tables) is never replaced (`restore-conflict`, 400).',
            'requestBody' => [
                'required' => true,
                'content' => [
                    Response::JSON_API => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'type' => ['const' => 'restores'],
                                        'attributes' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'file' => ['type' => 'string', 'description' => 'The backup, on this machine'],
                                                'archive' => ['type' => 'string', 'description' => 'Or its id in /archives'],
                                                'mode' => ['type' => 'string', 'enum' => ['plan', 'apply']],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'responses' => [
                '200' => [
                    'description' => 'The plan, or what was written: `grid`, `simulator`, `made`, `host`, `databases` (`kept`: a database of the grid a simulator shares, kept as it is when the grid is here), `writes`, `kept`, `reminders` (notices: `code`, `params`, the English `detail`), `applied`',
                    'content' => [Response::JSON_API => ['schema' => ['type' => 'object']]],
                ],
                '400' => Contract::problem('Not a backup, or what this machine has already (`restore-conflict`)'),
                '403' => Contract::problem('This process is not the user that runs the instances (`instance-user-required`)'),
                '404' => Contract::problem('There is no archive of this id'),
                '503' => Contract::problem('A file or a database cannot be had'),
            ],
        ];
        $paths['/setups/quick']['post'] = [
            'operationId' => 'quickSetup',
            'tags' => ['Setup'],
            'summary' => 'Quick setup',
            'description' =>
                'The setup that a few answers stand for. A grid has its Robust and a first simulator, `Welcome`, with its region; a standalone is one simulator that is the whole grid, with the name that was given. Everything the answers do not say is what the planners propose (ports, database, folders, options), so an empty request gives the proposals. `setup` is in the format of the setup files (`opensim import`), the password of the avatar is kept hashed in it. `problems` names the questions that are wrong or missing, `rows` lists every value the setup defines.',
            'requestBody' => [
                'required' => false,
                'content' => [
                    Response::JSON_API => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'type' => ['const' => 'setups'],
                                        'attributes' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'type' => ['type' => 'string', 'description' => 'grid, standalone, simulator, or an implemented grid preset'],
                                                'name' => ['type' => 'string', 'description' => 'Of the grid, and of the simulator and its region for a standalone'],
                                                'private_port' => ['type' => ['integer', 'string'], 'description' => 'Private grid TCP port; empty keeps the planner proposal. For a simulator joining a grid, this is the remote grid service port.'],
                                                'grid_console_port' => ['type' => ['integer', 'string'], 'description' => 'Grid REST console TCP port; empty keeps the planner proposal.'],
                                                'http_port' => ['type' => ['integer', 'string'], 'description' => 'Simulator HTTP TCP port; its ten-port block also supplies default console and region ports.'],
                                                'console_port' => ['type' => ['integer', 'string'], 'description' => 'Simulator REST console TCP port; empty keeps the planner proposal.'],
                                                'region_port' => ['type' => ['integer', 'string'], 'description' => 'New region UDP port; empty keeps the planner proposal. An adopted region retains its original port.'],
                                                'existing_region' => ['type' => 'string', 'description' => 'Original Region.ini contents for a simulator joining a grid. Preserves the region UUID and settings; application stages a disabled simulator without creating accounts, a database or a placeholder region. Restore data and stop the source before enabling it.'],
                                                'login_uri' => ['type' => 'string', 'description' => 'http://login.example.org:8002'],
                                                'ssl' => ['type' => 'boolean', 'default' => false, 'description' => 'Not implemented yet'],
                                                'hypergrid' => ['type' => 'boolean', 'default' => true],
                                                'search' => ['type' => 'boolean', 'default' => false, 'description' => 'Cross-grid search: the shared provider (2do.directory) instead of the search of the grid. Not implemented yet'],
                                                'gloebit' => ['type' => 'boolean', 'default' => false, 'description' => 'Gloebit as the currency instead of BetaGridLikeMoneyModule. Not implemented yet'],
                                                'ossl' => ['type' => 'boolean', 'default' => false, 'description' => 'More OSSL functions for the parcel owners. Not implemented yet'],
                                                'avatar' => ['type' => 'string', 'description' => 'First Last: the avatar that owns the estate, made with the grid'],
                                                'password' => ['type' => 'string'],
                                                'email' => ['type' => 'string'],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'responses' => [
                '200' => [
                    'description' => 'The setup, and what is wrong with the answers',
                    'content' => $document('setups', [
                        'valid' => ['type' => 'boolean', 'description' => 'There is nothing wrong: the setup can be applied'],
                        'capabilities' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['prepare', 'apply_prepared', 'existing_region']], 'description' => 'Supported setup operations. A planning request has no provisioning side effects, so clients can check support before applying a configuration.'],
                        'problems' => [
                            'type' => 'object',
                            'description' => 'What is wrong, by the name of the question',
                            'additionalProperties' => ['type' => 'string'],
                        ],
                        'corrected' => [
                            'type' => 'object',
                            'description' => 'The answers the setup rewrote (a name with accents, transliterated), by question: show them in the form, the person validates the change by sending it again',
                            'additionalProperties' => ['type' => 'string'],
                        ],
                        'identity' => ['type' => 'string', 'description' => 'The grid nick, or the complete simulator instance name for a standalone or remote simulator'],
                        'setup' => ['type' => 'object', 'description' => 'The grid, its simulator, its owner'],
                        'rows' => [
                            'type' => 'array',
                            'description' => 'Every value the setup defines, to show before it is applied: the section (grid, options, simulator, region, owner), the label and the value',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'section' => ['type' => 'string'],
                                    'label' => ['type' => 'string'],
                                    'value' => ['type' => 'string'],
                                ],
                            ],
                        ],
                        'types' => [
                            'type' => 'array',
                            'description' => 'What can be set up, to offer as a choice: the name to send as `type`, the label, and whether the setup applies it yet',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'name' => ['type' => 'string'],
                                    'label' => ['type' => 'string'],
                                    'implemented' => ['type' => 'boolean'],
                                ],
                            ],
                        ],
                        'options' => [
                            'type' => 'array',
                            'description' => 'The options of the form, in the order to show them: the name to send as an answer, the label, the default, the value taken, and whether the setup applies it yet. The helpers and what they serve are on in a quick setup',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'name' => ['type' => 'string'],
                                    'label' => ['type' => 'string'],
                                    'default' => ['type' => 'boolean'],
                                    'value' => ['type' => 'boolean'],
                                    'implemented' => ['type' => 'boolean'],
                                ],
                            ],
                        ],
                    ]),
                ],
                '400' => Contract::problem('The body is not a JSON document'),
            ],
        ];

        $paths['/setups/steps']['post'] = [
            'operationId' => 'setupStep',
            'tags' => ['Setup'],
            'summary' => 'Quick setup, one step',
            'description' =>
                'The scenario of the quick setup, for a client that only draws screens. It sends back the screen it was given, filled (`answers`), the button it pressed and the `state` it got, and receives the next screen, the same one with what is wrong (`notice`), or an action: `quit` with a message, `abort` with an error. With no `state` it receives the first screen. A `screen` has a `kind`: `menu` (`items` with `key`, `label`, `note`; the answer is `{name: key}`), `form` (`fields` with `name`, `label`, `value`, `secret`; the answer is a value for each), `checks` (`items` with `name`, `label`, `checked`, `note`; the answer is true or false for each) or `review` (`rows`, to confirm). The last confirmation makes the folders and writes the files of the grid and of its first simulator. `occupied_ports`, when supplied, is the fresh TCP/UDP port snapshot of the deployment host; it replaces the local machine port scan.',
            'requestBody' => [
                'required' => false,
                'content' => [
                    Response::JSON_API => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'type' => ['const' => 'steps'],
                                        'attributes' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'state' => ['type' => 'string', 'description' => 'As the last reply gave it; empty for the first screen'],
                                                'button' => ['type' => 'string', 'enum' => ['next', 'back', 'quit'], 'default' => 'next'],
                                                'answers' => ['type' => 'object', 'description' => 'The screen, filled: a value for each of its fields, items or choice. For adoption, include existing_region INI contents with the type selection.'],
                                                'mode' => ['type' => 'string', 'enum' => ['apply', 'prepare'], 'default' => 'apply', 'description' => 'Prepare collects answers, including database settings, without provisioning. Send the mode on each step.'],
                                                'occupied_ports' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 65535], 'description' => 'Ports occupied on the selected deployment host; when present, local socket and config discovery is not used.'],
                                                'configuration' => ['type' => 'object', 'description' => 'A versioned configuration returned by prepare. With mode apply, validates and provisions it directly without asking further questions. Secrets belong in the request body.'],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'responses' => [
                '200' => [
                    'description' => 'The next screen, or the action to do',
                    'content' => $document('steps', [
                        'action' => ['type' => 'string', 'enum' => ['screen', 'quit', 'abort', 'prepared']],
                        'configuration' => ['type' => 'object', 'description' => 'Of prepared: instance identity, answers, setup preview and engine-generated TCP/UDP port bindings. Contains credentials; save privately and do not log it.'],
                        'mode' => ['type' => 'string', 'enum' => ['prepare'], 'description' => 'Returned on every prepare reply, confirming that no provisioning will run.'],
                        'kind' => ['type' => 'string', 'enum' => ['menu', 'form', 'checks', 'review'], 'description' => 'Of a screen'],
                        'name' => ['type' => 'string', 'description' => 'Of a menu: the name of its answer'],
                        'title' => ['type' => 'string'],
                        'text' => ['type' => 'string', 'description' => 'Above the screen'],
                        'notice' => ['type' => 'string', 'description' => 'What is wrong with the last answers, one line each: show it above the screen'],
                        'items' => ['type' => 'array', 'description' => 'Of a menu or of check boxes', 'items' => ['type' => 'object']],
                        'fields' => ['type' => 'array', 'description' => 'Of a form: the name, the label, the value, secret (typed hidden) and readonly (only shown, not sent back)', 'items' => ['type' => 'object']],
                        'rows' => ['type' => 'array', 'description' => 'Of a review: the section, the label and the value', 'items' => ['type' => 'object']],
                        'value' => ['type' => 'string', 'description' => 'Of a menu: the key chosen so far'],
                        'buttons' => ['type' => 'object', 'description' => 'The labels of next and back'],
                        'wait' => ['type' => 'string', 'description' => 'What to say while the next step is done, when it takes time'],
                        'state' => ['type' => 'string', 'description' => 'To send back with the answers'],
                        'message' => ['type' => 'string', 'description' => 'Of quit: to tell, the setup is over'],
                        'error' => ['type' => 'string', 'description' => 'Of abort: to tell, the setup is over'],
                    ]),
                ],
                '400' => Contract::problem('The body is not a JSON document'),
            ],
        ];

        $paths['/grids/{grid}/sign-ins']['post'] = self::action(
            'sign-ins',
            'signIn',
            'Sign an avatar in',
            'Checks the name (`First Last`, `First.Last`) or the email of an avatar of the grid, and its password, against what Robust keeps; no session is opened in the world. The avatar when they are right. A wrong name and a wrong password are refused alike (`sign-in-refused`); an account that is not active is refused once its password is right, and the code says why: `email-unconfirmed` while it waits for the confirmation of its email, else `account-disabled`. An email several avatars share signs in the oldest of them whose password it is.',
            [
                'name' => ['string', 'First Last, or First.Last'],
                'email' => ['string', 'The email of the avatar, instead of its name'],
                'password' => ['string', ''],
            ],
            [
                '200' => [
                    'description' => 'The avatar',
                    'content' => [
                        Response::JSON_API => [
                            'schema' => [
                                'type' => 'object',
                                'properties' => ['data' => ['$ref' => '#/components/schemas/Avatars']],
                            ],
                        ],
                    ],
                ],
                '400' => Contract::problem('No name or email, or no password'),
                '403' => Contract::problem(
                    'The name or the password is wrong (`sign-in-refused`), or the account is not active (`email-unconfirmed`, `account-disabled`)',
                ),
                '404' => Contract::problem('The grid is not known here'),
                '503' => Contract::problem('The database of the accounts cannot be reached'),
            ],
        );

        $avatar = [
            'description' => 'The avatar',
            'content' => [
                Response::JSON_API => [
                    'schema' => [
                        'type' => 'object',
                        'properties' => ['data' => ['$ref' => '#/components/schemas/Avatars']],
                    ],
                ],
            ],
        ];
        $tokens = static fn(string $type): array => [
            'description' => 'The token, not mailed (`send: false`)',
            'content' => [
                Response::JSON_API => [
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'data' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'type' => ['const' => $type],
                                        'id' => ['type' => 'string', 'description' => 'The UUID of the avatar'],
                                        'attributes' => [
                                            'type' => 'object',
                                            'properties' => [
                'avatar' => ['type' => 'string', 'description' => 'The UUID of the avatar'],
                'name' => ['type' => 'string'],
                'email' => ['type' => ['string', 'null']],
                'token' => ['type' => 'string', 'description' => 'The token, 32 hexadecimal characters'],
                'expires' => ['type' => 'integer', 'description' => 'When it expires (Unix time)'],
                'link' => ['type' => ['string', 'null'], 'description' => 'The link with the token'],
                'sent' => ['type' => 'boolean', 'description' => 'It was mailed'],
            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $accepted = static fn(string $description): array => [
            'description' => $description,
            'content' => [
                Response::JSON_API => [
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'meta' => ['type' => 'object', 'properties' => ['message' => ['type' => 'string']]],
                        ],
                    ],
                ],
            ],
        ];
        $paths['/grids/{grid}/password-resets']['post'] = self::action(
            'password-resets',
            'requestPasswordReset',
            'Forgotten password',
            'Gives a token to choose a new password to the avatar a name is, or to every avatar an email is: mailed to it with a link, or given back with `send: false`. The answer to a mail is the same whether the name or the email is someone\'s or not. An avatar without an email (the models, the technical accounts, by convention) has no link: mailed, it is left out with the same answer (it does not tell such an account exists); with `send: false`, it is refused (`email-required`).',
            [
                'name' => ['string', 'First Last, or First.Last'],
                'email' => ['string', 'The email of the avatar, instead of its name: every avatar that has it'],
                'link' => ['string', 'The page that takes the token, `{token}` in it: https://example.org/reset?key={token}'],
                'send' => ['boolean', 'Mail it (the default); false gives the token and mails nothing'],
            ],
            [
                '200' => $tokens('password-resets'),
                '202' => $accepted('Mailed, when the name or the email is someone\'s'),
                '400' => Contract::problem('No name or email, a link that is not a web address, or with `send: false` an avatar without an email (`email-required`)'),
                '404' => Contract::problem('The grid is not known here; with `send: false`, nobody has the name or the email'),
                '503' => Contract::problem('The databases of the accounts or of the passwords cannot be reached'),
            ],
        );
        $paths['/grids/{grid}/password-resets/complete']['post'] = self::action(
            'password-resets',
            'resetPassword',
            'New password',
            'Changes the password of the avatar a token of a forgotten password was given to. The token is valid once; a password that is not one is refused before the token is taken.',
            [
                'token' => ['string', 'The token of the mail'],
                'password' => ['string', 'The new password: 6 to 16 characters, no double quote'],
            ],
            [
                '200' => $avatar,
                '400' => Contract::problem('The password is not one'),
                '403' => Contract::problem('The token is not valid, or it has expired'),
                '404' => Contract::problem('The grid is not known here'),
                '503' => Contract::problem('The databases of the accounts or of the passwords cannot be reached'),
            ],
        );
        $paths['/grids/{grid}/email-confirmations']['post'] = self::action(
            'email-confirmations',
            'requestEmailConfirmation',
            'Confirm an email again',
            'Gives a new token to confirm its email to a registration that waits for it (a new avatar asked with `confirmEmail`, not made yet; every registration of the name when several asked for it), or to an avatar made waiting for it before the registrations: mailed with a link, or given back with `send: false`. The token it had is refused from then on, and the registration waits two more days. A registration is forgotten once it expired: the person registers again. An avatar that does not wait for it, an administrator disabled it for instance, is never given one: it would activate it.',
            [
                'avatar' => ['string', 'The name of the avatar (or the UUID it has, or will have)'],
                'link' => ['string', 'The page that takes the token, `{token}` in it'],
                'send' => ['boolean', 'Mail it (the default); false gives the token and mails nothing'],
            ],
            [
                '200' => $tokens('email-confirmations'),
                '202' => $accepted('Mailed'),
                '400' => Contract::problem('The avatar has no email, or the link is not a web address'),
                '404' => Contract::problem('The grid is not known here, or no avatar nor registration has this name'),
                '409' => Contract::problem('The avatar does not wait for the confirmation of its email'),
                '503' => Contract::problem('A database cannot be reached, or the mail cannot be sent'),
            ],
        );
        $paths['/grids/{grid}/email-confirmations/complete']['post'] = self::action(
            'email-confirmations',
            'confirmEmail',
            'Confirm an email',
            'Confirms the email a token was given for, once. The token of a registration makes the avatar, active (its account, its password, its inventory, in the outfit of its model, or the default one when the model is gone): it can log in. Its name may have been taken meanwhile, no name is kept for a registration: then nothing is made, the registration is forgotten (`name-taken`), and the person registers again. The token of an avatar made waiting before activates it; it is valid only for the email the avatar has. The token of a new email (`confirmEmail` of a change of the avatar) changes its email, the avatar staying as active as it is.',
            ['token' => ['string', 'The token of the mail']],
            [
                '200' => $avatar,
                '400' => Contract::problem('The name of the registration was taken meanwhile (`name-taken`)'),
                '403' => Contract::problem('The token is not valid, or it has expired'),
                '404' => Contract::problem('The grid is not known here'),
                '503' => Contract::problem('The databases of the accounts or of the passwords cannot be reached'),
            ],
        );

        $groups = [
            'Grid' => [
                'Addresses',
                'Message of the day',
                'Avatar access',
                'Search',
                'Economy',
                'Offline messages',
                'Health',
                'Monitor',
            ],
            'Application' => ['Text images', 'Logos', 'Setup'],
            'Helpers pages' => ['Pages'],
            'Robust' => ['Grid info'],
        ];

        return ['paths' => $paths, 'tags' => $tags, 'groups' => $groups];
    }

    /**
     * What `/grids/{grid}/destinations` takes and gives besides the resources: the lines the boards of the scripts read.
     *
     * @param array<string,mixed> $operation
     * @return array<string,mixed>
     */
    public static function destinations(array $operation): array
    {
        $operation['description'] =
            'The list of the guide. `GuideSource` of `[Helpers]` (a file or an address of "Name|Region URL" lines, a name alone starting a category) is the list, the guide of 2do.directory (`https://2do.directory/destinations/guide`) for a grid that gives none; when the list cannot be read, the places its search knows, ten popular places and ten places shown in search, in two sections: the places of this grid only, no land for sale among the popular places, nothing that is not shown in search. With `format=list` (or `Accept: text/plain`) it is the lines themselves, as the boards of the scripts read them: the place in the region in its own column (`x,y,z`), their layout hints kept (`-` a new column, `|` an empty row). A source is read when it is text, 1 MB at most, in UTF-8 (the Gudz Teleport Board reads 2048 bytes of it). The lines are read as the board reads them: the old format of 5 columns gives the name in the 2nd, the address in the 4th and the landing point in the 5th; an address listed already is left out, `http://` taken off.';
        $operation['parameters'][] = [
            'name' => 'format',
            'in' => 'query',
            'required' => false,
            'description' => '`list` gives the lines the boards of the scripts read, in plain text',
            'schema' => ['type' => 'string', 'enum' => ['list']],
        ];
        $operation['responses']['200']['content']['text/plain'] = [
            'schema' => ['type' => 'string'],
            'examples' => [
                'list' => [
                    'value' =>
                        "Popular places\nRose garden|grid.example.org:8002/Welcome/128/128/25\nPlaces in the search\nLibrary|grid.example.org:8002/Welcome/64/64/22\n",
                ],
            ],
        ];

        return $operation;
    }

    /**
     * An XML-RPC endpoint, its methods described.
     *
     * @param array<string,array{summary:string,request:array<string,array{0:string,1:string,2:string}>,answer:array<string,array{0:string,1:string,2:string}>}> $methods
     * @param list<array<string,mixed>> $parameters
     * @return array<string,mixed>
     */
    private static function xmlRpc(
        string $id,
        string $tag,
        string $summary,
        string $description,
        array $methods,
        array $parameters,
        bool $rows,
    ): array {
        $text = "$description\n";
        $requests = [];
        $answers = [];
        foreach ($methods as $name => $method) {
            $text .=
                "\n### `$name`\n\n{$method['summary']}.\n\n**Request** (the struct)\n\n" .
                self::table($method['request']);
            $text .=
                $method['answer'] === []
                    ? "\n\n**Answer**: `success`, with the reason in `errorMessage` when it failed.\n"
                    : "\n\n**Answer**" .
                        ($rows ? ' (each row of `data`)' : '') .
                        "\n\n" .
                        self::table($method['answer']) .
                        "\n";
            $requests[$name] = ['summary' => $name, 'value' => Formats::requestExample($name, $method['request'])];
            $answers[$name] = ['summary' => $name, 'value' => Formats::answerExample($method['answer'], $rows)];
        }

        return [
            'operationId' => $id,
            'tags' => [$tag],
            'summary' => $summary,
            'description' => $text,
            'parameters' => $parameters,
            'requestBody' => [
                'required' => true,
                'content' => [
                    'text/xml' => [
                        'schema' => ['type' => 'string', 'description' => 'XML-RPC method call'],
                        'examples' => $requests,
                    ],
                ],
            ],
            'responses' => [
                '200' => [
                    'description' => 'The answer of the method',
                    'content' => ['text/xml' => ['schema' => ['type' => 'string'], 'examples' => $answers]],
                ],
                '400' => self::xmlProblem('The body is not an XML-RPC request'),
                '403' => self::xmlProblem(
                    'The caller does not hold the secure session of the avatar (avatar_publish_update)',
                ),
                '404' => self::xmlProblem('The grid is not known here'),
                '503' => self::xmlProblem('The grid has no database for it, or no key for its currency'),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private static function gatekeeper(): array
    {
        return [
            'name' => 'gk',
            'in' => 'query',
            'required' => false,
            'description' =>
                'The gatekeeper of the grid the search is made for (`grid.example.org:8002`), when the search is shared: only its places are answered',
            'schema' => ['type' => 'string'],
        ];
    }

    /**
     * An operation of a grid that is not a resource but takes a JSON:API document: `{data: {type, attributes}}`.
     *
     * @param array<string,array{0:string,1:string}> $fields the attributes it takes: type and description
     * @param array<string,mixed> $responses
     * @return array<string,mixed>
     */
    private static function action(
        string $type,
        string $operationId,
        string $summary,
        string $description,
        array $fields,
        array $responses,
    ): array {
        $properties = [];
        foreach ($fields as $name => [$kind, $text]) {
            $properties[$name] = ['type' => $kind] + ($text !== '' ? ['description' => $text] : []);
        }

        return [
            'operationId' => $operationId,
            'tags' => ['Avatar access'],
            'summary' => $summary,
            'description' => $description,
            'parameters' => [Contract::gridParameter()],
            'requestBody' => [
                'required' => true,
                'content' => [
                    Response::JSON_API => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'type' => ['const' => $type],
                                        'attributes' => ['type' => 'object', 'properties' => $properties],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'responses' => $responses,
        ];
    }

    /**
     * @param array<string,array{0:string,1:string,2:string}> $fields
     * @return list<array<string,mixed>>
     */
    private static function query(array $fields): array
    {
        $parameters = [];
        foreach ($fields as $name => [$type, $description, $example]) {
            $parameters[] = [
                'name' => $name,
                'in' => 'query',
                'required' => true,
                'description' => $description,
                'schema' =>
                    ['type' => $type] +
                    ($example !== '' ? ['example' => $type === 'integer' ? (int) $example : $example] : []),
            ];
        }

        return $parameters;
    }

    /**
     * @param array<string,array{0:string,1:string,2:string}> $fields
     */
    private static function table(array $fields): string
    {
        $rows = ['| Field | Type | |', '| --- | --- | --- |'];
        foreach ($fields as $name => [$type, $description]) {
            $rows[] = "| `$name` | $type | " . str_replace('|', '\\|', $description) . ' |';
        }

        return implode("\n", $rows);
    }

    /** @return array<string,mixed> */
    private static function text(string $description): array
    {
        return ['description' => $description, 'content' => ['text/plain' => ['schema' => ['type' => 'string']]]];
    }

    /** @return array<string,mixed> */
    private static function xmlProblem(string $description): array
    {
        return ['description' => $description, 'content' => ['text/xml' => ['schema' => ['type' => 'string']]]];
    }
}
