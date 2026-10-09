<?php
declare(strict_types=1);

namespace P360;

use PDO;

/**
 * Lead capture and demo bookings. Kept in its own SQLite file (PAYSLIP360_CRM_DB), separate from the payroll data.
 *
 * Flow: the website demo form posts to POST /leads and is then sent to Microsoft Bookings. When a booking is made,
 * Power Automate posts it to POST /bookings with the shared key. We then email the customer a confirmation and
 * email the support team a notification.
 */
final class Crm
{
    private const DEFAULT_BOOKING_URL = 'https://bookings.cloud.microsoft/bookwithme/user/26c46969a1b64d27a2476065687170e6@appz360.com/meetingtype/oS5hN2e0MUq1c1PQ5k8xKQ2?anonymous&ismsaljsauthenabled&ep=mLinkFromTile';
    private const SUPPORT_EMAIL = 'support@appz360.com';

    private static ?PDO $pdo = null;

    private static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $path = getenv('PAYSLIP360_CRM_DB') ?: (Config::root() . '/api/data/crm.sqlite');
            @mkdir(dirname($path), 0775, true);
            $pdo = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $pdo->exec('PRAGMA busy_timeout = 8000');
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('CREATE TABLE IF NOT EXISTS leads (
                id INTEGER PRIMARY KEY, name TEXT NOT NULL, company TEXT, email TEXT NOT NULL, phone TEXT,
                message TEXT, source TEXT, status TEXT NOT NULL DEFAULT \'new\', created_at TEXT NOT NULL, created_ip TEXT
            )');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_leads_email ON leads(email)');
            $pdo->exec('CREATE TABLE IF NOT EXISTS bookings (
                id INTEGER PRIMARY KEY, lead_id INTEGER, name TEXT NOT NULL, email TEXT NOT NULL, company TEXT,
                phone TEXT, starts_at TEXT NOT NULL, ends_at TEXT, notes TEXT, created_at TEXT NOT NULL
            )');
            self::$pdo = $pdo;
        }
        return self::$pdo;
    }

    private static function run(string $sql, array $params = []): \PDOStatement
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st;
    }

    private static function bookingUrl(): string
    {
        $url = (string) (getenv('PAYSLIP360_BOOKING_URL') ?: '');
        return $url !== '' ? $url : self::DEFAULT_BOOKING_URL;
    }

    /** Step 1: the demo form. Saves the lead and returns the Bookings address to send the visitor to. */
    public static function createLead(array $in, string $ip): array
    {
        $v = new Validator($in);
        $name = $v->str('name', true, 100);
        $email = $v->email('email', true);
        $company = $v->str('company', false, 120);
        $phone = $v->str('phone', false, 40);
        $message = $v->str('message', false, 1000);
        // Honeypot: a real visitor never fills this hidden field.
        if (!empty($in['website'])) {
            $v->fail('name', 'Please try again.');
        }
        $v->done();

        self::run(
            'INSERT INTO leads (name, company, email, phone, message, source, created_at, created_ip) VALUES (?,?,?,?,?,?,?,?)',
            [$name, $company, $email, $phone, $message, 'website-demo', gmdate('Y-m-d\TH:i:s\Z'), $ip]
        );
        $leadId = (int) self::pdo()->lastInsertId();

        // Also copy the lead into the site's existing MySQL leads table (the one submit-lead.php has always
        // used), so anything outside this app that already reads from there keeps working. Best-effort: this
        // never blocks or fails the lead capture itself, which has already succeeded above.
        [$product, $team, $companySize, $challenge] = self::parseLeadContext($message);
        $nameParts = preg_split('/\s+/', trim((string) $name), 2);
        self::mirrorToMysql((string) $nameParts[0], (string) ($nameParts[1] ?? ''), (string) $email, $company, $companySize, $team, $product, $challenge ?? $message, $ip);

        return ['ok' => true, 'lead_id' => $leadId, 'redirect' => self::bookingUrl()];
    }

    /** Copies one lead into the shared MySQL `leads` table (same table and columns as submit-lead.php creates).
     *  Does nothing if APPZ360_DB_* isn't configured here, and never throws - a failure here must not affect
     *  the SQLite copy, which already has the lead. */
    private static function mirrorToMysql(string $firstName, string $lastName, string $email, ?string $company, ?string $companySize, ?string $team, ?string $product, ?string $message, string $ip): void
    {
        $host = (string) (getenv('APPZ360_DB_HOST') ?: 'localhost');
        $dbName = (string) (getenv('APPZ360_DB_NAME') ?: 'u650935920_db_appz360');
        $user = (string) (getenv('APPZ360_DB_USER') ?: 'u650935920_admin');
        $pass = (string) (getenv('APPZ360_DB_PASS') ?: '');
        if ($pass === '') {
            return; // not configured on this server - the lead is still safe in the SQLite copy
        }
        try {
            $pdo = new \PDO("mysql:host={$host};dbname={$dbName};charset=utf8mb4", $user, $pass, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT => 5,
            ]);
            $pdo->exec("CREATE TABLE IF NOT EXISTS `leads` (
                `id` INT AUTO_INCREMENT PRIMARY KEY, `form_type` VARCHAR(50) NOT NULL DEFAULT 'Demo',
                `first_name` VARCHAR(100) NOT NULL, `last_name` VARCHAR(100) DEFAULT NULL, `email` VARCHAR(150) NOT NULL,
                `company` VARCHAR(150) NOT NULL, `company_size` VARCHAR(50) DEFAULT NULL, `team_or_request` VARCHAR(100) DEFAULT NULL,
                `product` VARCHAR(100) DEFAULT NULL, `message` TEXT DEFAULT NULL, `ip_address` VARCHAR(45) DEFAULT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $stmt = $pdo->prepare('INSERT INTO `leads` (form_type, first_name, last_name, email, company, company_size, team_or_request, product, message, ip_address) VALUES (?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute(['Demo Request', $firstName, $lastName, $email, $company, $companySize, $team, $product, $message, $ip]);
        } catch (\Throwable $e) {
            error_log('Payroll360 CRM: MySQL lead mirror failed: ' . $e->getMessage());
        }
    }

    /** Power Automate must send the shared key in the X-CRM-Key header. Compared in constant time. */
    public static function requireKey(Req $req): void
    {
        $expected = (string) (getenv('PAYSLIP360_CRM_KEY') ?: Config::get('crm_key', ''));
        $given = (string) $req->header('x-crm-key');
        if ($expected === '' || !hash_equals($expected, $given)) {
            throw new ApiError(401, 'unauthorized', 'The CRM key is missing or incorrect.');
        }
    }

    /** Step 2: a booking was made in Microsoft Bookings. Stores it, links it to the lead, and sends both emails. */
    public static function recordBooking(array $in): array
    {
        $v = new Validator($in);
        $name = $v->str('name', true, 100);
        $email = $v->email('email', true);
        $company = $v->str('company', false, 120);
        $phone = $v->str('phone', false, 40);
        $start = $v->str('start', true, 40);
        $end = $v->str('end', false, 40);
        $notes = $v->str('notes', false, 1000);
        $v->done();

        // Pull in what the demo form captured (product, team, company size, their challenge), lost otherwise
        // between the form and Microsoft Bookings - Bookings only ever tells us the name, email and time.
        $lead = self::run('SELECT id, company AS lead_company, phone AS lead_phone, message FROM leads WHERE email = ? ORDER BY id DESC LIMIT 1', [$email])->fetch();
        $leadId = $lead ? (int) $lead['id'] : null;
        if ($leadId) {
            self::run("UPDATE leads SET status = 'booked' WHERE id = ?", [$leadId]);
        }
        $company = $company ?: ($lead['lead_company'] ?? null);
        $phone = $phone ?: ($lead['lead_phone'] ?? null);
        [$product, , , $challenge] = self::parseLeadContext($lead['message'] ?? null);

        self::run(
            'INSERT INTO bookings (lead_id, name, email, company, phone, starts_at, ends_at, notes, created_at) VALUES (?,?,?,?,?,?,?,?,?)',
            [$leadId, $name, $email, $company, $phone, $start, $end, $notes, gmdate('Y-m-d\TH:i:s\Z')]
        );

        $when = self::formatWhen($start, $end);
        $productLabel = $product ?: 'Appz360';
        $firstName = trim((string) strtok($name, ' ')) ?: $name;

        $customerBody = '<h2 style="margin:0 0 6px;font-size:21px;color:#0b0f0c">You\'re booked!</h2>'
            . '<p style="margin:0 0 22px;color:#6b7a72;font-size:14px">Thanks for scheduling your ' . htmlspecialchars($productLabel, ENT_QUOTES) . ' demo with Appz360.</p>'
            . '<div style="background:#f6f8f7;border:1px solid #e7ece9;border-radius:12px;padding:18px 20px;margin-bottom:22px">'
            . '<div style="font-size:11px;font-weight:700;color:#6b7a72;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px">Your demo</div>'
            . '<div style="font-size:17px;font-weight:600;color:#0b0f0c">' . htmlspecialchars($when, ENT_QUOTES) . '</div></div>'
            . '<p style="font-size:14px;line-height:1.7;margin:0 0 14px">Hi ' . htmlspecialchars($firstName, ENT_QUOTES) . ',</p>'
            . '<p style="font-size:14px;line-height:1.7;margin:0 0 14px">We\'re looking forward to showing you how ' . htmlspecialchars($productLabel, ENT_QUOTES) . ' fits your team. One of our specialists will join you at the scheduled time.</p>'
            . '<p style="font-size:14px;line-height:1.7;margin:0">Need to reschedule, or have a question before then? Just reply to this email, or reach us at <a href="mailto:' . self::SUPPORT_EMAIL . '" style="color:#23943a;text-decoration:none;font-weight:600">' . self::SUPPORT_EMAIL . '</a>.</p>'
            . '<p style="font-size:14px;line-height:1.7;margin:26px 0 0;color:#6b7a72">See you soon,<br><strong style="color:#0b0f0c">The Appz360 Team</strong></p>';

        $row = fn(string $label, ?string $value) => $value === null || $value === '' ? '' : (
            '<tr><td style="padding:9px 14px 9px 0;border-bottom:1px solid #f1f5f3;font-size:11px;font-weight:700;color:#6b7a72;text-transform:uppercase;letter-spacing:.05em;white-space:nowrap;vertical-align:top">' . $label . '</td>'
            . '<td style="padding:9px 0;border-bottom:1px solid #f1f5f3;font-size:14px;color:#0b0f0c">' . htmlspecialchars($value, ENT_QUOTES) . '</td></tr>'
        );
        $supportBody = '<h2 style="margin:0 0 4px;font-size:20px;color:#0b0f0c">New demo booking</h2>'
            . '<p style="margin:0 0 20px;color:#6b7a72;font-size:13px">A visitor just booked a slot through the website.</p>'
            . '<table cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse;margin-bottom:' . ($challenge ? '14px' : '0') . '">'
            . $row('Name', $name) . $row('Email', $email) . $row('Company', $company) . $row('Phone', $phone)
            . $row('Product', $productLabel) . $row('When', $when) . $row('Notes from Bookings', $notes)
            . '</table>'
            . ($challenge ? '<div style="background:#f6f8f7;border-left:3px solid #23943a;border-radius:0 10px 10px 0;padding:14px 18px;font-size:14px;line-height:1.6;color:#1d2622"><div style="font-size:11px;font-weight:700;color:#6b7a72;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px">What they told us</div>' . nl2br(htmlspecialchars($challenge, ENT_QUOTES)) . '</div>' : '');

        // A failed email does not undo the stored booking. The result tells Power Automate which emails went out.
        $sent = ['customer' => true, 'support' => true];
        try {
            Mailer::sendPlain($email, 'You\'re booked: your ' . $productLabel . ' demo with Appz360', self::emailShell('Your demo is confirmed for ' . $when, $customerBody), 'Appz360');
        } catch (\Throwable) {
            $sent['customer'] = false;
        }
        try {
            Mailer::sendPlain(self::SUPPORT_EMAIL, 'New demo booking: ' . $name . ($company ? ' (' . $company . ')' : ''), self::emailShell('New ' . $productLabel . ' demo booked by ' . $name, $supportBody), 'Appz360 CRM');
        } catch (\Throwable) {
            $sent['support'] = false;
        }
        return ['ok' => true, 'lead_id' => $leadId, 'emails' => $sent];
    }

    /** The demo form packs "Product: X · Team: Y · Company size: Z" then an optional free-text message into one
     *  field (see demo.html). Splits that back out: used by the emails, and to fill the separate columns when
     *  mirroring a lead into the MySQL table. Returns [product, team, companySize, challenge]. */
    private static function parseLeadContext(?string $message): array
    {
        if (!$message) {
            return [null, null, null, null];
        }
        $parts = explode("\n\n", $message, 2);
        $product = $team = $companySize = null;
        foreach (preg_split('/\s*\x{00b7}\s*/u', $parts[0]) as $segment) {
            $segment = trim($segment);
            if (preg_match('/^Product:\s*(.+)$/u', $segment, $m)) {
                $product = trim($m[1]);
            } elseif (preg_match('/^Team:\s*(.+)$/u', $segment, $m)) {
                $team = trim($m[1]);
            } elseif (preg_match('/^Company size:\s*(.+)$/u', $segment, $m)) {
                $companySize = trim($m[1]);
            }
        }
        $challenge = trim($parts[1] ?? '');
        return [$product, $team, $companySize, $challenge !== '' ? $challenge : null];
    }

    /** A readable date/time for an email. Falls back to the raw value if Bookings sends something unparsable. */
    private static function formatWhen(string $start, ?string $end): string
    {
        try {
            $s = new \DateTime($start);
            $out = $s->format('l, j F Y \a\t g:i A') . ' UTC';
            if ($end) {
                try {
                    $out .= ' - ' . (new \DateTime($end))->format('g:i A') . ' UTC';
                } catch (\Throwable) {
                    // keep the start-only version
                }
            }
            return $out;
        } catch (\Throwable) {
            return $start . ($end ? ' to ' . $end : '');
        }
    }

    /** The shared Appz360 email frame: dark header with the logo, a white card, and a light footer. */
    private static function emailShell(string $previewText, string $bodyHtml): string
    {
        $logo = 'https://www.appz360.com/assets/appz360-logo.png';
        return '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width"></head>'
            . '<body style="margin:0;padding:0;background:#f6f8f7;font-family:\'Segoe UI\',Arial,sans-serif;color:#0b0f0c">'
            . '<div style="display:none;max-height:0;overflow:hidden;opacity:0">' . htmlspecialchars($previewText, ENT_QUOTES) . '</div>'
            . '<div style="max-width:600px;margin:0 auto;padding:28px 16px">'
            . '<div style="background:#ffffff;border-radius:16px;border:1px solid #e7ece9;overflow:hidden;box-shadow:0 10px 30px rgba(0,0,0,.05)">'
            . '<div style="background:#0b0f0c;padding:22px 32px;text-align:center"><img src="' . $logo . '" alt="Appz360" height="28" style="height:28px;width:auto;display:inline-block"></div>'
            . '<div style="padding:32px">' . $bodyHtml . '</div>'
            . '<div style="background:#f6f8f7;padding:16px 32px;font-size:12px;color:#6b7a72;border-top:1px solid #e7ece9;text-align:center">'
            . '&copy; ' . date('Y') . ' Appz360 Inc. &middot; <a href="https://appz360.com/contact" style="color:#23943a;text-decoration:none">Contact Support</a>'
            . '</div></div></div></body></html>';
    }

    /** Internal list for the admin: newest first. */
    public static function listLeads(): array
    {
        return self::run('SELECT id, name, company, email, phone, message, status, source, created_at FROM leads ORDER BY id DESC LIMIT 500')->fetchAll();
    }
}
