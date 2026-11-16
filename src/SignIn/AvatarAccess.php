<?php

declare(strict_types=1);

namespace OpenSim\SignIn;

use OpenSim\Config\GridConfig;
use OpenSim\Data\Connections;
use OpenSim\Exceptions\InvalidValue;
use OpenSim\Models\Avatar;
use OpenSim\Runtime\ClockInterface;
use OpenSim\Services\MailerInterface;

/**
 * What lets the people of a grid back in, from any site or the command line: a forgotten password and the confirmation of the email
 * of a new avatar, each a one-time token (AvatarTokens) mailed to the avatar with a link to the page that takes it, the way WordPress
 * does it for w4os. Whoever cannot mail it (no mail system, an administrator who gives it by hand) asks for the token instead.
 *
 * The link is the one the caller gives (`{token}` is replaced by the token, else `token=` is added to it), else the page of the grid:
 * `password` of `[GridInfoService]` for a password, `register` for a confirmation. The mail always gives the token as a code too.
 *
 *     $access = new AvatarAccess($config, 'Demo', 'Demo <no-reply@example.org>', $mailer, $clock);
 *     $access->requestPasswordReset('Ada Love', 'https://example.org/reset?key={token}');
 *     $access->resetPassword($token, 'new password');                    // the avatar, null for a token that is refused
 */
final class AvatarAccess
{
    /** Seconds a token of a forgotten password is valid: a day, as WordPress. */
    public const RESET_TTL = 86400;

    /** Seconds a token of the confirmation of an email is valid; the avatar may ask for another one later. */
    public const CONFIRMATION_TTL = 172800;

    private ?AvatarTokens $tokens = null;

    /**
     * @param string $grid the name of the grid, in the mails
     * @param string $from the sender of the mails, `Name <address>`
     */
    public function __construct(
        private readonly GridConfig $config,
        private readonly string $grid,
        private readonly string $from,
        private readonly MailerInterface $mailer,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * Give a token to choose a new password to the avatars a name or an email is, mailed to them unless `send` is false. Nothing
     * tells a name nobody has from one that has an avatar, but what is given back.
     *
     * @param string $login "First Last", "First.Last", or an email (every avatar that has it)
     * @param ?string $link the page that takes the token (see the class)
     * @param bool $send mail it; false gives the token to the caller and mails nothing
     * @return list<array{avatar:Avatar,token:string,expires:int,link:?string,sent:bool,email:string}> none when the name or the email is nobody's
     * @throws \InvalidArgumentException for a link that is not a web address
     * @throws \OpenSim\Exceptions\ChannelUnavailable when the databases cannot be reached
     */
    public function requestPasswordReset(string $login, ?string $link = null, bool $send = true): array
    {
        $given = [];
        foreach ($this->avatars($login) as $avatar) {
            $email = (string) ($avatar->detail()['email'] ?? '');
            if ($send && !Avatar::validEmail($email)) {
                continue;
            }
            $given[] = $this->give(
                $avatar,
                AvatarTokens::PASSWORD_RESET,
                self::RESET_TTL,
                $email,
                $link ?? $this->config->find('GridInfoService', 'password'),
                $send,
                sprintf(_('[%1$s] Reset the password of %2$s'), $this->grid, $avatar->name()),
                sprintf(
                    _('Someone asked to reset the password of the avatar %1$s on %2$s. If it was not you, ignore this mail: nothing changes.'),
                    $avatar->name(),
                    $this->grid,
                ),
                _('Choose a new password'),
            );
        }

        return $given;
    }

    /**
     * Change the password of the avatar a token was given to, once.
     *
     * @return ?Avatar the avatar, null when the token is not valid or expired
     * @throws \InvalidArgumentException for a password that is not one (checked before the token is taken)
     */
    public function resetPassword(string $token, string $password): ?Avatar
    {
        $wrong = Avatar::validatePassword($password);
        if ($wrong !== null) {
            throw InvalidValue::because('password-invalid', "the password is $wrong");
        }
        $given = $this->tokens()->redeem($token, AvatarTokens::PASSWORD_RESET);
        $avatar = $given === null ? null : Avatar::find($this->config, $given['avatar']);
        if ($avatar === null) {
            return null;
        }
        $avatar->update(['password' => $password]);

        return $avatar;
    }

    /** Whether an avatar waits for the confirmation of its email: it was made so, and has not confirmed it yet. */
    public function waitsForConfirmation(Avatar $avatar): bool
    {
        return $avatar->status() === 'pending';
    }

    /**
     * Give a token to confirm the email of an avatar, mailed to it unless `send` is false: an avatar that was just made inactive for
     * it, or one that waits for it (a new token replaces the last). An avatar an administrator disabled is never given one: its token
     * would activate it.
     *
     * @return array{avatar:Avatar,token:string,expires:int,link:?string,sent:bool,email:string}
     * @throws \InvalidArgumentException for an avatar that has no email, or a link that is not a web address
     */
    public function requestEmailConfirmation(Avatar $avatar, ?string $link = null, bool $send = true): array
    {
        $email = (string) ($avatar->detail()['email'] ?? '');
        if (!Avatar::validEmail($email)) {
            throw InvalidValue::because('email-required', "the avatar {$avatar->name()} has no email to confirm");
        }

        return $this->give(
            $avatar,
            AvatarTokens::EMAIL_CONFIRMATION,
            self::CONFIRMATION_TTL,
            $email,
            $link ?? $this->config->find('GridInfoService', 'register'),
            $send,
            sprintf(_('[%1$s] Confirm the email of %2$s'), $this->grid, $avatar->name()),
            sprintf(
                _('The avatar %1$s was made on %2$s with this email. Confirm it to activate the avatar. If it was not you, ignore this mail.'),
                $avatar->name(),
                $this->grid,
            ),
            _('Confirm the email'),
        );
    }

    /**
     * Give a token to confirm a new email of an avatar, mailed to that email unless `send` is false: the avatar keeps its email until
     * the new one is confirmed, as WordPress does for w4os. The avatar stays as active as it is.
     *
     * @return array{avatar:Avatar,token:string,expires:int,link:?string,sent:bool,email:string}
     * @throws \InvalidArgumentException for an email that is not one, or a link that is not a web address
     */
    public function requestEmailChange(Avatar $avatar, string $email, ?string $link = null, bool $send = true): array
    {
        $email = trim($email);
        if (!Avatar::validEmail($email)) {
            throw InvalidValue::because('email-invalid', "'$email' is not an email");
        }

        return $this->give(
            $avatar,
            AvatarTokens::EMAIL_CHANGE,
            self::CONFIRMATION_TTL,
            $email,
            $link ?? $this->config->find('GridInfoService', 'register'),
            $send,
            sprintf(_('[%1$s] Confirm the new email of %2$s'), $this->grid, $avatar->name()),
            sprintf(
                _('Someone asked to change the email of the avatar %1$s on %2$s to this one. Confirm it to make the change. If it was not you, ignore this mail: nothing changes.'),
                $avatar->name(),
                $this->grid,
            ),
            _('Confirm the email'),
        );
    }

    /**
     * Confirm the email of the avatar a token was given to, once. A new avatar that waits for it is activated (never one a manager
     * disabled or enabled meanwhile); an avatar that asked for a new email gets it, and stays as active as it is. A token for an email the avatar does not have any more is refused.
     *
     * @return ?Avatar the avatar, null when the token is not valid or expired
     */
    public function confirmEmail(string $token): ?Avatar
    {
        $tokens = $this->tokens();
        $change = $tokens->redeem($token, AvatarTokens::EMAIL_CHANGE);
        if ($change !== null) {
            $avatar = Avatar::find($this->config, $change['avatar']);
            $avatar?->update(['email' => $change['email']]);

            return $avatar === null ? null : Avatar::find($this->config, $avatar->id());
        }
        $given = $tokens->redeem($token, AvatarTokens::EMAIL_CONFIRMATION);
        $avatar = $given === null ? null : Avatar::find($this->config, $given['avatar']);
        // An avatar a manager disabled or enabled meanwhile does not wait any more: the token does nothing
        if (
            $avatar === null ||
            $avatar->status() !== 'pending' ||
            strtolower((string) ($avatar->detail()['email'] ?? '')) !== strtolower($given['email'])
        ) {
            return null;
        }
        $avatar->update(['active' => true]);
        $tokens->forget($avatar->id(), AvatarTokens::EMAIL_CONFIRMATION);

        return Avatar::find($this->config, $avatar->id());
    }

    /**
     * @return array{avatar:Avatar,token:string,expires:int,link:?string,sent:bool,email:string}
     */
    private function give(
        Avatar $avatar,
        string $purpose,
        int $ttl,
        string $email,
        ?string $link,
        bool $send,
        string $subject,
        string $text,
        string $action,
    ): array {
        $link = $link !== null && trim($link) !== '' ? trim($link) : null;
        if ($link !== null && preg_match('#^https?://[^\s"<>]+$#i', $link) !== 1) {
            throw InvalidValue::because('link-invalid', "'$link' is not a web address");
        }
        $token = $this->tokens()->issue($avatar->id(), $purpose, $ttl, $email);
        $expires = (int) $this->clock->time() + $ttl;
        $url =
            $link === null
                ? null
                : (str_contains($link, '{token}')
                    ? str_replace('{token}', $token, $link)
                    : $link . (str_contains($link, '?') ? '&' : '?') . 'token=' . $token);
        $sent = false;
        if ($send) {
            $html =
                '<p>' .
                self::html($text) .
                '</p>' .
                ($url !== null ? '<p><a href="' . self::html($url) . '">' . self::html($action) . '</a></p>' : '') .
                '<p>' .
                self::html($url !== null ? _('Or enter this code') : _('Enter this code')) .
                ": <code>$token</code></p><p>" .
                self::html(sprintf(_('It is valid until %s (UTC).'), gmdate('Y-m-d H:i', $expires))) .
                '</p>';
            $sent = $this->mailer->send($email, $subject, $html, $this->from);
            $sent || error_log("opensim: the mail to {$avatar->name()} ($purpose) could not be sent");
        }

        return [
            'avatar' => $avatar,
            'token' => $token,
            'expires' => $expires,
            'link' => $url,
            'sent' => $sent,
            'email' => $email,
        ];
    }

    /**
     * The avatars of this grid a name or an email is.
     *
     * @return list<Avatar>
     */
    private function avatars(string $login): array
    {
        $login = trim($login);
        if (!str_contains($login, '@')) {
            $avatar = AvatarName::parse($login);

            return $avatar === null || $avatar->grid !== null
                ? []
                : array_values(array_filter([Avatar::find($this->config, $avatar->full())]));
        }
        $accounts = Connections::pdo($this->config, 'UserAccountService');
        if ($accounts === null || !Avatar::validEmail($login)) {
            return [];
        }
        $ids =
            $accounts->get_col('SELECT PrincipalID FROM UserAccounts WHERE LOWER(Email) = :email ORDER BY Created', [
                'email' => strtolower($login),
            ]) ?:
            [];

        return array_values(array_filter(array_map(fn(string $id): ?Avatar => Avatar::find($this->config, $id), $ids)));
    }

    private function tokens(): AvatarTokens
    {
        return $this->tokens ??= AvatarTokens::for($this->config, $this->clock);
    }

    private static function html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
