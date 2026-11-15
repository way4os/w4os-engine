<?php

declare(strict_types=1);

namespace OpenSim\SignIn;

use OpenSim\Config\GridConfig;
use OpenSim\Data\Connections;
use OpenSim\Data\OSPDO;
use OpenSim\Models\Avatar as AvatarModel;
use OpenSim\Provisioning\AccountWriter;
use OpenSim\Runtime\SystemClock;

/**
 * Signs an avatar of the grid in with the databases of its services: the account by its name ("First Last", "First.Last") or its
 * email (UserAccounts), the password checked against the hash Robust keeps (auth). Opens no session, needs no running Robust.
 *
 * An account that is not active is refused, with a reason once its password is right. An email several accounts share signs in the
 * first of them (the oldest) whose password it is.
 */
final class DatabaseAuthenticator implements AvatarAuthenticator
{
    public function __construct(private readonly GridConfig $config) {}

    public function authenticate(string $name, string $password): SignInResult
    {
        $name = trim($name);
        if ($name === '' || $password === '') {
            return SignInResult::refused();
        }
        if (str_contains($name, '@')) {
            if (!AvatarModel::validEmail($name)) {
                return SignInResult::refused();
            }
            $where = 'LOWER(Email) = :email';
            $params = ['email' => strtolower($name)];
        } else {
            $avatar = AvatarName::parse($name);
            if ($avatar === null || $avatar->grid !== null) {
                return SignInResult::refused();
            }
            // Case-insensitive, as the databases of Robust compare names
            $where = 'LOWER(FirstName) = :first AND LOWER(LastName) = :last';
            $params = ['first' => strtolower($avatar->first), 'last' => strtolower($avatar->last)];
        }

        $accounts = Connections::pdo($this->config, 'UserAccountService');
        $auth = Connections::pdo($this->config, 'AuthenticationService');
        if ($accounts === null || $auth === null) {
            return SignInResult::unavailable(_('The database of the accounts cannot be reached'));
        }
        try {
            $rows = $accounts->get_results(
                'SELECT PrincipalID, FirstName, LastName, Email, UserLevel, UserFlags, UserTitle, active FROM UserAccounts WHERE ' .
                    $where .
                    ' ORDER BY Created, PrincipalID',
                $params,
                \PDO::FETCH_ASSOC,
            );
            foreach ($rows ?: [] as $row) {
                if (!self::matches($auth, (string) $row['PrincipalID'], $password)) {
                    continue;
                }
                if ((int) ($row['active'] ?? 1) !== 1) {
                    return self::waitsForConfirmation($auth, (string) $row['PrincipalID'])
                        ? SignInResult::refused(
                            _('The account is not active: its email is not confirmed yet'),
                            'email-unconfirmed',
                        )
                        : SignInResult::refused(_('The account is not active'), 'account-disabled');
                }

                return SignInResult::granted(
                    new Avatar(
                        id: (string) $row['PrincipalID'],
                        firstName: (string) $row['FirstName'],
                        lastName: (string) $row['LastName'],
                        email: (string) ($row['Email'] ?? ''),
                        level: (int) ($row['UserLevel'] ?? 0),
                        flags: (int) ($row['UserFlags'] ?? 0),
                        title: (string) ($row['UserTitle'] ?? ''),
                    ),
                );
            }
        } catch (\PDOException $e) {
            return SignInResult::unavailable($e->getMessage());
        }

        return SignInResult::refused();
    }

    public function opensSession(): bool
    {
        return false;
    }

    /** Whether an account that is not active waits for the confirmation of its email, rather than being disabled by a manager. */
    private static function waitsForConfirmation(OSPDO $auth, string $id): bool
    {
        try {
            return (new AvatarTokens($auth, new SystemClock()))->pending($id, AvatarTokens::EMAIL_CONFIRMATION);
        } catch (\Throwable) {
            return false;
        }
    }

    /** Whether the password is the one Robust keeps for the account: MD5(MD5(password) + ':' + salt). */
    private static function matches(OSPDO $auth, string $id, string $password): bool
    {
        $row = $auth->get_row(
            'SELECT passwordHash, passwordSalt FROM auth WHERE UUID = :id',
            ['id' => $id],
            \PDO::FETCH_ASSOC,
        );

        return is_array($row) &&
            (string) $row['passwordHash'] !== '' &&
            hash_equals(
                strtolower((string) $row['passwordHash']),
                AccountWriter::passwordHash($password, (string) $row['passwordSalt']),
            );
    }
}
