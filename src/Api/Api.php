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
use OpenSim\Assets\ImagickConverter;
use OpenSim\Assets\ImagickTextRenderer;
use OpenSim\Assets\Logos;
use OpenSim\Assets\TextRendererInterface;
use OpenSim\Assets\WebAssets;
use OpenSim\Provisioning\Web\SitePages;
use OpenSim\Models\Archive;
use OpenSim\Models\Asset;
use OpenSim\Models\Avatar;
use OpenSim\Models\AvatarModel;
use OpenSim\Models\Estate;
use OpenSim\Models\Grid;
use OpenSim\Models\Post;
use OpenSim\Provisioning\QuickSetup;
use OpenSim\Provisioning\SetupApply;
use OpenSim\Provisioning\SetupFile;
use OpenSim\Provisioning\Import\SimulatorImport;
use OpenSim\Provisioning\NullUi;
use OpenSim\Models\Parcel;
use OpenSim\Models\Region;
use OpenSim\Models\Service;
use OpenSim\Models\Simulator;
use OpenSim\Text\Notice;
use OpenSim\OpenSim;
use OpenSim\Runtime\Environment;
use OpenSim\Runtime\InstanceAccess;
use OpenSim\Data\Connections;
use OpenSim\Data\Database;
use OpenSim\Provisioning\Slug;
use OpenSim\Economy\Currency;
use OpenSim\Health\Health;
use OpenSim\Health\HealthCheck;
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
        'posts' => Post::class,
    ];

    /** Seconds a simulator that registers is given to load its regions before its snapshot is read. */
    public const REGISTER_DELAY = 30;

    /** The forms the engine knows (what a setup asks), by name. */
    public const FORMS = ['grid', 'remote-grid', 'simulator', 'region'];

    /** What the profile of an avatar lists, asked under it: `/grids/{grid}/avatars/{id}/picks`. */
    public const AVATAR_ITEMS = ['picks', 'classifieds', 'groups', 'posts'];

    /** @var \Closure(Grid):?GridConfig the config of a grid, from its folder unless the caller says otherwise */
    private \Closure $config;

    /** @var \Closure(Environment):?string why this process may not start or stop the instances, null when it may */
    private \Closure $instanceAccess;

    /** @var list<\Closure():void> what is done once the answer is sent (see finish()) */
    private array $after = [];

    /** @var array<string,true> the search databases whose tables are checked */
    private static array $prepared = [];

    /**
     * @param ?Scheduler $scheduler the tasks of the application, none when it has none
     * @param ?\Closure(Grid):?GridConfig $config the config of a grid (default: its folder)
     * @param ?TextRendererInterface $texts draws the images of a text (default: ImageMagick)
     * @param ?MailerInterface $mailer sends the mails of the grid (default: the mail function of PHP)
     * @param ?\Closure(Environment):?string $instanceAccess why this process may not start or stop the instances (default:
     *        InstanceAccess::refusal())
     * @param ?\Closure(array{host:string,name:string,user:string,pass:string},string):void $dumper writes the dump of a database for a
     *        backup (default: DatabaseDump::write())
     * @param ?\Closure(\OpenSim\Config\ConnectionString,string):void $restoreLoader makes a database again from a dump for a restore
     *        (default: BackupRestore's own)
     */
    public function __construct(
        private readonly Environment $environment,
        private readonly ?Scheduler $scheduler = null,
        ?\Closure $config = null,
        private readonly ?TextRendererInterface $texts = null,
        private readonly ?MailerInterface $mailer = null,
        ?\Closure $instanceAccess = null,
        private readonly ?\Closure $dumper = null,
        private readonly ?\Closure $restoreLoader = null,
        ?\Closure $grids = null,
        private readonly ?\OpenSim\Provisioning\AccountImporter $accounts = null,
    ) {
        // The grids it serves: the ones of this machine (their folder in EtcRoot), or the ones an application gives (a site that has
        // the engine in its own folder: the settings of a grid and its databases over the network, nothing read from the machine)
        $this->grids = $grids ?? fn(string $nick): ?Grid => Grid::find($this->environment, $nick);
        $this->instanceAccess = $instanceAccess ?? static fn(Environment $environment): ?string => InstanceAccess::refusal($environment->layout);
        // The config of a grid as the grid reads it: the one an application gives, else its folder, with the access to the
        // databases the environment gives
        $this->config = $config ?? static fn(Grid $grid): ?GridConfig => $grid->config();
    }

    /** @var \Closure(string):?Grid the grid of a nick, null when it is not one of the grids served */
    private \Closure $grids;

    /** The grid of a nick, as the API serves it (its folder on this machine, or the one an application gives), null when none. */
    private function grid(string $nick): ?Grid
    {
        return ($this->grids)($nick);
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
                $found = $this->grid($grid) ?? throw NotFound::for("grid called '$grid'", 'grids');

                return Response::binary(
                    DestinationList::format($found->destinations(($this->config)($found), true)),
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
            ] +
                ($collection->available() === [] ? [] : ['available' => $collection->available()]) +
                ($collection->counts() === [] ? [] : ['counts' => $collection->counts()]) +
                ($type === 'avatars' ? ['profileFlags' => Avatar::profileFlags()] : []),
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
                    : Response::problem(Problem::notFound("destination called '$id'", $instance, 'destinations'));
            }
            if ($type === 'forms') {
                return in_array($id, self::FORMS, true)
                    ? Response::document(['data' => $this->form($id), 'links' => ['self' => $instance]])
                    : Response::problem(Problem::notFound("form called '$id'", $instance));
            }
            $model = $this->find($type, $id, $grid, $instance);
            // Alone, a simulator says whether each way to it is reached, not only configured: a list does not wait for that
            $model instanceof Simulator && $model->probe();
        } catch (\Throwable $error) {
            return Response::problem(Problem::from($error, $instance));
        }

        return $model instanceof Response
            ? $model
            : Response::document(
                ['data' => self::resource($type, $model, $grid), 'links' => ['self' => $instance]] +
                    // The settings of the grid the form of the item needs (its fields that say `setting`)
                    ($model instanceof Avatar ? ['meta' => self::avatarForm($model)] : []),
            );
    }

    /**
     * What the profile of an avatar lists (Api::AVATAR_ITEMS): the picks it shows, the classifieds that have not expired, the
     * groups it shows. The picture of each is the UUID of its asset (`/grids/{grid}/assets/{id}.{format}` shows it), null without
     * one.
     */
    public function avatarItems(string $grid, string $id, string $kind): Response
    {
        $instance = self::path('avatars', $id, $grid) . "/$kind";
        try {
            $avatar = Avatar::find($this->gridConfig($grid), $id);
            if ($avatar === null) {
                return Response::problem(Problem::notFound("avatar called '$id'", $instance, 'avatars'));
            }
            $rows = match ($kind) {
                'picks' => $avatar->picks(),
                'groups' => $avatar->groups(),
                'posts' => \OpenSim\Flux\Posts::for($this->gridConfig($grid))->of((string) $avatar->uuid(), 1, 100),
                default => $avatar->classifieds((int) $this->environment->clock->time()),
            };
        } catch (\Throwable $error) {
            return Response::problem(Problem::from($error, $instance));
        }

        return Response::document([
            'data' => array_map(static fn(array $row): array => self::avatarItem($kind, $row), $rows),
            'links' => ['self' => $instance],
        ]);
    }

    /**
     * Make a resource (an avatar; the others are made by the setup and answer that no channel does it).
     *
     * @param array<string,mixed> $attributes the values of the new resource, by the names of its attributes
     */
    public function create(string $type, array $attributes, ?string $grid = null): Response
    {
        $instance = self::path($type, null, $grid);
        if ($type === 'archives' && $grid === null) {
            return $this->backup($attributes);
        }
        try {
            $class = self::classOf($type, $grid);
            if ($class === null) {
                return Response::problem(Problem::notFound("kind of resource called '$type'", $instance));
            }
            // A new avatar whose email is to be confirmed is only a registration until then: nothing is written in the grid
            if ($type === 'avatars' && Result::isTrue($attributes['confirmEmail'] ?? false)) {
                $send = !Result::isFalse($attributes['send'] ?? true);
                $given = $this->access((string) $grid)->requestRegistration(
                    $attributes,
                    self::link($attributes),
                    $send,
                );

                return Response::document(
                    [
                        'data' => [
                            'type' => 'registrations',
                            'id' => $given['id'],
                            'attributes' => $given['registration'],
                        ],
                        'meta' => self::confirmation($given, $send) + match (true) {
                            // A site that delivers the token itself says what it does with it
                            !$send => [],
                            (bool) $given['sent'] => self::said(Notice::of('registered-confirm', ['email' => (string) $given['email']])),
                            default => self::said(Notice::of('registered-mail-failed', ['email' => (string) $given['email']])),
                        },
                    ],
                    202,
                );
            }
            $created = isset(self::RESOURCES[$type])
                ? $class::create($this->environment, $attributes)
                : $class::create($this->gridConfig((string) $grid), $attributes);
        } catch (\Throwable $error) {
            return Response::problem(Problem::from($error, $instance));
        }
        $self = self::path($type, (string) $created->id(), $grid);
        // A new avatar is ready to sign in: said as a notice, for a site to say it in its language
        $meta = $created instanceof Avatar ? ['meta' => self::said(Notice::of('registered', ['name' => $created->name()]))] : [];

        return new Response(
            201,
            ['Content-Type' => Response::JSON_API, 'Location' => $self],
            ['data' => self::resource($type, $created, $grid), 'links' => ['self' => $self]] + $meta,
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
            // What is left to do after the change (a restart)
            if ($model instanceof Region && $model->reminders() !== []) {
                $meta['reminders'] = $model->reminders();
            }
            if ($email !== null) {
                $send = !Result::isFalse($options['send'] ?? true);
                $meta = self::confirmation(
                    $this->access((string) $grid)->requestEmailChange($model, $email, self::link($options), $send),
                    $send,
                );
            }
            $model = $this->find($type, $id, $grid, $instance);
            $model instanceof Avatar && ($meta += self::avatarForm($model));
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

    /**
     * What the API says without an error (Text\Notice): its English text as `message`, as before, and the notice for a site to say it
     * in its language.
     *
     * @param array{code:string,params:array<string,string|int>,detail:string} $notice
     * @return array{message:string,notice:array<string,mixed>}
     */
    private static function said(array $notice): array
    {
        return ['message' => $notice['detail'], 'notice' => $notice];
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
                ['meta' => self::said(Notice::of('mail-sent-if-known'))],
                202,
            );
        }

        return $given === []
            ? Response::problem(Problem::notFound("avatar called '$login'", $instance, 'avatars'))
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
            $send = !Result::isFalse($attributes['send'] ?? true);
            if ($avatar === null) {
                // A registration that waits: the avatar is not made yet
                $given = $id === '' ? [] : $access->renewRegistration($id, self::link($attributes), $send);
                if ($given === []) {
                    return Response::problem(Problem::notFound("avatar called '$id'", $instance, 'avatars'));
                }
            } elseif (!$access->waitsForConfirmation($avatar)) {
                return Response::problem(
                    new Problem(
                        409,
                        'Conflict',
                        "The avatar {$avatar->name()} has no email confirmation pending.",
                        'urn:opensim:problem:no-confirmation-pending',
                        $instance,
                    ),
                );
            } else {
                $given = [$access->requestEmailConfirmation($avatar, self::link($attributes), $send)];
            }
            if ($send && in_array(false, array_column($given, 'sent'), true)) {
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
            ? Response::document(['meta' => self::said(Notice::of('mail-sent'))], 202)
            : Response::document([
                'data' => array_map(static fn(array $one): array => self::given('email-confirmations', $one), $given),
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
     * (the grid keeps textures as JPEG 2000), the other types as they are. What is not there is the picture of nothing, or of a person
     * who has none (`$of` person, WebAssets::DEFAULTS), with the status 404.
     */
    public function media(
        string $grid,
        string $id,
        string $format = 'jpg',
        ?ConverterInterface $converter = null,
        ?int $width = null,
        string $of = 'place',
    ): Response {
        $instance = self::path('assets', $id, $grid);
        try {
            $found = $this->grid($grid) ?? throw NotFound::for("grid called '$grid'", 'grids');
            // Nothing here reads JPEG 2000: the external web assets service of the grid gives it
            $elsewhere = WebAssets::elsewhere($found, $id, $format, $converter !== null || ImagickConverter::available());
            if ($elsewhere !== null) {
                return Response::binary('', 'text/plain', 302, ['Location' => $elsewhere]);
            }
            $picture = WebAssets::for($found, $converter)->get($id, $format, $width, $of);
        } catch (\Throwable $error) {
            return Response::problem(Problem::from($error, $instance));
        }

        return Response::binary($picture['data'], $picture['mime'], $picture['found'] ? 200 : 404);
    }

    /** A logo the engine ships for the sites (Logos: w4os, OpenSimulator), as a picture; 404 for a name that is not one. */
    public function logo(string $name): Response
    {
        $file = Logos::file($name);

        return $file === null
            ? Response::problem(Problem::notFound("logo called '$name'", '/logos/' . rawurlencode($name)))
            : Response::binary((string) file_get_contents($file), 'image/png');
    }

    /**
     * The message of the day of a grid, as plain text: what its viewers show at login, `<USERNAME>` replaced when a name is given.
     */
    public function motd(string $grid, string $username = ''): Response
    {
        try {
            $found = $this->grid($grid) ?? throw NotFound::for("grid called '$grid'", 'grids');
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
            $found = $this->grid($grid) ?? throw new Failure("There is no grid called '$grid'", 404);
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
        $grid = $this->grid($nick) ?? throw new Failure("There is no grid called '$nick'", 404);
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

    /**
     * A setup file applied to a grid of this machine (SetupApply): the simulators it names made, or given the regions it names;
     * planned, or written with `mode` apply. The file is `text` (JSON or YAML, `format` when its first character does not tell), or
     * `setup`, the same as an object.
     *
     * @param array<string,mixed> $attributes
     */
    public function setupFile(array $attributes): Response
    {
        $instance = '/setups/files';
        try {
            $mode = (string) ($attributes['mode'] ?? 'plan');
            in_array($mode, ['plan', 'apply'], true) || throw InvalidValue::because('invalid-request', "'$mode' is not a mode: plan or apply");
            $data = is_array($attributes['setup'] ?? null)
                ? $attributes['setup']
                : SetupFile::read((string) ($attributes['text'] ?? ''), ($attributes['format'] ?? '') !== '' ? (string) $attributes['format'] : null);
            $setup = SetupApply::prepare($this->environment, $data);
            $mode === 'apply' && $setup->apply(new NullUi());
        } catch (\Throwable $error) {
            return Response::problem(Problem::from($error, $instance));
        }

        return Response::document([
            'data' => [
                'type' => 'files',
                'id' => $setup->grid->nick(),
                'attributes' => ['mode' => $mode, 'applied' => $mode === 'apply'] + $setup->document() + [
                    'notes' => $mode === 'apply' ? [] : [Notice::of('plan-reserves-nothing')],
                ],
            ],
            'links' => ['self' => $instance],
        ]);
    }

    /** The settings of a grid, a group of them (GridSettings): the models... */
    public function settings(string $grid, string $group): Response
    {
        return $this->gridSettings($grid, $group, null);
    }

    /**
     * Change settings of a grid, of a group (GridSettings::write()).
     *
     * @param array<string,mixed> $attributes
     */
    public function updateSettings(string $grid, string $group, array $attributes): Response
    {
        return $this->gridSettings($grid, $group, $attributes);
    }

    /** @param ?array<string,mixed> $changes */
    private function gridSettings(string $grid, string $group, ?array $changes): Response
    {
        $instance = self::path('grids', $grid, null) . "/settings/$group";
        try {
            $found = $this->grid($grid) ?? throw NotFound::for("grid called '$grid'", 'grids');
            in_array($group, \OpenSim\Models\GridSettings::groups(), true) || throw NotFound::for("settings called '$group'");
            $settings = new \OpenSim\Models\GridSettings($found);
            $values = $changes === null ? $settings->read($group) : $settings->write($group, $changes);
            $meta = $settings->meta($group);
        } catch (\Throwable $error) {
            return Response::problem(Problem::from($error, $instance));
        }

        return Response::document(['data' => ['type' => 'settings', 'id' => $group, 'attributes' => $values], 'links' => ['self' => $instance]] + ($meta === [] ? [] : ['meta' => $meta]));
    }

    /**
     * The options of the grid its simulators miss in their files, `mode` plan (the default) or apply (GridOptions::repair()).
     *
     * @param array<string,mixed> $attributes
     */
    public function repairOptions(string $grid, array $attributes): Response
    {
        $instance = self::path('grids', $grid, null) . '/options/repair';
        try {
            $mode = (string) ($attributes['mode'] ?? 'plan');
            in_array($mode, ['plan', 'apply'], true) || throw InvalidValue::because('invalid-request', "'$mode' is not a mode: plan or apply");
            $found = $this->grid($grid) ?? throw NotFound::for("grid called '$grid'", 'grids');
            in_array('files', $found->access(), true) || throw ChannelUnavailable::because("repair the options of $grid", 'options-no-files');
            $repair = (new \OpenSim\Provisioning\GridOptions($found))->repair($mode === 'apply');
        } catch (\Throwable $error) {
            return Response::problem(Problem::from($error, $instance));
        }

        return Response::document([
            'data' => ['type' => 'repairs', 'id' => $grid, 'attributes' => ['mode' => $mode] + $repair],
            'links' => ['self' => $instance],
        ]);
    }

    /** What a site needs of a grid for its helpers (SiteDescription): from the files of this machine, never a secret. */
    public function siteDescription(string $grid): Response
    {
        $instance = self::path('grids', $grid, null) . '/site-description';
        try {
            $found = $this->grid($grid) ?? throw NotFound::for("grid called '$grid'", 'grids');
            in_array('files', $found->access(), true) || throw ChannelUnavailable::because("describe $grid for a site", 'options-no-files');
            $description = \OpenSim\Models\SiteDescription::of($found);
        } catch (\Throwable $error) {
            return Response::problem(Problem::from($error, $instance));
        }

        return Response::document(['data' => ['type' => 'site-descriptions', 'id' => $grid, 'attributes' => $description], 'links' => ['self' => $instance]]);
    }

    /**
     * Write a post on the profile of an avatar (its stream), as the avatar or a manager: `content`, plain text of Posts::MAX
     * characters at most (`post-invalid`).
     *
     * @param array<string,mixed> $attributes
     */
    public function createPost(string $grid, string $id, array $attributes): Response
    {
        $instance = self::path('avatars', $id, $grid) . '/posts';
        try {
            $config = $this->gridConfig($grid);
            $avatar = Avatar::find($config, $id);
            if ($avatar === null) {
                return Response::problem(Problem::notFound("avatar called '$id'", $instance, 'avatars'));
            }
            $post = \OpenSim\Flux\Posts::for($config)->add((string) $avatar->uuid(), $avatar->name(), (string) ($attributes['content'] ?? ''));
        } catch (\Throwable $error) {
            return Response::problem(Problem::from($error, $instance));
        }

        return Response::document(['data' => self::avatarItem('posts', $post), 'links' => ['self' => "$instance/{$post['id']}"]], 201);
    }

    /** Delete a post of the stream of an avatar, as its author or a manager: a post of another avatar is not there. */
    public function deletePost(string $grid, string $id, string $post): Response
    {
        $instance = self::path('avatars', $id, $grid) . "/posts/$post";
        try {
            $config = $this->gridConfig($grid);
            $avatar = Avatar::find($config, $id);
            $posts = \OpenSim\Flux\Posts::for($config);
            $found = $avatar === null ? null : $posts->find($post);
            if ($found === null || $found['avatar'] !== strtolower((string) $avatar->uuid())) {
                return Response::problem(Problem::notFound("post '$post' of the avatar '$id'", $instance, 'posts'));
            }
            $posts->remove($post);
        } catch (\Throwable $error) {
            return Response::problem(Problem::from($error, $instance));
        }

        return new Response(204, [], []);
    }

    /**
     * What another install brings into this one, planned, or applied with `mode` apply: `kind` simulator (a simulator of an older
     * install joining a grid of this machine, SimulatorImport), its config `file`, the `grid`, and `name` and `core` when they are not
     * the ones of its config and of the grid; flux, grid-settings, accounts (importAccounts()). Nothing is written in plan mode.
     *
     * @param array<string,mixed> $attributes
     */
    public function import(array $attributes): Response
    {
        $instance = '/setups/imports';
        try {
            $kind = (string) ($attributes['kind'] ?? 'simulator');
            in_array($kind, ['simulator', 'flux', 'grid-settings', 'accounts'], true) || throw InvalidValue::because('invalid-request', "'$kind' cannot be imported: simulator, flux, grid-settings, accounts");
            $mode = (string) ($attributes['mode'] ?? 'plan');
            in_array($mode, ['plan', 'apply'], true) || throw InvalidValue::because('invalid-request', "'$mode' is not a mode: plan or apply");
            if ($kind === 'flux') {
                return $this->importFlux($attributes, $mode, $instance);
            }
            if ($kind === 'accounts') {
                return $this->importAccounts($attributes, $mode, $instance);
            }
            // The settings of a grid of another install, in the OpenSim.ini of a grid of this machine
            if ($kind === 'grid-settings') {
                $settings = \OpenSim\Provisioning\Import\GridSettingsImport::prepare($this->environment, (string) ($attributes['file'] ?? ''), (string) ($attributes['grid'] ?? ''));
                $document = $settings->document();
                $applied = $mode === 'apply' && $settings->apply();

                return Response::document([
                    'data' => [
                        'type' => 'imports',
                        'id' => $document['grid'],
                        'attributes' => ['kind' => $kind, 'mode' => $mode, 'applied' => $applied] + $document,
                    ],
                    'links' => ['self' => $instance],
                ]);
            }
            // Several simulators into one grid: planned together, checked between them, written in their order
            if (is_array($attributes['simulators'] ?? null)) {
                $imports = \OpenSim\Provisioning\Import\SimulatorImports::prepare(
                    $this->environment,
                    array_values(array_filter($attributes['simulators'], 'is_array')),
                    (string) ($attributes['grid'] ?? ''),
                );
                $mode === 'apply' && $imports->apply($this->environment, new NullUi(), $this->dumper);

                return Response::document([
                    'data' => [
                        'type' => 'imports',
                        'id' => $imports->grid,
                        'attributes' => ['kind' => $kind, 'mode' => $mode, 'applied' => $mode === 'apply'] + $imports->document(),
                    ],
                    'links' => ['self' => $instance],
                ]);
            }
            $import = SimulatorImport::prepare(
                $this->environment,
                (string) ($attributes['file'] ?? ''),
                (string) ($attributes['grid'] ?? ''),
                ($attributes['name'] ?? '') !== '' ? (string) $attributes['name'] : null,
                ($attributes['core'] ?? '') !== '' ? (string) $attributes['core'] : null,
                !Result::isFalse($attributes['scripts'] ?? true),
            );
            $mode === 'apply' && $import->apply($this->environment, new NullUi(), $this->dumper);
        } catch (\Throwable $error) {
            return Response::problem(Problem::from($error, $instance));
        }

        return Response::document([
            'data' => [
                'type' => 'imports',
                'id' => $import->plan->slug,
                'attributes' => ['kind' => $kind, 'mode' => $mode, 'applied' => $mode === 'apply'] + $import->document(),
            ],
            'links' => ['self' => $instance],
        ]);
    }

    /**
     * The accounts of a list made in a grid (AccountList, AccountImporter): `text` the list (CSV: first, last, email, password, with or
     * without a header line; JSON or YAML: a list of objects with the same keys), `format` when it is not found from the text, the
     * `grid`, `keepGoing` to go on after an account that failed. The names that exist are skipped; an account given no password gets
     * one, given back only once it is made (`results`, `csv`).
     *
     * @param array<string,mixed> $attributes
     */
    private function importAccounts(array $attributes, string $mode, string $instance): Response
    {
        $nick = (string) ($attributes['grid'] ?? '');
        $grid = $this->grid($nick) ?? throw NotFound::for("grid called '$nick'", 'grids');
        $format = ($attributes['format'] ?? '') !== '' ? (string) $attributes['format'] : null;
        $format === null || in_array($format, ['csv', 'json', 'yaml'], true) || throw InvalidValue::because('invalid-request', "'$format' is not a format: csv, json or yaml");
        $list = \OpenSim\Provisioning\AccountList::parse((string) ($attributes['text'] ?? ''), $format);
        $list['accounts'] !== [] || throw InvalidValue::because('invalid-request', 'nothing to make' . ($list['errors'] === [] ? '' : ': every line has a problem (' . implode('; ', $list['errors']) . ')'));
        $done = ($this->accounts ?? \OpenSim\Provisioning\AccountImporter::forModels())->run($grid, $list['accounts'], $mode === 'apply', !Result::isFalse($attributes['keepGoing'] ?? false));
        $done['error'] === null || throw new \RuntimeException($done['error']);

        $counts = [];
        $results = [];
        foreach ($done['results'] as $row) {
            $counts[$row['status']] = ($counts[$row['status']] ?? 0) + 1;
            $made = $row['generated'] && $row['status'] === \OpenSim\Provisioning\AccountImporter::CREATED;
            // A password is given back only when it was made here and its account exists
            $results[] = ['first' => $row['first'], 'last' => $row['last'], 'email' => $row['email'], 'status' => $row['status'], 'detail' => $row['detail'], 'generated' => $row['generated'] && $row['status'] !== \OpenSim\Provisioning\AccountImporter::EXISTS]
                + ($made ? ['password' => $row['password']] : []);
        }
        $applied = $mode === 'apply';

        return Response::document([
            'data' => [
                'type' => 'imports',
                'id' => $nick,
                'attributes' => ['kind' => 'accounts', 'mode' => $mode, 'applied' => $applied, 'grid' => $nick, 'errors' => $list['errors'], 'counts' => $counts, 'results' => $results]
                    + ($applied ? ['csv' => \OpenSim\Provisioning\AccountImporter::resultCsv(array_map(static fn(array $row): array => $row + ['password' => '', 'generated' => false], $results))] : []),
            ],
            'links' => ['self' => $instance],
        ]);
    }

    /**
     * The posts of the stream of a site of w4os-wp brought into a grid (FluxImport): the `grid`, and `file`, the wp-config.php of the
     * site, which says its database and the prefix of its tables.
     *
     * @param array<string,mixed> $attributes
     */
    private function importFlux(array $attributes, string $mode, string $instance): Response
    {
        $grid = (string) ($attributes['grid'] ?? '');
        $site = \OpenSim\Flux\FluxImport::wordpress((string) ($attributes['file'] ?? ''));
        $import = \OpenSim\Flux\FluxImport::prepare($this->gridConfig($grid), \OpenSim\Flux\FluxImport::connect($site), $site['prefix']);
        $document = $import->document();
        $mode === 'apply' && $import->apply();

        return Response::document([
            'data' => [
                'type' => 'imports',
                'id' => $grid,
                'attributes' => ['kind' => 'flux', 'mode' => $mode, 'applied' => $mode === 'apply'] + $document,
            ],
            'links' => ['self' => $instance],
        ]);
    }

    /**
     * The backup of a grid, or of one of its simulators (BackupService: its configuration, its data, a dump of each database), in the
     * backup root: `kind` backup, `grid`, `simulator`, `logs`, `archives` (those of the users too), `keep` (how many of the series
     * are kept). By the user that runs the instances only (`instance-user-required`): their files are theirs.
     *
     * @param array<string,mixed> $attributes
     */
    public function backup(array $attributes): Response
    {
        $instance = '/archives';
        try {
            ($attributes['kind'] ?? 'backup') === 'backup' ||
                throw InvalidValue::because('invalid-request', 'an archive made here is a backup (kind backup); an iar or an oar is saved by the console of a simulator');
            $grid = trim((string) ($attributes['grid'] ?? ''));
            $grid !== '' || throw InvalidValue::because('invalid-request', 'the grid of the backup is needed (grid)');
            $refusal = ($this->instanceAccess)($this->environment);
            $refusal === null || throw InvalidValue::because('instance-user-required', $refusal);
            $refs = array_values(array_filter([$grid, trim((string) ($attributes['simulator'] ?? ''))]));
            $options = array_filter([
                'logs' => Result::isTrue($attributes['logs'] ?? false),
                'archives' => Result::isTrue($attributes['archives'] ?? false),
                'keep' => isset($attributes['keep']) ? (int) $attributes['keep'] : null,
            ], static fn($value): bool => $value !== null && $value !== false);
            $files = (new \OpenSim\Archives\BackupService(
                $this->environment,
                $this->dumper ?? \OpenSim\Archives\DatabaseDump::write(...),
                static function (string $message): void {},
            ))->run($refs, $options);
            $made = null;
            foreach (Archive::all($this->environment)->items() as $archive) {
                $archive->file() === ($files[0] ?? '') && ($made = $archive);
            }
            $made ?? throw new \RuntimeException('the backup is made, but not where the archives are listed: ' . ($files[0] ?? ''));
        } catch (NotFound|\OpenSim\Exceptions\ChannelUnavailable $error) {
            return Response::problem(Problem::from($error, $instance));
        } catch (\RuntimeException $error) {
            return Response::problem(new Problem(503, 'Not available', $error->getMessage(), 'urn:opensim:problem:channel-unavailable', $instance));
        } catch (\Throwable $error) {
            return Response::problem(Problem::from($error, $instance));
        }
        $self = self::path('archives', $made->id(), null);

        return new Response(
            201,
            ['Content-Type' => Response::JSON_API, 'Location' => $self],
            ['data' => self::resource('archives', $made, null), 'links' => ['self' => $self]],
        );
    }

    /**
     * A backup put back on this machine (BackupRestore): `file` (its path) or `archive` (its id in /archives), `mode` plan (the
     * default: what would be written, nothing written) or apply. Applied by the user that runs the instances only
     * (`instance-user-required`); what this machine has already is never replaced (`restore-conflict`).
     *
     * @param array<string,mixed> $attributes
     */
    public function restore(array $attributes): Response
    {
        $instance = '/setups/restores';
        try {
            $mode = (string) ($attributes['mode'] ?? 'plan');
            in_array($mode, ['plan', 'apply'], true) || throw InvalidValue::because('invalid-request', "'$mode' is not a mode: plan or apply");
            $file = (string) ($attributes['file'] ?? '');
            if ($file === '' && ($attributes['archive'] ?? '') !== '') {
                $file = Archive::find($this->environment, (string) $attributes['archive'])?->file() ??
                    throw NotFound::for("archive called '{$attributes['archive']}'");
            }
            $restore = \OpenSim\Archives\BackupRestore::prepare($this->environment, $file);
            if ($mode === 'apply') {
                $refusal = ($this->instanceAccess)($this->environment);
                $refusal === null || throw InvalidValue::because('instance-user-required', $refusal);
                $restore->apply($this->restoreLoader);
            }
        } catch (NotFound|\OpenSim\Exceptions\ChannelUnavailable $error) {
            return Response::problem(Problem::from($error, $instance));
        } catch (\RuntimeException $error) {
            // A file or a database that cannot be had: said, it is not a failure of the server
            return Response::problem(new Problem(503, 'Not available', $error->getMessage(), 'urn:opensim:problem:channel-unavailable', $instance));
        } catch (\Throwable $error) {
            return Response::problem(Problem::from($error, $instance));
        }

        return Response::document([
            'data' => [
                'type' => 'restores',
                'id' => basename($restore->file),
                'attributes' => ['mode' => $mode, 'applied' => $mode === 'apply'] + $restore->document(),
            ],
            'links' => ['self' => $instance],
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
            $found = $this->grid($grid) ?? throw NotFound::for("grid called '$grid'", 'grids');
            $addresses = $found->addresses();
            // Each service, what it is, where it is set: file by file, then section by section
            $services = array_map(static fn(Service $service): array => $service->address(), Service::of($found)->items());
            usort($services, static fn(array $a, array $b): int => [(string) $a['file'], (string) $a['section'], $a['key']] <=> [(string) $b['file'], (string) $b['section'], $b['key']]);
            $sources = [];
            foreach (array_keys($addresses) as $key) {
                $sources[$key] = $found->service($key)?->source();
            }
            $sources['helpers'] = $found->helpersUrlSource();
            // Who serves each page for people: the helpers under their base, the site elsewhere
            $servedBy = [];
            foreach (SitePages::rows($found) as $row) {
                $row['page'] === SitePages::HELPERS || ($servedBy[$row['page']] = $row['servedBy']);
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
                    'logo' => $found->logoUrl(),
                    'helpers' => $found->helpersUrl(),
                    'economy' => $found->economy(),
                    'search' => $found->searchUrl(),
                    'searchApi' => $found->searchApi(),
                    'register' => $found->registerUrl(),
                    'offline' => $found->offlineUrl(),
                    'addresses' => $addresses,
                    'services' => $services,
                    'sources' => $sources,
                    'servedBy' => $servedBy,
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
            $found = $this->grid($grid) ?? throw NotFound::for("grid called '$grid'", 'grids');
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
                    'logo' => $found->logoUrl(),
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
            $found = $this->grid($grid) ?? throw NotFound::for("grid called '$grid'", 'grids');
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
     * and not given, told), the PHP extensions it needs, the files it reads (HealthCheck). The last check is given again with its time
     * (`checked`, `cached`), kept by the task of the grid that checks it in the background; `refresh` checks it now.
     */
    public function health(string $grid, bool $refresh = false): Response
    {
        $instance = self::path('health', null, $grid);
        try {
            $found = $this->grid($grid) ?? throw NotFound::for("grid called '$grid'", 'grids');
            $config = ($this->config)($found);
            $tasks = $this->scheduler?->directory() ?? $this->environment->layout->tasksRoot();
            $last = $refresh ? null : HealthCheck::last($this->environment, $found, $config);
            $check = $last === null ? HealthCheck::refresh($this->environment, $found, $config, $tasks) : $last;
        } catch (\Throwable $error) {
            return Response::problem(Problem::from($error, $instance));
        }

        return Response::document([
            'data' => [
                'type' => 'health',
                'id' => $found->nick(),
                'attributes' => $check + ['cached' => $last !== null],
                'links' => ['self' => $instance],
            ],
        ]);
    }

    /** One line of the health of a grid checked now (HealthCheck::one()), by its id: the last check keeps it. */
    public function healthCheck(string $grid, string $id): Response
    {
        $instance = self::path('health', null, $grid) . '/' . rawurlencode($id);
        try {
            $found = $this->grid($grid) ?? throw NotFound::for("grid called '$grid'", 'grids');
            $tasks = $this->scheduler?->directory() ?? $this->environment->layout->tasksRoot();
            $line = HealthCheck::one($this->environment, $found, ($this->config)($found), $tasks, $id)
                ?? throw NotFound::for("check '$id' of the health of $grid");
        } catch (\Throwable $error) {
            return Response::problem(Problem::from($error, $instance));
        }
        unset($line['id']);

        return Response::document([
            'data' => ['type' => 'health-checks', 'id' => $id, 'attributes' => $line, 'links' => ['self' => $instance]],
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
        $grid = $this->grid($nick) ?? throw new Failure("There is no grid called '$nick'", 404);
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
        $status = $this->scheduler?->status() ?? [];
        if (!in_array($name, [...array_column($status, 'id'), ...array_column($status, 'name')], true)) {
            return Response::problem(Problem::notFound("task called '$name'", $instance));
        }
        try {
            $result = $this->scheduler->run($name, true);
        } catch (\InvalidArgumentException $error) {
            // A name several grids have: the task of each is asked by its id (search-parse.demo)
            return Response::problem(Problem::from($error, $instance));
        }

        return Response::document(
            [
                'data' => [
                    'type' => 'runs',
                    'id' => $result->task,
                    'attributes' => [
                        'task' => $result->task,
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
     * Start, stop or restart an instance of this machine (its name, as `opensim start` takes it: a grid is its Robust), as the command
     * line does. `simulators`: the simulators of a grid too; `now`: no waiting for the visitors to leave. Refused, nothing done, when
     * this process is not the user that runs the instances (`instance-user-required`, InstanceAccess).
     *
     * @param array<string,mixed> $attributes
     */
    public function control(string $action, string $name, array $attributes): Response
    {
        $instance = "/instances/$name/$action";
        if (!in_array($action, ['start', 'stop', 'restart'], true)) {
            return Response::problem(Problem::notFound("action called '$action' (start, stop, restart)", $instance));
        }
        $refusal = ($this->instanceAccess)($this->environment);
        if ($refusal !== null) {
            return Response::problem(Problem::from(InvalidValue::because('instance-user-required', $refusal), $instance));
        }
        if (OpenSim::instance($this->environment, $name) === null && $this->grid($name) === null) {
            return Response::problem(Problem::notFound("instance called '$name'", $instance));
        }
        $simulators = Result::isTrue($attributes['simulators'] ?? false);
        $now = Result::isTrue($attributes['now'] ?? false);
        try {
            $results = match ($action) {
                'start' => OpenSim::start($name, $this->environment, $simulators),
                'stop' => OpenSim::stop($name, $now, $this->environment, $simulators),
                'restart' => OpenSim::restart($name, $now, $this->environment, $simulators),
            };
        } catch (\Throwable $error) {
            return Response::problem(Problem::from($error, $instance));
        }
        $rows = [];
        foreach ($results as $target => $result) {
            $failed = $result instanceof \OpenSim\Exceptions\InstanceFailed;
            $rows[] = [
                'instance' => (string) $target,
                'outcome' => $failed ? 'failed' : $result->value,
                'ok' => !$failed,
                'code' => $failed ? $result->reason : null,
                'detail' => $failed ? $result->getMessage() : null,
            ];
        }
        $ok = !in_array(false, array_column($rows, 'ok'), true);

        return Response::document(
            [
                'data' => ['type' => 'operations', 'id' => $name, 'attributes' => ['action' => $action, 'results' => $rows]],
                'links' => ['self' => $instance],
            ],
            $ok ? 200 : 500,
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
            // A grid is one of the grids served: of this machine, or given by an application
            $type === 'grids' => $this->grid($id),
            isset(self::RESOURCES[$type]) => $class::find($this->environment, $id),
            default => $class::find($this->gridConfig((string) $grid), $id),
        };
        if ($model === null) {
            $known = isset(self::RESOURCES[$type]) || isset(self::GRID_RESOURCES[$type]);

            return Response::problem(
                Problem::notFound(
                    $known ? rtrim($type, 's') . " called '$id'" : "kind of resource called '$type'",
                    $instance,
                    $known ? $type : null,
                ),
            );
        }

        return $model;
    }

    /** What lets the avatars of a grid of this machine back in: its tokens and its mails. */
    private function access(string $nick): AvatarAccess
    {
        $grid = $this->grid($nick) ?? throw NotFound::for("grid called '$nick'", 'grids');
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
     * @param array{token:string,expires:int,link:?string,sent:bool,email:string} $given
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
     * @param array{id:string,name:string,token:string,expires:int,link:?string,sent:bool,email:string} $given
     * @return array<string,mixed>
     */
    private static function given(string $type, array $given): array
    {
        return [
            'type' => $type,
            'id' => $given['id'],
            'attributes' => [
                'avatar' => $given['id'],
                'name' => $given['name'],
                'email' => $given['email'],
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
        $grid = $this->grid($nick) ?? throw NotFound::for("grid called '$nick'", 'grids');

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
        $found = $this->grid($grid) ?? throw NotFound::for("grid called '$grid'", 'grids');
        $resources = [];
        foreach ($found->guide(($this->config)($found)) as $category => $section) {
            foreach ($section['destinations'] as $destination) {
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
                        // What its card shows: its title (its region elsewhere), its label (its grid elsewhere), the name of its grid
                        'title' => $destination['title'],
                        'label' => $destination['label'],
                        'gridName' => $destination['gridName'],
                        // The pictures the engine finds, as asset UUIDs: of its parcel, of the first of its section that has one
                        'picture' => $destination['picture'],
                        'sectionPicture' => $section['picture'],
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
            if ($task['id'] === $name || $task['name'] === $name) {
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
        $id = $task['id'] ?? $task['name'];
        unset($task['id']);

        return ['type' => 'tasks', 'id' => $id, 'attributes' => $task, 'links' => ['self' => "/tasks/$id"]];
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

    /**
     * A pick, a classified or a group, from its row (Avatar::picks(), Avatar::classifieds(), Avatar::groups()).
     *
     * @param array<string,mixed> $row
     * @return array{type:string,id:string,attributes:array<string,mixed>}
     */
    private static function avatarItem(string $kind, array $row): array
    {
        if ($kind === 'groups' || $kind === 'posts') {
            return ['type' => $kind, 'id' => $row['id'], 'attributes' => array_diff_key($row, ['id' => true])];
        }
        $attributes = [
            'name' => (string) ($row['name'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'region' => $row['region'] ?? null,
            'parcel' => $row['parcel'] ?? null,
            'position' => $row['position'] ?? null,
            'teleport' => $row['teleport'] ?? null,
        ];

        return $kind === 'picks'
            ? [
                'type' => 'picks',
                'id' => (string) ($row['pickuuid'] ?? ''),
                'attributes' => $attributes + [
                    'gatekeeper' => ($row['gatekeeper'] ?? '') === '' ? null : (string) $row['gatekeeper'],
                    'picture' => $row['picture'] ?? null,
                ],
            ]
            : [
                'type' => 'classifieds',
                'id' => (string) ($row['classifieduuid'] ?? ''),
                'attributes' => $attributes + [
                    'picture' => $row['picture'] ?? null,
                    'created' => (int) ($row['creationdate'] ?? 0),
                    'expires' => (int) ($row['expirationdate'] ?? 0),
                ],
            ];
    }

    /**
     * The form of an avatar as its grid has it, for the meta of the item: the settings it needs, its `x-form` without what the grid
     * does not allow (a section, a field).
     *
     * @return array{settings:array<string,bool>,form:array<string,mixed>}
     */
    private static function avatarForm(Avatar $avatar): array
    {
        return ['settings' => $avatar->formSettings(), 'form' => Contract::formDocument('avatars', $avatar->form())];
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
