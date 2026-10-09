<?php
declare(strict_types=1);

namespace P360;

/** Role -> permission matrix. Enforced on the server for every request. */
final class Perms
{
    public const ROLES = ['ADMIN', 'PAYROLL_ADMIN', 'HR_ADMIN', 'MANAGER', 'EMPLOYEE'];

    private const MATRIX = [
        'ADMIN' => ['*'],
        'PAYROLL_ADMIN' => [
            'employees.read', 'employees.write', 'employees.sensitive',
            'compensation.read', 'components.read', 'components.manage', 'assignments.write',
            'taxrules.read', 'taxrules.manage', 'schedules.read', 'schedules.manage', 'entities.read', 'entities.manage',
            'payroll.read', 'payroll.run', 'payroll.approve', 'payroll.process',
            'payslips.read', 'payslips.send', 'reports.read', 'audit.read',
        ],
        'HR_ADMIN' => [
            'employees.read', 'employees.write', 'employees.sensitive',
            'compensation.read', 'compensation.write', 'components.read', 'assignments.write',
            'schedules.read', 'entities.read',
        ],
        'MANAGER' => ['team.read'],
        'EMPLOYEE' => [],
    ];

    public static function forRole(string $role): array
    {
        return self::MATRIX[$role] ?? [];
    }

    public static function roleCan(string $role, string $perm): bool
    {
        $p = self::forRole($role);
        return in_array('*', $p, true) || in_array($perm, $p, true);
    }
}

final class Auth
{
    private static ?array $user = null;
    private static bool $resolved = false;
    private static ?string $token = null;

    public static function reset(): void
    {
        self::$user = null;
        self::$resolved = false;
        self::$token = null;
    }

    /** Act as a given user without a token (setup wizard, CLI, tests). */
    public static function actAs(?array $user): void
    {
        self::$user = $user;
        self::$resolved = true;
    }

    public static function currentToken(): ?string
    {
        return self::$token;
    }

    public static function passwordOk(string $pw): bool
    {
        return mb_strlen($pw) >= 10 && mb_strlen($pw) <= 200 && preg_match('/[A-Za-z]/', $pw) && preg_match('/\d/', $pw);
    }

    public static function userCount(): int
    {
        return (int) Db::val('SELECT COUNT(*) FROM users');
    }

    public static function createUser(string $email, string $name, string $password, string $role, ?int $employeeId = null): int
    {
        if (!in_array($role, Perms::ROLES, true)) {
            throw new ApiError(422, 'validation_failed', 'Unknown role.', ['role' => 'Please choose a valid role.']);
        }
        if (!self::passwordOk($password)) {
            throw new ApiError(422, 'validation_failed', 'Password too weak.', ['password' => 'Use at least 10 characters including a letter and a number.']);
        }
        if (Db::one('SELECT id FROM users WHERE email = ?', [$email])) {
            throw new ApiError(409, 'duplicate', 'A user with this email already exists.', ['email' => 'This email is already in use.']);
        }
        return Db::insert('users', [
            'email' => $email,
            'name' => $name,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role' => $role,
            'employee_id' => $employeeId,
            'active' => 1,
            'created_at' => Db::now(),
        ]);
    }

    /** Returns [token, user]. Throttled per email and per IP. */
    public static function login(string $email, string $password, string $ip, string $ua): array
    {
        $window = (int) Config::get('login_window_minutes', 15) * 60;
        $max = (int) Config::get('login_max_attempts', 5);
        $since = time() - $window;
        $failsEmail = (int) Db::val('SELECT COUNT(*) FROM login_attempts WHERE ok = 0 AND at > ? AND email = ?', [$since, strtolower($email)]);
        $failsIp = (int) Db::val('SELECT COUNT(*) FROM login_attempts WHERE ok = 0 AND at > ? AND ip = ?', [$since, $ip]);
        if ($failsEmail >= $max || $failsIp >= $max * 4) {
            throw new ApiError(429, 'too_many_attempts', 'Too many failed sign-in attempts. Please wait a few minutes and try again.');
        }

        $row = Db::one('SELECT * FROM users WHERE email = ?', [strtolower($email)]);
        // Always run a hash check so timing does not reveal whether the email exists.
        $hash = $row['password_hash'] ?? '$2y$10$abcdefghijklmnopqrstuuJ0vQ1F9m5kq2X3Nn9eJt0Yd4bYw1kGe';
        $ok = password_verify($password, $hash) && $row && (int) $row['active'] === 1;
        Db::insert('login_attempts', ['email' => strtolower($email), 'ip' => $ip, 'at' => time(), 'ok' => $ok ? 1 : 0]);
        if (!$ok) {
            throw new ApiError(401, 'invalid_credentials', 'The email or password is incorrect.');
        }
        Db::run('DELETE FROM login_attempts WHERE at < ?', [time() - 86400]);

        $token = bin2hex(random_bytes(32));
        $hours = (int) Config::get('session_hours', 8);
        Db::insert('sessions', [
            'token_hash' => hash('sha256', $token),
            'user_id' => (int) $row['id'],
            'created_at' => Db::now(),
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + $hours * 3600),
            'ip' => $ip,
            'user_agent' => mb_substr($ua, 0, 200),
        ]);
        Db::update('users', (int) $row['id'], ['last_login_at' => Db::now()]);
        Db::run('DELETE FROM sessions WHERE expires_at < ?', [Db::now()]);
        self::$user = self::publicUser($row);
        self::$resolved = true;
        self::$token = $token;
        return [$token, self::$user];
    }

    public static function logout(?string $token): void
    {
        if ($token) {
            Db::run('DELETE FROM sessions WHERE token_hash = ?', [hash('sha256', $token)]);
        }
    }

    private static function publicUser(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'email' => $row['email'],
            'name' => $row['name'],
            'role' => $row['role'],
            'employee_id' => $row['employee_id'] !== null ? (int) $row['employee_id'] : null,
            'active' => (int) $row['active'] === 1,
        ];
    }

    /** Resolve the signed-in user from the bearer token (null when anonymous). */
    public static function resolve(?string $token): ?array
    {
        if (self::$resolved) {
            return self::$user;
        }
        self::$resolved = true;
        self::$user = null;
        self::$token = $token;
        if (!$token) {
            return null;
        }
        $row = Db::one(
            'SELECT u.* FROM sessions s JOIN users u ON u.id = s.user_id WHERE s.token_hash = ? AND s.expires_at > ? AND u.active = 1',
            [hash('sha256', $token), Db::now()]
        );
        if ($row) {
            self::$user = self::publicUser($row);
        }
        return self::$user;
    }

    public static function user(): ?array
    {
        return self::$user;
    }

    public static function requireUser(): array
    {
        if (!self::$user) {
            throw new ApiError(401, 'unauthenticated', 'Please sign in to continue.');
        }
        return self::$user;
    }

    public static function can(string $perm): bool
    {
        return self::$user !== null && Perms::roleCan(self::$user['role'], $perm);
    }

    public static function require(string $perm): array
    {
        $u = self::requireUser();
        if (!Perms::roleCan($u['role'], $perm)) {
            throw new ApiError(403, 'forbidden', 'You do not have permission to do this.');
        }
        return $u;
    }

    public static function permissions(array $user): array
    {
        return Perms::forRole($user['role']);
    }

    public static function uid(): ?int
    {
        return self::$user['id'] ?? null;
    }
}

/** Append-only audit trail of sensitive events. */
final class Audit
{
    private static ?string $ip = null;

    public static function setIp(string $ip): void
    {
        self::$ip = $ip;
    }

    private const SENSITIVE = ['tax_id', 'tax_id_enc', 'bank_account', 'bank_account_enc', 'password', 'password_hash', 'logo', 'photo'];

    public static function log(string $action, ?string $entityType = null, string|int|null $entityId = null, mixed $before = null, mixed $after = null): void
    {
        $u = Auth::user();
        Db::insert('audit_log', [
            'at' => Db::now(),
            'user_id' => $u['id'] ?? null,
            'user_email' => $u['email'] ?? null,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId === null ? null : (string) $entityId,
            'before_json' => $before === null ? null : json_encode(self::scrub($before), JSON_UNESCAPED_UNICODE),
            'after_json' => $after === null ? null : json_encode(self::scrub($after), JSON_UNESCAPED_UNICODE),
            'ip' => self::$ip,
        ]);
    }

    private static function scrub(mixed $v): mixed
    {
        if (!is_array($v)) {
            return $v;
        }
        $out = [];
        foreach ($v as $k => $val) {
            $out[$k] = (is_string($k) && in_array($k, self::SENSITIVE, true)) ? '[redacted]' : self::scrub($val);
        }
        return $out;
    }
}
