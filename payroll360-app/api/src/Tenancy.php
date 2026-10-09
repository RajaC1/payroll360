<?php
declare(strict_types=1);

namespace P360;

use PDO;

/**
 * Self-service provisioning (multi-tenant mode). Off unless PAYSLIP360_MULTITENANT=1, in which case a single
 * running app can serve many separate companies, each with its own fully isolated SQLite database, encryption
 * key and outbox folder (the same isolation as running one deployment per company by hand, just automated).
 *
 * This never changes single-tenant behaviour: Config/Db work exactly as before unless a tenant has been
 * resolved and applied for the current request (see index.php).
 *
 * A small separate "control" database (never mixed with any tenant's own data) tracks the directory of
 * tenants, pending sign-ups awaiting email verification, and signup rate limiting. It has its own connection,
 * independent of the per-tenant Db class.
 */
final class ControlDb
{
    private static ?PDO $pdo = null;

    public static function enabled(): bool
    {
        return getenv('PAYSLIP360_MULTITENANT') === '1';
    }

    private static function path(): string
    {
        $p = getenv('PAYSLIP360_CONTROL_DB');
        return $p !== false && $p !== '' ? $p : Config::root() . '/api/data/control/control.sqlite';
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $path = self::path();
            @mkdir(dirname($path), 0775, true);
            $pdo = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA busy_timeout = 8000');
            $pdo->exec('PRAGMA journal_mode = WAL');
            self::$pdo = $pdo;
        }
        return self::$pdo;
    }

    public static function migrate(): void
    {
        $pdo = self::pdo();
        $pdo->exec('CREATE TABLE IF NOT EXISTS tenants (
            id INTEGER PRIMARY KEY, slug TEXT NOT NULL UNIQUE, company_name TEXT NOT NULL, admin_email TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT \'active\', plan TEXT NOT NULL DEFAULT \'free\',
            db_path TEXT NOT NULL, key_path TEXT NOT NULL, outbox_dir TEXT NOT NULL,
            created_at TEXT NOT NULL, created_ip TEXT
        )');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tenants_status ON tenants(status)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS signup_attempts (id INTEGER PRIMARY KEY, ip TEXT NOT NULL, at INTEGER NOT NULL)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_signup_ip_at ON signup_attempts(ip, at)');
        // Sign-ups waiting for the email link. Only a hash of the password is kept, never the password itself.
        $pdo->exec('CREATE TABLE IF NOT EXISTS signup_requests (
            id INTEGER PRIMARY KEY, token_hash TEXT NOT NULL UNIQUE, company_name TEXT NOT NULL, slug_requested TEXT,
            admin_name TEXT NOT NULL, admin_email TEXT NOT NULL, password_hash TEXT NOT NULL,
            created_at TEXT NOT NULL, expires_at TEXT NOT NULL, used_at TEXT, created_ip TEXT
        )');
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $r = $st->fetch();
        return $r === false ? null : $r;
    }

    public static function all(string $sql, array $params = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    public static function val(string $sql, array $params = []): mixed
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function run(string $sql, array $params = []): void
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
    }

    public static function insert(string $table, array $row): int
    {
        $cols = array_keys($row);
        $sql = "INSERT INTO {$table} (" . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
        self::run($sql, array_values($row));
        return (int) self::pdo()->lastInsertId();
    }
}

/** Resolves which tenant a request belongs to, and points Config/Db at that tenant's own database. */
final class Tenancy
{
    private const RESERVED = ['www', 'api', 'app', 'admin', 'mail', 'ftp', 'control', 'static', 'cdn', 'assets',
        'payroll360', 'payslip360', 'signup', 'login', 'help', 'support', 'status', 'blog', 'demo', 'test', 'localhost'];

    /** Reads the tenant slug from an explicit header or ?tenant= query param (useful before DNS/subdomains
     *  exist - see app/api.js, which turns a ?tenant= on the page URL into this header automatically), or
     *  otherwise the Host subdomain. */
    public static function slugFromRequest(Req $req): ?string
    {
        $slug = $req->header('x-tenant-slug') ?? $req->q('tenant');
        if ($slug === null) {
            $host = strtolower((string) $req->header('host'));
            $host = (string) preg_replace('/:\d+$/', '', $host);
            $base = strtolower((string) getenv('PAYSLIP360_BASE_DOMAIN'));
            if ($base !== '' && str_ends_with($host, '.' . $base)) {
                $slug = substr($host, 0, -(strlen($base) + 1));
            }
        }
        $slug = $slug !== null ? strtolower(trim($slug)) : null;
        return ($slug !== null && $slug !== '') ? $slug : null;
    }

    /** Looks up the tenant for this request. Returns null if this request is not addressed to any tenant
     *  (for example, the base/signup domain, or no PAYSLIP360_BASE_DOMAIN / X-Tenant-Slug at all). */
    public static function resolve(Req $req): ?array
    {
        $slug = self::slugFromRequest($req);
        if ($slug === null) {
            return null;
        }
        return ControlDb::one('SELECT * FROM tenants WHERE slug = ?', [$slug]);
    }

    /** Points Config (and therefore Db, on its next use) at this tenant's own isolated database. */
    public static function apply(array $tenant): void
    {
        Config::override([
            'db_path' => $tenant['db_path'],
            'key_path' => $tenant['key_path'],
            'outbox_dir' => $tenant['outbox_dir'],
        ]);
        Db::reset();
    }

    public static function isReserved(string $slug): bool
    {
        return in_array($slug, self::RESERVED, true);
    }

    public static function slugify(string $s): string
    {
        $s = strtolower(trim($s));
        $s = (string) preg_replace('/[^a-z0-9]+/', '-', $s);
        $s = trim($s, '-');
        $s = (string) preg_replace('/-{2,}/', '-', $s);
        if ($s === '' || !preg_match('/^[a-z]/', $s)) {
            $s = 'company-' . $s;
        }
        return mb_substr($s, 0, 40);
    }
}

/**
 * The self-service sign-up workflow, in two steps:
 *   1. request(): the signer's details are checked and held, and a verification link is emailed to them.
 *      No workspace exists yet.
 *   2. verify(): the link creates the company's isolated workspace and its administrator.
 * This stops anyone from creating workspaces with addresses they don't control.
 */
final class Tenants
{
    private const VERIFY_HOURS = 48;

    private static function uniqueSlug(string $base): string
    {
        $slug = $base;
        $n = 2;
        while (ControlDb::val('SELECT id FROM tenants WHERE slug = ?', [$slug]) || Tenancy::isReserved($slug)) {
            $slug = mb_substr($base, 0, 36) . '-' . $n;
            $n++;
            if ($n > 500) {
                // Astronomically unlikely, but never loop forever.
                $slug = $base . '-' . bin2hex(random_bytes(3));
                break;
            }
        }
        return $slug;
    }

    private static function checkRateLimit(string $ip): void
    {
        $since = time() - 3600;
        $count = (int) ControlDb::val('SELECT COUNT(*) FROM signup_attempts WHERE ip = ? AND at > ?', [$ip, $since]);
        if ($count >= 5) {
            throw new ApiError(429, 'too_many_attempts', 'Too many sign-ups from this location. Please try again in a hour, or contact us.');
        }
        ControlDb::insert('signup_attempts', ['ip' => $ip, 'at' => time()]);
        ControlDb::run('DELETE FROM signup_attempts WHERE at < ?', [time() - 86400]);
    }

    public static function isSlugAvailable(string $slug): bool
    {
        $slug = strtolower(trim($slug));
        if ($slug === '' || !preg_match('/^[a-z][a-z0-9-]{1,39}$/', $slug) || Tenancy::isReserved($slug)) {
            return false;
        }
        return ControlDb::val('SELECT id FROM tenants WHERE slug = ?', [$slug]) === null;
    }

    /** Step 1. Holds the sign-up and emails the verification link. The link's base is the site's public_url. */
    public static function request(array $in, string $ip): array
    {
        if (!ControlDb::enabled()) {
            throw new ApiError(404, 'not_found', 'Self-service sign-up is not enabled on this server.');
        }
        $base = rtrim((string) Config::get('public_url', ''), '/');
        if ($base === '') {
            throw new ApiError(503, 'not_configured', 'Sign-up is not finished on this server yet. Please try again later.');
        }
        self::checkRateLimit($ip);

        $v = new Validator($in);
        $companyName = $v->str('company_name', true, 120);
        $adminName = $v->str('admin_name', true, 100);
        $adminEmail = $v->email('admin_email', true);
        $password = $v->str('admin_password', true, 200);
        $requestedSlug = $v->str('slug', false, 40);
        // Honeypot: a real signer never fills this hidden field. A bot filling every input usually will.
        if (!empty($in['website'])) {
            $v->fail('company_name', 'Please try again.');
        }
        if ($password !== null && !Auth::passwordOk($password)) {
            $v->fail('admin_password', 'Use at least 10 characters including a letter and a number.');
        }
        $v->done();

        $slugBase = $requestedSlug ? Tenancy::slugify($requestedSlug) : Tenancy::slugify((string) $companyName);
        if ($requestedSlug && (!preg_match('/^[a-z][a-z0-9-]{1,39}$/', $slugBase) || Tenancy::isReserved($slugBase))) {
            throw new ApiError(422, 'validation_failed', 'Please correct the highlighted fields.', ['slug' => 'Choose a workspace address of lowercase letters, numbers and hyphens.']);
        }

        $token = bin2hex(random_bytes(32));
        ControlDb::insert('signup_requests', [
            'token_hash' => hash('sha256', $token),
            'company_name' => $companyName,
            'slug_requested' => $requestedSlug ? $slugBase : null,
            'admin_name' => $adminName,
            'admin_email' => $adminEmail,
            'password_hash' => password_hash((string) $password, PASSWORD_DEFAULT),
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + self::VERIFY_HOURS * 3600),
            'created_ip' => $ip,
        ]);

        $link = $base . '/signup.html?verify=' . $token;
        $html = "<!DOCTYPE html><html><body style='font-family:Arial,sans-serif;color:#1f2937;padding:24px'>"
            . '<h2 style="color:#01442d">Confirm your Payroll360 workspace</h2>'
            . '<p>Someone asked to create a Payroll360 workspace for <b>' . htmlspecialchars((string) $companyName, ENT_QUOTES) . '</b> with this email address. If that was you, confirm within ' . self::VERIFY_HOURS . ' hours:</p>'
            . '<p><a href="' . htmlspecialchars($link, ENT_QUOTES) . '" style="background:#14a841;color:#fff;padding:10px 16px;border-radius:8px;text-decoration:none">Confirm and create workspace</a></p>'
            . '<p style="color:#6b7280;font-size:12px">If you did not ask for this, ignore this email and nothing will be created.</p></body></html>';
        try {
            Mailer::sendPlain((string) $adminEmail, 'Confirm your Payroll360 workspace', $html, 'Payroll360');
        } catch (\Throwable) {
            throw new ApiError(502, 'delivery_failed', 'We could not send the confirmation email. Please try again in a few minutes.');
        }
        return ['ok' => true, 'message' => 'Check your email for a confirmation link. It is valid for ' . self::VERIFY_HOURS . ' hours.'];
    }

    /** Step 2. The emailed link. Each link works once and creates the workspace. */
    public static function verify(string $token): array
    {
        if (!ControlDb::enabled()) {
            throw new ApiError(404, 'not_found', 'Self-service sign-up is not enabled on this server.');
        }
        $row = ControlDb::one('SELECT * FROM signup_requests WHERE token_hash = ?', [hash('sha256', $token)]);
        if (!$row || $row['used_at'] !== null || $row['expires_at'] < gmdate('Y-m-d\TH:i:s\Z')) {
            throw new ApiError(422, 'invalid_token', 'This confirmation link is invalid or has expired. Please sign up again.');
        }
        // Claim the link before doing any work, so two clicks cannot create two workspaces.
        $claim = ControlDb::pdo()->prepare('UPDATE signup_requests SET used_at = ? WHERE id = ? AND used_at IS NULL');
        $claim->execute([gmdate('Y-m-d\TH:i:s\Z'), (int) $row['id']]);
        if ($claim->rowCount() !== 1) {
            throw new ApiError(422, 'invalid_token', 'This confirmation link has already been used.');
        }

        try {
            $slug = self::uniqueSlug($row['slug_requested'] ?: Tenancy::slugify((string) $row['company_name']));
            $tenant = self::build($slug, $row);
        } catch (\Throwable $e) {
            // Give the link back so the signer can try again, then report the real error.
            ControlDb::run('UPDATE signup_requests SET used_at = NULL WHERE id = ?', [(int) $row['id']]);
            throw $e;
        }
        return ['tenant' => $tenant];
    }

    /** Creates the tenant's own database and its administrator. The password hash from sign-up is stored as-is. */
    private static function build(string $slug, array $row): array
    {
        $companyName = (string) $row['company_name'];
        $adminEmail = (string) $row['admin_email'];
        $tenantsRoot = getenv('PAYSLIP360_TENANTS_DIR') ?: (Config::root() . '/api/data/tenants');
        $dir = $tenantsRoot . '/' . $slug;
        if (is_dir($dir)) {
            // Extremely unlikely given the uniqueness check above, but never provision into an existing folder.
            throw new ApiError(409, 'duplicate', 'That workspace address was just taken. Please try again.');
        }
        @mkdir($dir, 0770, true);
        $dbPath = $dir . '/payslip360.sqlite';
        $keyPath = $dir . '/app.key';
        $outboxDir = $dir . '/outbox';

        $baseDomain = getenv('PAYSLIP360_BASE_DOMAIN') ?: null;
        $url = $baseDomain ? "https://{$slug}.{$baseDomain}/" : null;

        // Build the new tenant's own database using the exact same code every other Payroll360 install uses
        // (Schema::migrate, ReferenceData::seedComponents) - just pointed at a fresh path. The welcome email is
        // sent from inside this same tenant context, so a test-mode .eml lands in that tenant's own outbox.
        $baseCfg = Config::all(); // the control plane / shared site config, restored once the new tenant is built
        Tenancy::apply(['db_path' => $dbPath, 'key_path' => $keyPath, 'outbox_dir' => $outboxDir]);
        try {
            Schema::migrate();
            Db::insert('users', [
                'email' => $adminEmail,
                'name' => (string) $row['admin_name'],
                'password_hash' => (string) $row['password_hash'],
                'role' => 'ADMIN',
                'employee_id' => null,
                'active' => 1,
                'created_at' => Db::now(),
            ]);
            ReferenceData::seedComponents();
            try {
                $html = "<!DOCTYPE html><html><body style='font-family:Arial,sans-serif;color:#1f2937;padding:24px'>"
                    . '<h2 style="color:#01442d">Welcome to Payroll360</h2>'
                    . '<p>Your workspace <b>' . htmlspecialchars($companyName, ENT_QUOTES) . '</b> is ready'
                    . ($url ? " at <a href=\"{$url}\">{$url}</a>." : '.')
                    . '</p><p>Sign in with the email and password you chose at sign-up.</p></body></html>';
                Mailer::sendPlain($adminEmail, 'Your Payroll360 workspace is ready', $html, 'Payroll360');
            } catch (\Throwable) {
                // A failed welcome email should never undo a workspace that was created successfully.
            }
        } finally {
            Config::override($baseCfg);
            Db::reset();
        }

        $tenantId = ControlDb::insert('tenants', [
            'slug' => $slug, 'company_name' => $companyName, 'admin_email' => $adminEmail, 'status' => 'active', 'plan' => 'free',
            'db_path' => $dbPath, 'key_path' => $keyPath, 'outbox_dir' => $outboxDir, 'created_at' => gmdate('Y-m-d\TH:i:s\Z'), 'created_ip' => (string) $row['created_ip'],
        ]);

        return ['id' => $tenantId, 'slug' => $slug, 'company_name' => $companyName, 'url' => $url];
    }

    public static function list(): array
    {
        if (!ControlDb::enabled()) {
            return [];
        }
        return ControlDb::all('SELECT id, slug, company_name, admin_email, status, plan, created_at FROM tenants ORDER BY created_at DESC');
    }
}
