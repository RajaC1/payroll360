<?php
declare(strict_types=1);

namespace P360;

use PDO;
use Throwable;

/** Error that is safe to show to the API client. */
final class ApiError extends \RuntimeException
{
    public function __construct(public int $status, public string $errCode, string $message, public array $fields = [])
    {
        parent::__construct($message);
    }
}

/** Configuration: defaults < payslip-config.php < environment. */
final class Config
{
    private static ?array $cfg = null;

    public static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    public static function all(): array
    {
        if (self::$cfg !== null) {
            return self::$cfg;
        }
        $data = dirname(__DIR__) . '/data';
        $c = [
            'db_path' => $data . '/payslip360.sqlite',
            'key_path' => $data . '/app.key',
            'outbox_dir' => $data . '/outbox',
            'session_hours' => 8,
            'login_max_attempts' => 5,
            'login_window_minutes' => 15,
            'mail_transport' => 'smtp', // smtp | mail | file
            'smtp_host' => 'smtp.office365.com',
            'smtp_port' => 587,
            'smtp_user' => '',
            'smtp_pass' => '',
            'from_address' => '',
            'public_url' => '', // e.g. https://payroll.yourdomain.com - base of password reset links
            'debug' => false,
        ];
        $file = getenv('PAYSLIP360_CONFIG') ?: self::root() . '/payslip-config.php';
        if (is_file($file)) {
            $fileCfg = require $file;
            if (is_array($fileCfg)) {
                $c = array_merge($c, $fileCfg);
            }
        }
        $env = [
            'db_path' => 'PAYSLIP360_DB',
            'key_path' => 'PAYSLIP360_KEY_PATH',
            'outbox_dir' => 'PAYSLIP360_OUTBOX',
            'mail_transport' => 'PAYSLIP360_MAIL_TRANSPORT',
            'smtp_host' => 'PAYSLIP360_SMTP_HOST',
            'smtp_user' => 'PAYSLIP360_SMTP_USER',
            'smtp_pass' => 'PAYSLIP360_SMTP_PASS',
            'from_address' => 'PAYSLIP360_FROM_ADDRESS',
            'public_url' => 'PAYSLIP360_PUBLIC_URL',
        ];
        foreach ($env as $key => $name) {
            $v = getenv($name);
            if ($v !== false && $v !== '') {
                $c[$key] = $v;
            }
        }
        if (getenv('PAYSLIP360_DEBUG') === '1') {
            $c['debug'] = true;
        }
        return self::$cfg = $c;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::all()[$key] ?? $default;
    }

    /** Multi-tenant mode only (see Tenancy::apply): layers per-request overrides (db_path etc.) on top of the
     *  normal defaults/file/env config, for the lifetime of the current request. Single-tenant deployments
     *  never call this, so their behaviour is completely unchanged. */
    public static function override(array $kv): void
    {
        self::all();
        foreach ($kv as $k => $v) {
            self::$cfg[$k] = $v;
        }
    }
}

/** Decimal-safe money. Amounts are integers in minor units (cents). */
final class Money
{
    private const EXP = ['JPY' => 0, 'KRW' => 0, 'VND' => 0, 'CLP' => 0, 'BHD' => 3, 'KWD' => 3, 'OMR' => 3, 'JOD' => 3, 'TND' => 3];

    public static function exp(string $cur): int
    {
        return self::EXP[strtoupper($cur)] ?? 2;
    }

    /** Strict decimal parse ("5500.50"). Returns null when invalid. No floats are ever used for money. */
    public static function parse(mixed $v, string $cur): ?int
    {
        $e = self::exp($cur);
        if (is_int($v)) {
            $s = (string) $v;
        } elseif (is_float($v)) {
            $s = number_format($v, $e, '.', '');
        } elseif (is_string($v)) {
            $s = trim($v);
        } else {
            return null;
        }
        $pattern = $e === 0 ? '/^(-?)(\d{1,12})$/' : '/^(-?)(\d{1,12})(?:\.(\d{1,' . $e . '}))?$/';
        if (!preg_match($pattern, $s, $m)) {
            return null;
        }
        $minor = (int) ($m[2] . str_pad($m[3] ?? '', $e, '0'));
        return $m[1] === '-' ? -$minor : $minor;
    }

    public static function fmt(int $minor, string $cur): string
    {
        $e = self::exp($cur);
        $neg = $minor < 0;
        $s = str_pad((string) abs($minor), $e + 1, '0', STR_PAD_LEFT);
        if ($e > 0) {
            $s = substr($s, 0, -$e) . '.' . substr($s, -$e);
        }
        return ($neg ? '-' : '') . $s;
    }

    /** Integer division rounding half away from zero. */
    public static function divRound(int $num, int $den): int
    {
        if ($den === 0) {
            throw new \DivisionByZeroError('Division by zero in money calculation');
        }
        $neg = ($num < 0) !== ($den < 0);
        $n = abs($num);
        $d = abs($den);
        $q = intdiv($n, $d);
        if (($n % $d) * 2 >= $d) {
            $q++;
        }
        return $neg ? -$q : $q;
    }

    public static function mulDiv(int $a, int $n, int $d): int
    {
        return self::divRound($a * $n, $d);
    }

    /** Percentage in basis points (100 bp = 1%). */
    public static function pct(int $amount, int $bp): int
    {
        return self::divRound($amount * $bp, 10000);
    }
}

/** ISO date helpers (YYYY-MM-DD, UTC). */
final class Dates
{
    public static function parse(string $d): ?\DateTimeImmutable
    {
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $d, new \DateTimeZone('UTC'));
        return ($dt && $dt->format('Y-m-d') === $d) ? $dt : null;
    }

    public static function valid(string $d): bool
    {
        return self::parse($d) !== null;
    }

    public static function today(): string
    {
        return gmdate('Y-m-d');
    }

    public static function add(string $d, int $days): string
    {
        return self::must($d)->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
    }

    /** Whole days from $a to $b (b - a). */
    public static function diff(string $a, string $b): int
    {
        return (int) self::must($a)->diff(self::must($b))->format('%r%a');
    }

    public static function eom(string $d): string
    {
        return self::must($d)->modify('last day of this month')->format('Y-m-d');
    }

    public static function som(string $d): string
    {
        return self::must($d)->modify('first day of this month')->format('Y-m-d');
    }

    public static function addMonths(string $d, int $n): string
    {
        return self::must(self::som($d))->modify(($n >= 0 ? '+' : '') . $n . ' months')->format('Y-m-d');
    }

    public static function year(string $d): int
    {
        return (int) substr($d, 0, 4);
    }

    private static function must(string $d): \DateTimeImmutable
    {
        $dt = self::parse($d);
        if (!$dt) {
            throw new \InvalidArgumentException("Invalid date: {$d}");
        }
        return $dt;
    }
}

/** AES-256-GCM field encryption for sensitive values (tax ids, bank accounts). */
final class Crypto
{
    private static ?string $key = null;

    private static function key(): string
    {
        if (self::$key !== null) {
            return self::$key;
        }
        $env = getenv('PAYSLIP360_APP_KEY');
        if ($env !== false && $env !== '') {
            $k = base64_decode($env, true);
        } else {
            $path = (string) Config::get('key_path');
            if (!is_file($path)) {
                @mkdir(dirname($path), 0775, true);
                file_put_contents($path, base64_encode(random_bytes(32)));
                @chmod($path, 0600);
            }
            $k = base64_decode(trim((string) file_get_contents($path)), true);
        }
        if (!is_string($k) || strlen($k) !== 32) {
            throw new \RuntimeException('Encryption key is missing or invalid.');
        }
        return self::$key = $k;
    }

    public static function enc(?string $plain): ?string
    {
        if ($plain === null || $plain === '') {
            return null;
        }
        $iv = random_bytes(12);
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new \RuntimeException('Encryption failed.');
        }
        return 'v1:' . base64_encode($iv . $tag . $cipher);
    }

    public static function dec(?string $blob): ?string
    {
        if ($blob === null || $blob === '' || !str_starts_with($blob, 'v1:')) {
            return null;
        }
        $raw = base64_decode(substr($blob, 3), true);
        if ($raw === false || strlen($raw) < 29) {
            return null;
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $plain === false ? null : $plain;
    }

    public static function mask(?string $plain): ?string
    {
        if ($plain === null || $plain === '') {
            return null;
        }
        $len = mb_strlen($plain);
        return $len <= 4 ? str_repeat('*', $len) : str_repeat('*', min(8, $len - 4)) . mb_substr($plain, -4);
    }
}

/** SQLite access with nested transactions (savepoints). */
final class Db
{
    private static ?PDO $pdo = null;
    private static int $depth = 0;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $path = (string) Config::get('db_path');
            if ($path !== ':memory:') {
                @mkdir(dirname($path), 0775, true);
            }
            $pdo = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA busy_timeout = 8000');
            if ($path !== ':memory:') {
                $pdo->exec('PRAGMA journal_mode = WAL');
            }
            self::$pdo = $pdo;
            self::$depth = 0;
        }
        return self::$pdo;
    }

    public static function reset(): void
    {
        self::$pdo = null;
        self::$depth = 0;
    }

    public static function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }

    public static function all(string $sql, array $params = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    public static function val(string $sql, array $params = []): mixed
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function run(string $sql, array $params = []): int
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    public static function insert(string $table, array $row): int
    {
        $cols = array_keys($row);
        $sql = 'INSERT INTO ' . $table . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
        self::run($sql, array_values($row));
        return (int) self::pdo()->lastInsertId();
    }

    public static function update(string $table, int $id, array $row): void
    {
        $set = implode(',', array_map(fn($c) => $c . ' = ?', array_keys($row)));
        self::run('UPDATE ' . $table . ' SET ' . $set . ' WHERE id = ?', [...array_values($row), $id]);
    }

    public static function tx(callable $fn): mixed
    {
        $pdo = self::pdo();
        $level = self::$depth;
        if ($level === 0) {
            $pdo->exec('BEGIN IMMEDIATE');
        } else {
            $pdo->exec('SAVEPOINT sp' . $level);
        }
        self::$depth++;
        try {
            $result = $fn();
            self::$depth--;
            $pdo->exec($level === 0 ? 'COMMIT' : 'RELEASE sp' . $level);
            return $result;
        } catch (Throwable $e) {
            self::$depth--;
            $pdo->exec($level === 0 ? 'ROLLBACK' : 'ROLLBACK TO sp' . $level);
            if ($level > 0) {
                $pdo->exec('RELEASE sp' . $level);
            }
            throw $e;
        }
    }
}

/** Request wrapper. */
final class Req
{
    public function __construct(
        public string $method,
        public string $path,
        public array $query,
        public array $headers,
        public string $raw,
        public string $ip
    ) {
    }

    public static function capture(): self
    {
        $uri = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $route = '/';
        if (preg_match('#/api(?:/index\.php)?(/.*)?$#', $uri, $m)) {
            $route = $m[1] ?? '/';
        }
        $route = '/' . trim(rawurldecode($route), '/');
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = (string) $v;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        $raw = (string) file_get_contents('php://input', false, null, 0, 12 * 1024 * 1024);
        return new self($_SERVER['REQUEST_METHOD'] ?? 'GET', $route, $_GET, $headers, $raw, $_SERVER['REMOTE_ADDR'] ?? 'unknown');
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function bearer(): ?string
    {
        $h = $this->header('authorization');
        if ($h !== null && preg_match('/^Bearer\s+([A-Za-z0-9._-]{20,200})$/', $h, $m)) {
            return $m[1];
        }
        $x = $this->header('x-auth-token'); // some hosts strip Authorization
        return ($x !== null && preg_match('/^[A-Za-z0-9._-]{20,200}$/', $x)) ? $x : null;
    }

    public function json(): array
    {
        if (trim($this->raw) === '') {
            return [];
        }
        $d = json_decode($this->raw, true);
        if (!is_array($d)) {
            throw new ApiError(400, 'bad_json', 'The request body must be valid JSON.');
        }
        return $d;
    }

    public function q(string $key, ?string $default = null): ?string
    {
        $v = $this->query[$key] ?? null;
        return is_string($v) && $v !== '' ? $v : $default;
    }
}

final class Http
{
    public static function json(int $status, mixed $data): never
    {
        self::send($status, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION), 'application/json; charset=UTF-8');
    }

    public static function raw(int $status, string $body, string $contentType, array $extra = []): never
    {
        self::send($status, $body, $contentType, $extra);
    }

    private static function send(int $status, string|false $body, string $type, array $extra = []): never
    {
        http_response_code($status);
        header('Content-Type: ' . $type);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer');
        foreach ($extra as $k => $v) {
            header($k . ': ' . $v);
        }
        echo $body === false ? '{}' : $body;
        exit;
    }
}

/** Collects field errors so a form gets all problems at once. */
final class Validator
{
    public array $errors = [];

    public function __construct(private array $in)
    {
    }

    public function has(string $k): bool
    {
        return array_key_exists($k, $this->in) && $this->in[$k] !== null && $this->in[$k] !== '';
    }

    public function raw(string $k): mixed
    {
        return $this->in[$k] ?? null;
    }

    public function str(string $k, bool $req = true, int $max = 200): ?string
    {
        $v = $this->in[$k] ?? null;
        if ($v === null || (is_string($v) && trim($v) === '')) {
            if ($req) {
                $this->errors[$k] = 'This field is required.';
            }
            return null;
        }
        if (!is_string($v) && !is_numeric($v)) {
            $this->errors[$k] = 'Enter a valid value.';
            return null;
        }
        $v = trim((string) $v);
        if (mb_strlen($v) > $max) {
            $this->errors[$k] = "Must be {$max} characters or fewer.";
            return null;
        }
        return $v;
    }

    public function email(string $k, bool $req = true): ?string
    {
        $v = $this->str($k, $req, 200);
        if ($v === null) {
            return null;
        }
        if (!filter_var($v, FILTER_VALIDATE_EMAIL)) {
            $this->errors[$k] = 'Enter a valid email address.';
            return null;
        }
        return strtolower($v);
    }

    public function enum(string $k, array $allowed, bool $req = true, ?string $default = null): ?string
    {
        $v = $this->in[$k] ?? null;
        if ($v === null || $v === '') {
            if ($req && $default === null) {
                $this->errors[$k] = 'Please choose an option.';
                return null;
            }
            return $default;
        }
        if (!is_string($v) || !in_array($v, $allowed, true)) {
            $this->errors[$k] = 'Please choose a valid option.';
            return null;
        }
        return $v;
    }

    public function date(string $k, bool $req = true): ?string
    {
        $v = $this->str($k, $req, 10);
        if ($v === null) {
            return null;
        }
        if (!Dates::valid($v)) {
            $this->errors[$k] = 'Enter a valid date (YYYY-MM-DD).';
            return null;
        }
        return $v;
    }

    public function int(string $k, bool $req = true, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): ?int
    {
        $v = $this->in[$k] ?? null;
        if ($v === null || $v === '') {
            if ($req) {
                $this->errors[$k] = 'This field is required.';
            }
            return null;
        }
        if (!is_int($v) && !(is_string($v) && preg_match('/^-?\d{1,15}$/', $v))) {
            $this->errors[$k] = 'Enter a whole number.';
            return null;
        }
        $n = (int) $v;
        if ($n < $min || $n > $max) {
            $this->errors[$k] = "Must be between {$min} and {$max}.";
            return null;
        }
        return $n;
    }

    public function bool(string $k, bool $default = false): bool
    {
        $v = $this->in[$k] ?? null;
        if ($v === null) {
            return $default;
        }
        return $v === true || $v === 1 || $v === '1' || $v === 'true';
    }

    /** Money in major units as a decimal string/number; returns minor units. */
    public function money(string $k, string $currency, bool $req = true, bool $allowNegative = false, int $maxMinor = 100000000000): ?int
    {
        $v = $this->in[$k] ?? null;
        if ($v === null || $v === '') {
            if ($req) {
                $this->errors[$k] = 'Enter an amount.';
            }
            return null;
        }
        $minor = Money::parse($v, $currency);
        if ($minor === null) {
            $this->errors[$k] = 'Enter a valid amount with at most ' . Money::exp($currency) . ' decimal places.';
            return null;
        }
        if (!$allowNegative && $minor < 0) {
            $this->errors[$k] = 'Amount cannot be negative.';
            return null;
        }
        if (abs($minor) > $maxMinor) {
            $this->errors[$k] = 'Amount is too large.';
            return null;
        }
        return $minor;
    }

    /** Percentage like "12.5" -> 1250 basis points. */
    public function pct(string $k, bool $req = true, float $max = 100.0): ?int
    {
        $v = $this->in[$k] ?? null;
        if ($v === null || $v === '') {
            if ($req) {
                $this->errors[$k] = 'Enter a percentage.';
            }
            return null;
        }
        $s = is_string($v) ? trim($v) : (is_int($v) || is_float($v) ? number_format((float) $v, 2, '.', '') : '');
        if (!preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/', $s, $m)) {
            $this->errors[$k] = 'Enter a percentage with at most 2 decimal places.';
            return null;
        }
        $bp = (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
        if ($bp > (int) round($max * 100)) {
            $this->errors[$k] = "Percentage cannot exceed {$max}.";
            return null;
        }
        return $bp;
    }

    public function fail(string $key, string $message): void
    {
        $this->errors[$key] = $message;
    }

    public function done(): void
    {
        if ($this->errors) {
            throw new ApiError(422, 'validation_failed', 'Please correct the highlighted fields.', $this->errors);
        }
    }
}

/** CSV cell that is safe against spreadsheet formula injection. */
function csv_cell(mixed $v): string
{
    $t = (string) ($v ?? '');
    if (preg_match('/^[=+\-@\t\r]/', $t)) {
        $t = "'" . $t;
    }
    return '"' . str_replace('"', '""', $t) . '"';
}
