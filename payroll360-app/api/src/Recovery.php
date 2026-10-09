<?php
declare(strict_types=1);

namespace P360;

/**
 * Forgot-password recovery. A reset link is emailed with a random one-time token; only a SHA-256 hash of the
 * token is stored. Requests always get the same reply whether or not the email has an account, so the forgot
 * form cannot be used to discover which emails are registered.
 */
final class Recovery
{
    private const TTL_MINUTES = 60;
    private const MAX_PER_HOUR = 3;

    public static function migrate(): void
    {
        Db::pdo()->exec('CREATE TABLE IF NOT EXISTS password_resets (
            id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            token_hash TEXT NOT NULL UNIQUE, created_at TEXT NOT NULL, expires_at TEXT NOT NULL, used_at TEXT
        )');
        Db::pdo()->exec('CREATE INDEX IF NOT EXISTS idx_password_resets_user ON password_resets(user_id, created_at)');
    }

    /** Base address for the reset link. Set PAYSLIP360_PUBLIC_URL in production so a request cannot steer the link. */
    private static function publicUrl(string $host, bool $https): string
    {
        $configured = (string) Config::get('public_url', '');
        if ($configured !== '') {
            return rtrim($configured, '/') . '/';
        }
        return ($https ? 'https' : 'http') . '://' . $host . '/';
    }

    public static function request(string $email, string $host, bool $https): void
    {
        self::migrate();
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        $user = Db::one('SELECT * FROM users WHERE email = ? AND active = 1', [$email]);
        if (!$user) {
            return; // same reply as an existing account; nothing is revealed
        }
        $since = gmdate('Y-m-d\TH:i:s\Z', time() - 3600);
        $recent = (int) Db::val('SELECT COUNT(*) FROM password_resets WHERE user_id = ? AND created_at > ?', [(int) $user['id'], $since]);
        if ($recent >= self::MAX_PER_HOUR) {
            return; // throttled: send nothing more
        }
        $token = bin2hex(random_bytes(32));
        Db::insert('password_resets', [
            'user_id' => (int) $user['id'],
            'token_hash' => hash('sha256', $token),
            'created_at' => Db::now(),
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + self::TTL_MINUTES * 60),
        ]);
        $link = self::publicUrl($host, $https) . '?reset=' . $token;
        $html = "<!DOCTYPE html><html><body style='font-family:Arial,sans-serif;color:#1f2937;padding:24px'>"
            . '<h2 style="color:#01442d">Reset your Payroll360 password</h2>'
            . '<p>Someone asked to reset the password for this account. If that was you, use the button below within '
            . self::TTL_MINUTES . ' minutes:</p>'
            . '<p><a href="' . htmlspecialchars($link, ENT_QUOTES) . '" style="background:#14a841;color:#fff;padding:10px 16px;border-radius:8px;text-decoration:none">Reset password</a></p>'
            . '<p style="color:#6b7280;font-size:12px">If you did not ask for this, ignore this email. Your password will not change.</p></body></html>';
        try {
            Mailer::sendPlain((string) $user['email'], 'Reset your Payroll360 password', $html, 'Payroll360');
        } catch (\Throwable) {
            // A failed email must not reveal anything to the requester.
        }
    }

    /**
     * Sent when an administrator creates a new user. Does not email the password the administrator chose -
     * instead it emails a secure link (valid 60 minutes) for the new user to set their own password, the same
     * mechanism as forgot-password. This avoids ever putting a real password in an email.
     */
    public static function welcome(string $email, string $name, string $host, bool $https): void
    {
        self::migrate();
        $email = strtolower(trim($email));
        $user = Db::one('SELECT * FROM users WHERE email = ? AND active = 1', [$email]);
        if (!$user) {
            return;
        }
        $token = bin2hex(random_bytes(32));
        Db::insert('password_resets', [
            'user_id' => (int) $user['id'],
            'token_hash' => hash('sha256', $token),
            'created_at' => Db::now(),
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + self::TTL_MINUTES * 60),
        ]);
        $link = self::publicUrl($host, $https) . '?reset=' . $token;
        $greeting = $name !== '' ? htmlspecialchars($name, ENT_QUOTES) : 'there';
        $html = "<!DOCTYPE html><html><body style='font-family:Arial,sans-serif;color:#1f2937;padding:24px'>"
            . '<h2 style="color:#01442d">Welcome to Payroll360</h2>'
            . '<p>Hello ' . $greeting . ',</p>'
            . '<p>An administrator created a Payroll360 account for you (<b>' . htmlspecialchars($email, ENT_QUOTES) . '</b>). '
            . 'Set your own password within ' . self::TTL_MINUTES . ' minutes using the button below:</p>'
            . '<p><a href="' . htmlspecialchars($link, ENT_QUOTES) . '" style="background:#14a841;color:#fff;padding:10px 16px;border-radius:8px;text-decoration:none">Set my password</a></p>'
            . '<p style="color:#6b7280;font-size:12px">If this link expires, use "Forgot password?" on the sign-in page to request a new one.</p></body></html>';
        try {
            Mailer::sendPlain($email, 'Welcome to Payroll360 - set your password', $html, 'Payroll360');
        } catch (\Throwable) {
            // A new account must not fail just because the welcome email could not be sent.
        }
    }

    /** Sets a new password from a valid, unused, unexpired token, then ends that user's sessions. */
    public static function reset(string $token, string $password): void
    {
        self::migrate();
        if (!Auth::passwordOk($password)) {
            throw new ApiError(422, 'validation_failed', 'Please correct the highlighted fields.', ['new_password' => 'Use at least 10 characters including a letter and a number.']);
        }
        $row = Db::one('SELECT * FROM password_resets WHERE token_hash = ?', [hash('sha256', $token)]);
        if (!$row || $row['used_at'] !== null || $row['expires_at'] < Db::now()) {
            throw new ApiError(422, 'invalid_token', 'This reset link is invalid or has expired. Request a new one.');
        }
        Db::tx(function () use ($row, $password) {
            Db::update('users', (int) $row['user_id'], ['password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
            Db::run('UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL', [Db::now(), (int) $row['user_id']]);
            Db::run('DELETE FROM sessions WHERE user_id = ?', [(int) $row['user_id']]);
        });
        Audit::log('Password Reset', 'user', (int) $row['user_id']);
    }
}
