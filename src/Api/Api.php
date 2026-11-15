<?php

declare(strict_types=1);

namespace OpenSim\Api;

use OpenSim\Result;
use OpenSim\Config\GridConfig;
use OpenSim\Exceptions\ChannelUnavailable;
use OpenSim\Exceptions\InvalidValue;
use OpenSim\Exceptions\NotFound;
use OpenSim\Interfaces\ModelInterface;
use OpenSim\Assets\ConverterInterface;
use OpenSim\Assets\ImagickTextRenderer;
use OpenSim\Assets\TextRendererInterface;
use OpenSim\Assets\WebAssets;
use OpenSim\Models\Archive;
use OpenSim\Models\Asset;
use OpenSim\Models\Avatar;
use OpenSim\Models\AvatarModel;
use OpenSim\Models\Estate;
use OpenSim\Models\Grid;
use OpenSim\Provisioning\QuickSetup;
use OpenSim\Models\Parcel;
use OpenSim\Models\Region;
use OpenSim\Models\Service;
use OpenSim\Models\Simulator;
use OpenSim\Runtime\Environment;
use OpenSim\Data\Connections;
use OpenSim\Data\Database;
use OpenSim\Provisioning\Slug;
use OpenSim\Economy\Currency;
use OpenSim\Health\Health;
use OpenSim\Runtime\GridMonitor;
use OpenSim\Economy\CurrencySettings;
use OpenSim\Services\CurrencyServer;
use OpenSim\Services\Failure;
use OpenSim\Text\DestinationList;
use OpenSim\Services\MailerInterface;
use OpenSim\Services\OfflineMessages;
use OpenSim\Services\PhpMailer;
use OpenSim\Services\SearchParser;
use OpenSim\Services\SearchQuery;
use OpenSim\Services\SearchRegistry;
use OpenSim\Services\SearchTables;
use OpenSim\Services\XmlRpc;
use OpenSim\Scheduling\Scheduler;
use OpenSim\SignIn\AvatarAccess;
use OpenSim\SignIn\DatabaseAuthenticator;
use OpenSim\SignIn\SignInResult;

/**
 * What the machine offers as resources, listed, read and changed the way JSON:API says, the errors told as problems (RFC 9457). The
 * use cases are the models': this layer only names the resources, reads the query and writes the documents, so that the command
 * line, the HTTP API and the PHP library show the same.
 *
 *     $api = new Api($environment, $scheduler);
 *     $api->list('services', ['filter' => ['served' => 'yes'], 'sort' => '-name', 'page' => ['number' => 2, 'size' => 20]]);
 *     $api->get('grids', 'vonda');
 *     $api->list('avatars', ['search' => 'ada'], grid: 'vonda');                      // the resources of a grid are asked with it
 *     $api->create('avatars', ['name' => 'Ada Love', 'password' => 'secret'], grid: 'vonda');
 *     $api->update('simulators', 'vonda.welcome', ['enabled' => true]);
 *     $api->run('search-parse');
 *
 * The contract of these resources is Contract::document(), which the tests check against the models.
 */
final class Api
{
    /** The resources of the machine, by the name they have in an address. */
    public const RESOURCES = [
        'grids' => Grid::class,
        'simulators' => Simulator::class,
        'services' => Service::class,
        'archives' => Archive::class,
    ];

    /** The resources of a grid: read in its databases and its files, at `/grids/{grid}/{type}`. */
    public const GRID_RESOURCES = [
        'avatars' => Avatar::class,
        'avatar-models' => AvatarModel::class,
        'regions' => Region::class,
        'parcels' => Parcel::class,
        'estates' => Estate::class,
        'assets' => Asset::class,
    ];

    /** Seconds a simulator that registers is given to load its regions before its snapshot is read. */
    public const REGISTER_DELAY = 30;

    /** The forms the engine knows (what a setup asks), by name. */
    public const FORMS = ['grid', 'remote-grid', 'simulator', 'region'];

    /** @var \Closure(Grid):?GridConfig the config of a grid, from its folder unless the caller says otherwise */
    private \Closure $config;

    /** @var list<\Closure():void> what is done once the answer is sent (see finish()) */
    private array $after = [];

    /** @var array<string,true> the search databases whose tables are checked */
    private static array $prepared = [];

    /**
     * @param ?Scheduler $scheduler the tasks of the application, none when it has none
     * @param ?\Closure(Grid):?GridConfig $config the config of a grid (default: its folder)
     * @param ?TextRendererInterface $texts draws the images of a text (default: ImageMagick)
     * @param ?MailerInterface $mailer sends the mails of the grid (default: the mail function of PHP)
     */
    public function __construct(
        private readonly Environment $environment,
        private readonly ?Scheduler $scheduler = null,
        ?\Closure $config = null,
        private readonly ?TextRendererInterface $texts = null,
        private readonly ?MailerInterface $mailer = null,
    ) {
        $this->config =
            $config ??
            static fn(Grid $grid): ?GridConfig => $grid->directory() !== '' && is_dir($grid->directory())
                ? GridConfig::fromFolder($grid->directory(), ['Helpers' => 'Helpers.ini'])
                : null;
    }

    /**
     * A page of the resources of a type.
     *
     * @param array<string,mixed> $query `search`, `sort` ("-name" is name descending), `filter[<name>]`, `page[number]`, `page[size]`
     * @param ?string $grid the nick of the grid, for the resources of a grid
     */
    public function list(string $type, array $query = [], ?string $grid = null): Response
    {
        $instance = self::path($type, null, $grid);
        try {
            if ($type === 'tasks') {
                return $this->tasks($instance);
            }
            if ($type === 'forms') {
                return Response::document([
                    'data' => array_map(fn(string $name): array => $this->form($name), self::FORMS),
                    'links' => ['self' => $instance],
                ]);
            }
            if ($type === 'fonts') {
                $fonts = array_map(
                    static fn(string $name): array => self::fontResource($name),
                    $this->renderer()->fonts(),
                );

                return Response::document([
                    'data' => $fonts,
                    'meta' => ['total' => count($fonts)],
                    'links' => ['self' => $instance],
                ]);
            }
            if ($type === 'destinations' && $grid !== null && ($query['format'] ?? '') === 'list') {
                $found = Grid::find($this->environment, $grid) ?? throw NotFound::for("grid called '$grid'");

                return Response::binary(
                    DestinationList::format($found->destinations(($this->config)($found))),
                    'text/plain; charset=utf-8',
                );
            }
            if ($type === 'destinations' && $grid !== null) {
                $destinations = $this->destinations($grid);

                return Response::document([
                    'data' => array_values($destinations),
                    'meta' => [
                        'total' => count($destinations),
                        'categories' => array_values(
                            array_unique(array_column(array_column($destinations, 'attributes'), 'category')),
                        ),
                    ],
                    'links' => ['self' => $instance],
                ]);
            }
            $class = self::classOf($type, $grid);
            if ($class === null) {
                return Response::problem(
                    Problem::notFound(
                        "kind of resource called '$type'" . ($grid !== null ? ' in a grid' : ''),
                        $instance,
                    ),
                );
            }
            $options = self::options($query);
            $collection = isset(self::RESOURCES[$type])
                ? $class::all($this->environment, $options)
                : $class::all($this->gridConfig((string) $grid), $options);
        } catch (\Throwable $error) {
            return Response::problem(Problem::from($error, $instance));
        }

        return Response::document([
            'data' => array_map(
                fn(ModelInterface $model): array => self::resource($type, $model, $grid),
                $collection->items(),
            ),
            'meta' => [
                'total' => $collection->total(),
                'page' => $collection->page(),
                'perPage' => $collection->perPage(),
                'sort' => $collection->sort(),
                'search' => $collection->search(),
                'filters' => $collection->applied(),
            ],
            'links' => ['self' => $instance],
        ]);
    }

    /** One resource by its id. */
    public function get(string $type, string $id, ?string $grid = null): Response
    {
        $instance = self::path($type, $id, $grid);
        try {
            if ($type === 'tasks') {
                return $this->task($id, $instance);
            }
            if ($type === 'fonts') {
                return in_array($id, $this->renderer()->fonts(), true)
                    ? Response::document(['data' => self::fontResource($id), 'links' => ['self' => $instance]])
                    : Response::problem(Problem::notFound("font called '$id'", $instance));
            }
            if ($type === 'destinations' && $grid !== null) {
                $found = $this->destinations($grid)[$id] ?? null;

                return $found !== null
                    ? Response::document(['data' => $found, 'links' => ['self' => $instance]])
                    : Response::problem(Problem::notFound("destination called '$id'", $instance));
            }
            if ($type === 'forms') {
                return in_array($id, self::FORMS, true)
                    ? Response::document(['data' => $this->form($id), 'links' => ['self' => $instance]])
                    : Response::problem(Problem::notFound("form called '$id'", $instance));
            }
            $model = $this->find($type, $id, $grid, $instance);
        } catch (\Throwable $error) {
            return Response::problem(Problem::from($error, $instance));
        }

        return $model instanceof Response
            ? $model
            : Response::document(['data' => self::resource($type, $model, $grid), 'links' => ['self' => $instance]]);
    }

    /**
     * Make a resource (an avatar; the others are made by the setup and answer that no channel does it).
     *
     * @param array<string,mixed> $attributes the values of the new resource, by the names of its attributes
     */
    public function create(string $type, array $attributes, ?string $grid = null): Response
    {
        $instance = self::path($type, null, $grid);
        try {
            $class = self::classOf($type, $grid);
            if ($class === null) {
                return Response::problem(Problem::notFound("kind of resource called '$type'", $instance));
            }
            $confirm = $type === 'avatars' && Result::isTrue($attributes['confirmEmail'] ?? false);
            if ($confirm) {
                if (!Avatar::validEmail(trim((string) ($attributes['email'] ?? '')))) {
                    throw InvalidValue::because(
                        'email-required',
                        'an avatar whose email is to be confirmed needs an email',
                    );
                }
                $attributes['active'] = false;
            }
            $created = isset(self::RESOURCES[$type])
                ? $class::create($this->environment, $attributes)
                : $class::create($this->gridConfig((string) $grid), $attributes);
            $meta = [];
            if ($confirm && $created instanceof Avatar) {
                $send = !Result::isFalse($attributes['send'] ?? true);
                $meta = self::confirmation(
                    $this->access((string) $grid)->requestEmailConfirmation($created, self::link($attributes), $send),
                    $send,
                );
            }
        } catch (\Throwable $error) {
            return Response::problem(Problem::from($error, $instance));
        }
        $self = self::path($type, (string) $created->id(), $grid);

        return new Response(
            201,
            ['Content-Type' => Response::JSON_API, 'Location' => $self],
            ['data' => self::resource($type, $created, $grid), 'links' => ['self' => $self]] +
                ($meta !== [] ? ['meta' => $meta] : []),
        );
    }

    /**
     * Change a resource. `enabled` enables or disables it, the other attributes are given to its `update()`.
     *
     * @param array<string,mixed> $attributes
     */
    public function update(string $type, string $id, array $attributes, ?string $grid = null): Response
    {
        $instance = self::path($type, $id, $grid);
        try {
            $model = $this->find($type, $id, $grid, $instance);
            if ($model instanceof Response) {
                return $model;
            }
            // A new email of an avatar waits for its confirmation: it is checked before anything changes, and changed by its token
            $email = null;
            $options = [];
            if ($model instanceof Avatar) {
                $options = array_intersect_key($attributes, ['confirmEmail' => 1, 'link' => 1, 'send' => 1]);
                $attributes = array_diff_key($attributes, $options);
                if (Result::isTrue($options['confirmEmail'] ?? false) && array_key_exists('email', $attributes)) {
                    $asked = trim((string) $attributes['email']);
                    unset($attributes['email']);
                    if (!Avatar::validEmail($asked)) {
                        throw InvalidValue::because('email-invalid', "'$asked' is not an email");
                    }
                    if (strtolower($asked) !== strtolower((string) ($model->detail()['email'] ?? ''))) {
                        $email = $asked;
                    }
                }
            }
            if (array_key_exists('enabled', $attributes)) {
                Result::isTrue($attributes['enabled']) ? $model->enable() : $model->disable();
                unset($attributes['enabled']);
            }
            $attributes !== [] && $model->update($attributes);
            $meta = [];
            if ($email !== null) {
                $send = !Result::isFalse($options['send'] ?? true);
                $meta = self::confirmation(
                    $this->access((string) $grid)->requestEmailChange($model, $email, self::link($options), $send),
                    $send,
                );
            }
            $model = $this->find($type, $id, $grid, $instance);
        } catch (\Throwable $error) {
            return Response::problem(Problem::from($error, $instance));
        }

        return $model instanceof Response
            ? $model
            : Response::document(
                ['data' => self::resource($type, $model, $grid), 'links' => ['self' => $instance]] +
                    ($meta !== [] ? ['meta' => $meta] : []),
            );
    }

    /** Delete a resource, when a channel can. */
    public function delete(string $type, string $id, ?string $grid = null): Response
    {
        $instance = self::path($type, $id, $grid);
        try {
            $model = $this->find($type, $id, $grid, $instance);
            if ($model instanceof Response) {
                return $model;
            }
            $model->delete();
        } catch (\Throwable $error) {
            return Response::problem(Problem::from($error, $instance));
        }

        return new Response(204, [], []);
    }

    /**
     * Sign an avatar of a grid in: its name (or its email) and its password, checked in the databases of the grid. The avatar when
     * they are right, a problem 403 when they are not (the same for an unknown avatar and a wrong password), or when the account is
     * not active.
     *
     * @param array<string,mixed> $attributes `name` ("First Last") or `email`, and `password`
     */
    public function signIn(string $grid, array $attributes): Response
    {
        $instance = '/grids/' . rawurlencode($grid) . '/sign-ins';
        try {
            $config = $this->gridConfig($grid);
            $login = trim((string) ($attributes['name'] ?? ($attributes['email'] ?? '')));
            $password = (string) ($attributes['password'] ?? '');
            if ($login === '' || $password === '') {
                throw new \InvalidArgumentException('a sign-in needs a name or an email, and a password');
            }
            $result = (new DatabaseAuthenticator($config))->authenticate($login, $password);
            if ($result->status === SignInResult::UNAVAILABLE) {
                throw ChannelUnavailable::for('sign the avatar in', $result->reason);
            }
            if (!$result->isGranted() || $result->avatar === null) {
                return Response::problem(
                    Problem::coded(
                        403,
                        'Refused',
                        $result->code !== '' ? $result->code : 'sign-in-refused',
                        $result->reason !== '' ? $result->reason . '.' : 'The name or the password is wrong.',
                        'urn:opensim:problem:sign-in-refused',
                        $instance,
                    ),
                );
            }
            $avatar =
                Avatar::find($config, $result->avatar->id) ??
                throw ChannelUnavailable::for('sign the avatar in', 'the account cannot be read back');
        } catch (\Throwable $error) {
            return Response::problem(Problem::from($error, $instance));
        }
        $self = self::path('avatars', $avatar->id(), $grid);

        return Response::document(['data' => self::resource('avatars', $avatar, $grid), 'links' => ['self' => $self]]);
    }

    /**
     * Give a token to choose a new password to the avatars a name or an email is: mailed to them (202, the same answer whether the
     * name or the email is someone's or not), or given back when `send` is false (for whoever delivers it: an administrator).
     *
     * @param array<string,mixed> $attributes `name` or `email`, `link` (the page that takes the token, `{token}` in it), `send`
     */
    public function requestPasswordReset(string $grid, array $attributes): Response
    {
        $instance = '/grids/' . rawurlencode($grid) . '/password-resets';
        try {
            $login = trim((string) ($attributes['name'] ?? ($attributes['email'] ?? '')));
            if ($login === '') {
                throw new \InvalidArgumentException('a forgotten password needs the name or the email of the avatar');
            }
            $send = !Result::isFalse($attributes['send'] ?? true);
            $given = $this->access($grid)->requestPasswordReset($login, self::link($attributes), $send);
        } catch (\Throwable $error) {
            return Response::problem(Problem::from($error, $instance));
        }
        if ($send) {
            return Response::document(
                ['meta' => ['message' => 'When an avatar has this name or this email, a mail was sent to it.']],
                202,
            );
        }

        return $given === []
            ? Response::problem(Problem::notFound("avatar called '$login'", $instance))
            : Response::document([
                'data' => array_map(static fn(array $one): array => self::given('password-resets', $one), $given),
                'links' => ['self' => $instance],
            ]);
    }

    /**
     * Choose a new password with the token of a forgotten password, once. The avatar, or a problem 403 when the token is refused.
     *
     * @param array<string,mixed> $attributes `token` and `password`
     */
    public function resetPassword(string $grid, array $attributes): Response
    {
        $instance = '/grids/' . rawurlencode($grid) . '/password-resets/complete';
        try {
            $avatar = $this->access($grid)->resetPassword(
                (string) ($attributes['token'] ?? ''),
                (string) ($attributes['password'] ?? ''),
            );
        } catch (\Throwable $error) {
            return Response::problem(Problem::from($error, $instance));
        }

        return $this->avatarOrRefusal($avatar, $grid, $instance);
    }

    /**
     * Give again a token to confirm the email of an avatar that waits for it: mailed (202, 503 when the mail cannot be sent), or
     * given back when `send` is false. An avatar that does not wait for it (an administrator disabled it) is a conflict.
     *
     * @param array<string,mixed> $attributes `avatar` (its UUID or its name), `link`, `send`
     */
    public function requestEmailConfirmation(string $grid, array $attributes): Response
    {
        $instance = '/grids/' . rawurlencode($grid) . '/email-confirmations';
        try {
            $access = $this->access($grid);
            $id = trim((string) ($attributes['avatar'] ?? ''));
            $avatar = $id === '' ? null : Avatar::find($this->gridConfig($grid), $id);
            if ($avatar === null) {
                return Response::problem(Problem::notFound("avatar called '$id'", $instance));
            }
            if (!$access->waitsForConfirmation($avatar)) {
                return Response::problem(
                    new Problem(
                        409,
                        'Conflict',
                        "The avatar {$avatar->name()} has no email confirmation pending.",
                        'urn:opensim:problem:no-confirmation-pending',
                        $instance,
                    ),
                );
            }
            $send = !Result::isFalse($attributes['send'] ?? true);
            $given = $access->requestEmailConfirmation($avatar, self::link($attributes), $send);
            if ($send && !$given['sent']) {
                return Response::problem(
                    Problem::coded(
                        503,
                        'Not available',
                        'mail-failed',
                        'cannot send the mail: the mail system of the machine refused it',
                        'urn:opensim:problem:channel-unavailable',
                        $instance,
                    ),
                );
            }
        } catch (\Throwable $error) {
            return Response::problem(Problem::from($error, $instance));
        }

        return $send
            ? Response::document(['meta' => ['message' => 'A mail was sent to the avatar.']], 202)
            : Response::document([
                'data' => [self::given('email-confirmations', $given)],
                'links' => ['self' => $instance],
            ]);
    }

    /**
     * Confirm the email of an avatar with the token of its mail, once: the avatar is activated. The avatar, or a problem 403 when
     * the token is refused.
     *
     * @param array<string,mixed> $attributes `token`
     */
    public function confirmEmail(string $grid, array $attributes): Response
    {
        $instance = '/grids/' . rawurlencode($grid) . '/email-confirmations/complete';
        try {
            $avatar = $this->access($grid)->confirmEmail((string) ($attributes['token'] ?? ''));
        } catch (\Throwable $error) {
            return Response::problem(Problem::from($error, $instance));
        }

        return $this->avatarOrRefusal($avatar, $grid, $instance);
    }

    /**
     * An asset of a grid as a page shows it: an image of any type as a picture in the format asked, resized to a width when it is asked
     * (the grid keeps textures as JPEG 2000), the other types as they are. What is not there is the picture of nothing, with the status
     * 404.
     */
    public function media(
        string $grid,
        string $id,
        string $format = 'jpg',
        ?ConverterInterface $converter = null,
        ?int $width = null,
    ): Response {
        $instance = self::path('assets', $id, $grid);
        try {
            $found = Grid::find($this->environment, $grid) ?? throw NotFound::for("grid called '$grid'");
            $picture = WebAssets::for($found, $converter)->get($id, $format, $width);
        } catch (\Throwable $error) {
            return Response::problem(Problem::from($error, $instance));
        }

        return Response::binary($picture['data'], $picture['mime'], $picture['found'] ? 200 : 404);
    }

    /**
     * The message of the day of a grid, as plain text: what its viewers show at login, `<USERNAME>` replaced when a name is given.
     */
    public function motd(string $grid, string $username = ''): Response
    {
        try {
            $found = Grid::find($this->environment, $grid) ?? throw NotFound::for("grid called '$grid'");
        } catch (\Throwable $error) {
            return Response::problem(Problem::from($error, self::path('motd', null, $grid)));
        }

        return Response::binary($found->motd($username), 'text/plain; charset=utf-8');
    }

    /**
     * The picture of a text, the texture of a prim: square, the text centered.
     *
     * @param array<string,mixed> $query `text` (the name of the font when there is none), `font`, `size` (1 to 1000), `color`,
     *        `background`, `download` (offer it as a file)
     */
    public function textImage(array $query = []): Response
    {
        try {
            $font = (string) ($query['font'] ?? 'Impact');
            $size = (int) ($query['size'] ?? 120);
            $text = mb_substr((string) ($query['text'] ?? $font), 0, 500);
            $png =
                $this->renderer()->render(
                    $text,
                    $font,
                    $size > 0 ? min($size, 1000) : 120,
                    (string) ($query['color'] ?? 'white'),
                    (string) ($query['background'] ?? 'black'),
                ) ?? throw ChannelUnavailable::for('draw the text', 'this server has no Imagick (php-imagick)');
        } catch (\Throwable $error) {
            return Response::problem(Problem::from($error, '/text-images'));
        }
        $name = mb_substr(trim((string) preg_replace('/[^\w\- .]+/u', '', $text)), 0, 60);

        return Response::binary(
            $png,
            'image/png',
            headers: Result::isTrue($query['download'] ?? false)
                ? ['Content-Disposition' => 'attachment; filename="' . ($name === '' ? 'text' : $name) . '.png"']
                : [],
        );
    }

    /**
     * The registration of a simulator to the search: it tells that it is online (it starts, its regions are to be read) or offline
     * (it stops). What OpenSimSearch calls is fixed: a GET with the `host` and the `port` of the simulator (not those of the grid) and
     * the `service`. The answer is nothing; the simulator waits for it to go on starting, so the snapshot is read once it is sent
     * (see finish()).
     *
     * @param array<string,mixed> $query `host`, `port`, `service` (`online` or `offline`)
     */
    public function register(string $grid, array $query): Response
    {
        $host = trim((string) ($query['host'] ?? ''));
        $port = (int) ($query['port'] ?? 0);
        $service = (string) ($query['service'] ?? '');
        try {
            if ($host === '' || $port <= 0) {
                throw new Failure('Bad request: missing simulator host and/or port', 400);
            }
            if (!in_array($service, ['online', 'offline'], true)) {
                throw new Failure('Bad request: service is online or offline', 400);
            }
            [$db, $regions] = $this->searchDatabase($grid);
            $registry = new SearchRegistry($db, $regions, $this->environment->clock);
            if ($service === 'offline') {
                $registry->unregister($host, $port);
            } else {
                $registry->register($host, $port);
                // A simulator registers while it starts: its regions are loaded a little later, and it keeps the snapshot it makes
                // when they are. It is read then, and again soon after
                $this->after[] = function () use ($db, $regions, $registry, $host, $port): void {
                    $this->environment->clock->sleep(self::REGISTER_DELAY);
                    (new SearchParser($registry, $db, $regions, $this->environment->http))->parse();
                    $registry->again($host, $port);
                };
            }
        } catch (Failure $failure) {
            return Response::binary($failure->getMessage() . "\n", 'text/plain; charset=utf-8', $failure->status);
        } catch (\Throwable $error) {
            return Response::binary("Internal error\n", 'text/plain; charset=utf-8', 500);
        }

        return Response::binary('', 'text/plain; charset=utf-8');
    }

    /**
     * The questions of the viewers to the search of a grid (OpenSimSearch): XML-RPC, the methods and the answers fixed by the
     * viewers. The search can be shared by several grids: `gk` in the address, which the viewers do not send, says for which grid
     * the search is made.
     *
     * @param array<string,mixed> $query `gk`, the gatekeeper of the grid that asks
     */
    public function searchQuery(string $grid, string $body, array $query = []): Response
    {
        try {
            [$db, $regions, $config] = $this->searchDatabase($grid);
            $profiles = Connections::pdo($config, Database::Profiles);
            $methods = new SearchQuery(
                $db,
                $regions,
                $profiles?->connected ? $profiles : null,
                Connections::pdo($config, 'UserAccountService'),
                Connections::pdo($config, 'PresenceService'),
                isset($query['gk']) ? (string) $query['gk'] : null,
                $this->environment->clock,
            );
        } catch (Failure $failure) {
            return XmlRpc::failure($failure);
        }

        return (new XmlRpc($methods->methods()))->call($body);
    }

    /**
     * What the simulators ask of the offline messages (the module V1 of OpenSimulator): `SaveMessage` and `RetrieveMessages`, the
     * messages in the XML of the module. The mails to the avatars who want them are sent once the answer is sent (see finish()).
     */
    public function offline(string $grid, string $method, string $body): Response
    {
        try {
            $found = Grid::find($this->environment, $grid) ?? throw new Failure("There is no grid called '$grid'", 404);
            $config = ($this->config)($found) ?? throw new Failure("There is no config for the grid '$grid'", 404);
            $messages = Connections::pdo($config, Database::Offline);
            if ($messages === null || !$messages->connected) {
                throw new Failure('Bad configuration: the database of the offline messages is not available', 503);
            }
            $profiles = Connections::pdo($config, Database::Profiles);
            $service = new OfflineMessages(
                $messages,
                $profiles?->connected ? $profiles : null,
                $found->name(),
                $config->find('Helpers', 'MailSender') ?? 'no-reply@' . $found->baseHostname(),
                $this->mailer ?? new PhpMailer(),
            );
        } catch (Failure $failure) {
            return XmlRpc::failure($failure);
        }
        $answer = $service->call($method, $body);
        $this->after[] = $service->finish(...);

        return $answer;
    }

    /**
     * What the viewers and the simulators ask of the currency of a grid (`currency.php` of its economy): XML-RPC, the methods and
     * the answers fixed by the viewers and the money modules.
     *
     * @param string $ip the address of whoever calls: a quote of currency is tied to it
     */
    public function currency(string $grid, string $body, string $ip = ''): Response
    {
        try {
            return (new XmlRpc($this->economy($grid, $ip)->currencyMethods()))->call($body);
        } catch (Failure $failure) {
            return XmlRpc::failure($failure);
        }
    }

    /** What the viewers ask of the land tools of a grid (`landtool.php` of its economy): XML-RPC, the answers fixed by the viewers. */
    public function landTool(string $grid, string $body, string $ip = ''): Response
    {
        try {
            return (new XmlRpc($this->economy($grid, $ip)->landMethods()))->call($body);
        } catch (Failure $failure) {
            return XmlRpc::failure($failure);
        }
    }

    /**
     * The currency of a grid, served.
     *
     * @throws Failure when the grid is not known, or has no database to keep the money
     */
    private function economy(string $nick, string $ip): CurrencyServer
    {
        $grid = Grid::find($this->environment, $nick) ?? throw new Failure("There is no grid called '$nick'", 404);
        $config = ($this->config)($grid) ?? throw new Failure("There is no config for the grid '$nick'", 404);
        $money = Connections::pdo($config, Database::Economy) ?? Connections::pdo($config, Database::Robust);
        if ($money === null || !$money->connected) {
            throw new Failure('Bad configuration: the database of the currency is not available', 503);
        }
        $settings = CurrencySettings::fromConfig($config, (string) $grid->address('currency'));

        return new CurrencyServer(
            new Currency(
                $settings,
                static fn(string $service): ?\OpenSim\Data\OSPDO => Connections::pdo($config, $service),
                $money,
            ),
            $ip,
        );
    }

    /**
     * The setup the answers of the quick setup stand for, with the proposals for the rest, and what is wrong with the answers.
     *
     * @param array<string,mixed> $answers type, name, login_uri, avatar, password, email
     */
    public function quickSetup(array $answers): Response
    {
        try {
            $result = QuickSetup::plan($this->environment, $answers);
            $result['capabilities'] = QuickSetup::CAPABILITIES;
        } catch (\Throwable $error) {
            return Response::problem(Problem::from($error, '/setups/quick'));
        }

        return Response::document([
            'data' => ['type' => 'setups', 'id' => 'quick', 'attributes' => $result],
            'links' => ['self' => '/setups/quick'],
        ]);
    }

    /** One step of the quick setup: the screen, filled, in; the next screen or the action to do, out (see QuickSetup::next()). */
    public function setupStep(array $attributes): Response
    {
        try {
            $result = QuickSetup::next($this->environment, $attributes);
        } catch (\Throwable $error) {
            return Response::problem(Problem::from($error, '/setups/steps'));
        }

        return Response::document([
            'data' => ['type' => 'steps', 'id' => 'quick', 'attributes' => $result],
            'links' => ['self' => '/setups/steps'],
        ]);
    }

    /**
     * The addresses of a grid: the ones it gives its viewers and its simulators (login, economy, search, registration, offline
     * messages, message of the day, guide...), each with where it comes from, and the ones the helpers need to know first.
     */
    public function addresses(string $grid): Response
    {
        $instance = self::path('addresses', null, $grid);
        try {
            $found = Grid::find($this->environment, $grid) ?? throw NotFound::for("grid called '$grid'");
            $addresses = $found->addresses();
            $sources = [];
            foreach (array_keys($addresses) as $key) {
                $sources[$key] = $found->service($key)?->source();
            }
        } catch (\Throwable $error) {
            return Response::problem(Problem::from($error, $instance));
        }

        return Response::document([
            'data' => [
                'type' => 'addresses',
                'id' => $found->nick(),
                'attributes' => [
                    'login' => $found->loginUrl(),
                    'uri' => $found->uri(),
                    'helpers' => $found->helpersUrl(),
                    'economy' => $found->economy(),
                    'search' => $found->searchUrl(),
                    'searchApi' => $found->searchApi(),
                    'register' => $found->registerUrl(),
                    'offline' => $found->offlineUrl(),
                    'addresses' => $addresses,
                    'sources' => $sources,
                ],
                'links' => ['self' => $instance],
            ],
        ]);
    }

    /**
     * What a grid says of itself (`get_grid_info`), every element it gave, the free ones included: as it said them when it was last
     * verified, else what its config says (`live` tells which).
     */
    public function info(string $grid): Response
    {
        $instance = self::path('info', null, $grid);
        try {
            $found = Grid::find($this->environment, $grid) ?? throw NotFound::for("grid called '$grid'");
        } catch (\Throwable $error) {
            return Response::problem(Problem::from($error, $instance));
        }

        return Response::document([
            'data' => [
                'type' => 'info',
                'id' => $found->nick(),
                'attributes' => [
                    'live' => $found->gridInfoIsLive(),
                    'login' => $found->loginUrl(),
                    'uri' => $found->uri(),
                    'elements' => (object) $found->gridInfo(),
                ],
                'links' => ['self' => $instance],
            ],
        ]);
    }

    /**
     * The state of a grid at a glance: its Robust, its simulators, its regions, who is in world. `format=text` gives the lines of
     * `opensim status`, `format=png` a picture of them for a prim.
     *
     * @param array<string,mixed> $query `format` (json, text or png); for png, `size` of the font, `color`, `background`
     */
    public function monitor(string $grid, array $query = []): Response
    {
        $instance = self::path('monitor', null, $grid);
        try {
            $found = Grid::find($this->environment, $grid) ?? throw NotFound::for("grid called '$grid'");
            $monitor = GridMonitor::of($found, ($this->config)($found));
            $text = GridMonitor::text($monitor);
            $format = (string) ($query['format'] ?? 'json');
            if ($format === 'text') {
                return Response::binary($text, 'text/plain; charset=utf-8');
            }
            if ($format === 'png') {
                $longest = max(array_map('strlen', explode("\n", $text)));
                $png =
                    $this->renderer()->render(
                        $text,
                        'DejaVu-Sans-Mono',
                        (int) ($query['size'] ?? max(10, min(80, (int) (1000 / max(1, $longest * 0.62))))),
                        (string) ($query['color'] ??
                            (['ok' => '#88ff88', 'degraded' => '#ffcc44', 'down' => '#ff6666'][$monitor['level']] ??
                                'white')),
                        (string) ($query['background'] ?? 'black'),
                    ) ?? throw ChannelUnavailable::for('draw the status', 'this server has no Imagick (php-imagick)');

                return Response::binary($png, 'image/png');
            }
        } catch (\Throwable $error) {
            return Response::problem(Problem::from($error, $instance));
        }

        return Response::document([
            'data' => [
                'type' => 'monitor',
                'id' => $found->nick(),
                'attributes' => $monitor,
                'links' => ['self' => $instance],
            ],
        ]);
    }

    /**
     * The health of a grid: every address its config gives and what the grid says of itself, asked (and the ones that are expected
     * and not given, told), the PHP extensions it needs, the files it reads. `level` is `error` when something does not answer as it
     * should, `ok` when all that is asked does, `none` when there is nothing to ask. A check is asked at the moment of the request, it
     * takes the time of the slowest address.
     */
    public function health(string $grid): Response
    {
        $instance = self::path('health', null, $grid);
        try {
            $found = Grid::find($this->environment, $grid) ?? throw NotFound::for("grid called '$grid'");
            $config = ($this->config)($found);
            $groups = Health::answers(
                Health::grid([
                    'config' => $config,
                    'live' => $found->gridInfo(),
                    'login' => (string) $found->loginUrl(),
                ]),
            );
            $groups[] = ['file' => 'PHP', 'title' => '', 'rows' => Health::extensions()];
            $files = Health::files($config?->files() ?? []);
            $files === [] || ($groups[] = ['file' => 'files', 'title' => '', 'rows' => $files]);
        } catch (\Throwable $error) {
            return Response::problem(Problem::from($error, $instance));
        }
        $levels = array_column(array_merge(...array_column($groups, 'rows') ?: [[]]), 'level');

        return Response::document([
            'data' => [
                'type' => 'health',
                'id' => $found->nick(),
                'attributes' => [
                    'level' => in_array('error', $levels, true)
                        ? 'error'
                        : (in_array('ok', $levels, true)
                            ? 'ok'
                            : 'none'),
                    'groups' => $groups,
                ],
                'links' => ['self' => $instance],
            ],
        ]);
    }

    /** The resolved system layout and the identities that own or access it. */
    public function layout(): Response
    {
        return Response::document([
            'data' => [
                'type' => 'layout',
                'id' => 'system',
                'attributes' => $this->environment->layout->systemInfo(),
            ],
            'links' => ['self' => '/layout'],
        ]);
    }

    /**
     * What waits for the answer to be sent: a simulator that registers is read, then the tasks that are due run. The application calls
     * it once the response is on its way (a PSR-15 handler cannot).
     */
    public function finish(): void
    {
        while (($todo = array_shift($this->after)) !== null) {
            $todo();
        }
        $this->scheduler?->tick();
    }

    /**
     * The search database of a grid, with its tables.
     *
     * @return array{0:\OpenSim\Data\OSPDO,1:string,2:GridConfig} the database, the table of the regions, the config of the grid
     * @throws Failure 503 when the grid has no search database or it cannot be reached
     */
    private function searchDatabase(string $nick): array
    {
        $grid = Grid::find($this->environment, $nick) ?? throw new Failure("There is no grid called '$nick'", 404);
        $config = ($this->config)($grid) ?? throw new Failure("There is no config for the grid '$nick'", 404);
        $db = Connections::pdo($config, Database::Search);
        if ($db === null || !$db->connected) {
            throw new Failure('Bad configuration: the search database is not available', 503);
        }
        $key = (string) Connections::key($config, Database::Search);
        if (!isset(self::$prepared[$key])) {
            SearchTables::ensure($db);
            self::$prepared[$key] = true;
        }

        return [$db, \OpenSim\Models\Region::searchTable($db), $config];
    }

    /** Run a task now, whether it is due or not. */
    public function run(string $name): Response
    {
        $instance = "/tasks/$name/runs";
        if ($this->scheduler === null || !in_array($name, array_column($this->scheduler->status(), 'name'), true)) {
            return Response::problem(Problem::notFound("task called '$name'", $instance));
        }
        $result = $this->scheduler->run($name, true);

        return Response::document(
            [
                'data' => [
                    'type' => 'runs',
                    'id' => $name,
                    'attributes' => [
                        'task' => $name,
                        'status' => $result->status,
                        'message' => $result->message,
                        'duration' => $result->duration,
                    ],
                ],
            ],
            $result->ok() ? 200 : 500,
        );
    }

    /**
     * The options of a list from a query: the sort of JSON:API (`-name`) becomes the one of the models (`name desc`).
     *
     * @param array<string,mixed> $query
     * @return array{search?:string,sort?:string,filters?:array<string,string>,page?:int,perPage?:int}
     */
    public static function options(array $query): array
    {
        $options = [];
        isset($query['search']) && ($options['search'] = (string) $query['search']);
        if (isset($query['sort']) && $query['sort'] !== '') {
            $sort = (string) $query['sort'];
            $options['sort'] = str_starts_with($sort, '-') ? substr($sort, 1) . ' desc' : $sort;
        }
        isset($query['filter']) &&
            is_array($query['filter']) &&
            ($options['filters'] = array_map('strval', $query['filter']));
        isset($query['page']['number']) && ($options['page'] = (int) $query['page']['number']);
        isset($query['page']['size']) && ($options['perPage'] = (int) $query['page']['size']);

        return $options;
    }

    /** The address of a resource or of a list. */
    public static function path(string $type, ?string $id = null, ?string $grid = null): string
    {
        $base = $grid !== null ? '/grids/' . rawurlencode($grid) . "/$type" : "/$type";

        return $id !== null ? $base . '/' . rawurlencode($id) : $base;
    }

    /** @return ModelInterface|Response the resource, or the problem that says it is not there */
    private function find(string $type, string $id, ?string $grid, string $instance): ModelInterface|Response
    {
        $class = self::classOf($type, $grid);
        $model = match (true) {
            $class === null => null,
            isset(self::RESOURCES[$type]) => $class::find($this->environment, $id),
            default => $class::find($this->gridConfig((string) $grid), $id),
        };
        if ($model === null) {
            $known = isset(self::RESOURCES[$type]) || isset(self::GRID_RESOURCES[$type]);

            return Response::problem(
                Problem::notFound(
                    $known ? rtrim($type, 's') . " called '$id'" : "kind of resource called '$type'",
                    $instance,
                ),
            );
        }

        return $model;
    }

    /** What lets the avatars of a grid of this machine back in: its tokens and its mails. */
    private function access(string $nick): AvatarAccess
    {
        $grid = Grid::find($this->environment, $nick) ?? throw NotFound::for("grid called '$nick'");
        $config = ($this->config)($grid) ?? throw NotFound::for("config of the grid '$nick' on this machine");
        $sender = $config->find('Helpers', 'MailSender') ?? 'no-reply@' . $grid->baseHostname();

        return new AvatarAccess(
            $config,
            $grid->name(),
            $grid->name() . " <$sender>",
            $this->mailer ?? new PhpMailer(),
            $this->environment->clock,
        );
    }

    /**
     * What is said of the confirmation of an email, in the meta of the avatar: `emailConfirmation` sent, not sent (the mail failed)
     * or given, and the token in `confirmation` when it is given instead of mailed.
     *
     * @param array{avatar:Avatar,token:string,expires:int,link:?string,sent:bool,email:string} $given
     * @return array<string,mixed>
     */
    private static function confirmation(array $given, bool $send): array
    {
        if ($send) {
            return ['emailConfirmation' => $given['sent'] ? 'sent' : 'not sent'];
        }

        return [
            'emailConfirmation' => 'given',
            'confirmation' => [
                'token' => $given['token'],
                'expires' => $given['expires'],
                'link' => $given['link'],
                'email' => $given['email'],
            ],
        ];
    }

    /** The page that takes a token, as the caller gives it. @param array<string,mixed> $attributes */
    private static function link(array $attributes): ?string
    {
        $link = trim((string) ($attributes['link'] ?? ''));

        return $link !== '' ? $link : null;
    }

    /**
     * A token given back instead of mailed, as a resource of the type of the operation.
     *
     * @param array{avatar:Avatar,token:string,expires:int,link:?string,sent:bool,email:string} $given
     * @return array<string,mixed>
     */
    private static function given(string $type, array $given): array
    {
        $avatar = $given['avatar'];

        return [
            'type' => $type,
            'id' => $avatar->id(),
            'attributes' => [
                'avatar' => $avatar->id(),
                'name' => $avatar->name(),
                'email' => $avatar->detail()['email'] ?? null,
                'token' => $given['token'],
                'expires' => $given['expires'],
                'link' => $given['link'],
                'sent' => $given['sent'],
            ],
        ];
    }

    /** The avatar a token was taken for, or the problem that says the token is refused. */
    private function avatarOrRefusal(?Avatar $avatar, string $grid, string $instance): Response
    {
        if ($avatar === null) {
            return Response::problem(
                new Problem(
                    403,
                    'Refused',
                    'The token is not valid, or it has expired.',
                    'urn:opensim:problem:token-refused',
                    $instance,
                ),
            );
        }
        $self = self::path('avatars', $avatar->id(), $grid);

        return Response::document(['data' => self::resource('avatars', $avatar, $grid), 'links' => ['self' => $self]]);
    }

    /** The config of a grid of this machine. */
    private function gridConfig(string $nick): GridConfig
    {
        $grid = Grid::find($this->environment, $nick) ?? throw NotFound::for("grid called '$nick'");

        return ($this->config)($grid) ?? throw NotFound::for("config of the grid '$nick' on this machine");
    }

    /** The model class of a type of resource, asked with a grid or without; null when the type is not one of those. */
    private static function classOf(string $type, ?string $grid): ?string
    {
        return $grid === null ? self::RESOURCES[$type] ?? null : self::GRID_RESOURCES[$type] ?? null;
    }

    /** @return list<string> the fonts the machine has to draw a text */
    public function fontNames(): array
    {
        return $this->renderer()->fonts();
    }

    private function renderer(): TextRendererInterface
    {
        return $this->texts ?? new ImagickTextRenderer();
    }

    /**
     * The destinations of the guide of a grid as resources, by id.
     *
     * @return array<string,array<string,mixed>>
     */
    private function destinations(string $grid): array
    {
        $found = Grid::find($this->environment, $grid) ?? throw NotFound::for("grid called '$grid'");
        $resources = [];
        foreach ($found->destinations(($this->config)($found)) as $category => $destinations) {
            foreach ($destinations as $destination) {
                $id = $base = Slug::slug("$category " . $destination['name']);
                for ($n = 2; isset($resources[$id]); $n++) {
                    $id = "$base-$n";
                }
                $resources[$id] = [
                    'type' => 'destinations',
                    'id' => $id,
                    'attributes' => [
                        'name' => $destination['name'],
                        'category' => (string) $category,
                        'url' => $destination['url'],
                    ],
                    'links' => ['self' => self::path('destinations', $id, $grid)],
                ];
            }
        }

        return $resources;
    }

    /** @return array{type:string,id:string,attributes:array<string,mixed>,links:array{self:string}} */
    private static function fontResource(string $name): array
    {
        return [
            'type' => 'fonts',
            'id' => $name,
            'attributes' => ['name' => $name],
            'links' => ['self' => self::path('fonts', $name)],
        ];
    }

    private function tasks(string $instance): Response
    {
        $tasks = $this->scheduler?->status() ?? [];

        return Response::document([
            'data' => array_map(static fn(array $task): array => self::taskResource($task), $tasks),
            'meta' => ['total' => count($tasks)],
            'links' => ['self' => $instance],
        ]);
    }

    private function task(string $name, string $instance): Response
    {
        foreach ($this->scheduler?->status() ?? [] as $task) {
            if ($task['name'] === $name) {
                return Response::document(['data' => self::taskResource($task), 'links' => ['self' => $instance]]);
            }
        }

        return Response::problem(Problem::notFound("task called '$name'", $instance));
    }

    /**
     * @param array<string,mixed> $task
     * @return array<string,mixed>
     */
    private static function taskResource(array $task): array
    {
        $name = $task['name'];
        unset($task['name']);

        return ['type' => 'tasks', 'id' => $name, 'attributes' => $task, 'links' => ['self' => "/tasks/$name"]];
    }

    /** @return array<string,mixed> a form: its fields, without what only the PHP side can do (the callbacks) */
    private function form(string $name): array
    {
        $fields = match ($name) {
            'grid' => Grid::fields(),
            'remote-grid' => Grid::remoteFields(),
            'simulator' => Simulator::fields(),
            'region' => Region::fields(),
        };
        $fields = array_map(
            static fn(array $field): array => array_diff_key($field, ['callback' => 1, 'validation' => 1]),
            $fields,
        );

        return [
            'type' => 'forms',
            'id' => $name,
            'attributes' => ['fields' => $fields],
            'links' => ['self' => "/forms/$name"],
        ];
    }

    /** @return array{type:string,id:string,attributes:array<string,mixed>,links:array{self:string}} */
    private static function resource(string $type, ModelInterface $model, ?string $grid = null): array
    {
        $id = (string) $model->id();
        $attributes = $model->detail();
        unset($attributes['id']);

        return [
            'type' => $type,
            'id' => $id,
            'attributes' => $attributes,
            'links' => ['self' => self::path($type, $id, $grid)],
        ];
    }
}
