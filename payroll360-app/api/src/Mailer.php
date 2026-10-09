<?php
declare(strict_types=1);

namespace P360;

/** Sends payslip emails. The recipient and the PDF always come from the server, never from the browser. */
final class Mailer
{
    // The last SMTP failure detail (connect error, or the server's own rejection text), for `cli.php test-mail`.
    // Also still written to the PHP error log, same as before.
    private static ?string $lastError = null;

    public static function lastError(): ?string
    {
        return self::$lastError;
    }

    public static function status(): array
    {
        $t = (string) Config::get('mail_transport', 'smtp');
        $configured = match ($t) {
            'smtp' => Config::get('smtp_user') !== '' && Config::get('smtp_pass') !== '',
            'mail', 'file' => true,
            default => false,
        };
        return ['transport' => $t, 'configured' => $configured];
    }

    private static function safe(string $v, int $max = 200): string
    {
        return mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $v)), 0, $max);
    }

    private static function word(string $text): string
    {
        return '=?UTF-8?B?' . base64_encode($text) . '?=';
    }

    private static function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }

    public static function payslipHtml(array $doc): string
    {
        $c = (string) $doc['currency'];
        $company = self::h((string) $doc['employer']['name']);
        $name = self::h((string) $doc['employee']['name']);
        $period = self::h($doc['period']['start'] . ' to ' . $doc['period']['end']);
        $net = self::h(PayslipPdf::money((string) $doc['totals']['net'], $c));
        $number = self::h((string) $doc['number']);
        return "<!DOCTYPE html><html><head><meta charset='UTF-8'></head><body style='margin:0;padding:24px;background:#f4f6f8;font-family:Arial,Helvetica,sans-serif;color:#1f2937'>"
            . "<div style='max-width:560px;margin:0 auto;background:#fff;border:1px solid #e5e7eb;border-radius:10px;overflow:hidden'>"
            . "<div style='background:#01442d;color:#fff;padding:20px 28px'><div style='font-size:17px;font-weight:700'>{$company}</div><div style='font-size:12px;opacity:.8'>Confidential payslip</div></div>"
            . "<div style='padding:26px 28px'><p style='margin-top:0'>Hello {$name},</p>"
            . "<p style='line-height:1.6'>Your payslip for <b>{$period}</b> is attached to this email as a PDF.</p>"
            . "<table style='width:100%;border-collapse:collapse;margin:18px 0'><tr><td style='padding:8px 0;color:#6b7280'>Payslip number</td><td style='padding:8px 0;text-align:right'>{$number}</td></tr>"
            . "<tr><td style='padding:8px 0;border-top:1px solid #e5e7eb;font-weight:700'>Net pay</td><td style='padding:8px 0;border-top:1px solid #e5e7eb;text-align:right;font-weight:700;color:#14a841'>{$net}</td></tr></table>"
            . "<p style='font-size:12px;color:#6b7280;line-height:1.5'>This message contains confidential payroll information. Please do not forward it. "
            . "If something looks wrong, reply to this email or contact your payroll team.</p></div></div></body></html>";
    }

    private static function build(string $to, ?string $cc, string $subject, string $html, string $fromAddress, string $fromName, ?string $replyTo, string $pdfName, string $pdf): array
    {
        $boundary = '==Payroll360_' . bin2hex(random_bytes(12));
        $domain = substr((string) strrchr($fromAddress, '@'), 1) ?: 'localhost';
        $headers = [
            'From: ' . self::word($fromName) . " <{$fromAddress}>",
            'MIME-Version: 1.0',
            "Content-Type: multipart/mixed; boundary=\"{$boundary}\"",
            'Date: ' . date('r'),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
            'X-Mailer: Payroll360',
        ];
        if ($replyTo) {
            $headers[] = "Reply-To: <{$replyTo}>";
        }
        if ($cc) {
            $headers[] = "Cc: <{$cc}>";
        }
        $safeName = preg_replace('/[^A-Za-z0-9_.\-]/', '_', $pdfName);
        $body = "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . rtrim(chunk_split(base64_encode($html))) . "\r\n"
            . "--{$boundary}\r\nContent-Type: application/pdf; name=\"{$safeName}\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"{$safeName}\"\r\n\r\n"
            . rtrim(chunk_split(base64_encode($pdf))) . "\r\n--{$boundary}--";
        return ['headers' => $headers, 'body' => $body, 'to' => $to, 'subject' => $subject];
    }

    /** @return string human-readable delivery method */
    public static function send(string $to, ?string $cc, string $subject, string $html, string $fromName, ?string $replyTo, string $pdfName, string $pdf): string
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new ApiError(422, 'validation_failed', 'The recipient does not have a valid email address on file.');
        }
        $cc = $cc && filter_var($cc, FILTER_VALIDATE_EMAIL) ? $cc : null;
        $replyTo = $replyTo && filter_var($replyTo, FILTER_VALIDATE_EMAIL) ? $replyTo : null;
        $transport = (string) Config::get('mail_transport', 'smtp');
        $from = (string) (Config::get('from_address') ?: Config::get('smtp_user') ?: 'payroll@localhost');
        $msg = self::build($to, $cc, self::safe($subject), $html, $from, self::safe($fromName, 100) ?: 'Payroll', $replyTo, $pdfName, $pdf);

        if ($transport === 'file') {
            $dir = (string) Config::get('outbox_dir');
            @mkdir($dir, 0775, true);
            $file = $dir . '/' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.eml';
            $eml = implode("\r\n", array_merge($msg['headers'], ['To: <' . $to . '>', 'Subject: ' . self::word($msg['subject'])])) . "\r\n\r\n" . $msg['body'];
            if (file_put_contents($file, $eml) === false) {
                throw new ApiError(502, 'delivery_failed', 'Could not write the email to the outbox folder.');
            }
            return 'Saved to the outbox folder (test mode - no email was actually sent)';
        }
        if ($transport === 'mail') {
            if (!@mail($to, self::word($msg['subject']), $msg['body'], implode("\r\n", $msg['headers']))) {
                throw new ApiError(502, 'delivery_failed', 'The server mail agent could not send this email.');
            }
            return 'Server mail agent (PHP mail)';
        }
        if (!self::smtp($msg, $from)) {
            throw new ApiError(502, 'delivery_failed', 'The email could not be sent. Check the mail settings in payslip-config.php.');
        }
        return 'Microsoft 365 Exchange Online (TLS)';
    }

    private static function buildPlain(string $to, string $subject, string $html, string $fromAddress, string $fromName): array
    {
        $domain = substr((string) strrchr($fromAddress, '@'), 1) ?: 'localhost';
        $headers = [
            'From: ' . self::word($fromName) . " <{$fromAddress}>",
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            'Date: ' . date('r'),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
            'X-Mailer: Payroll360',
        ];
        return ['headers' => $headers, 'body' => rtrim(chunk_split(base64_encode($html))), 'to' => $to, 'subject' => $subject];
    }

    /** A plain HTML email with no PDF attachment (welcome/verification mail, not a payslip). Same transports as send(). */
    public static function sendPlain(string $to, string $subject, string $html, string $fromName = 'Payroll360'): string
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new ApiError(422, 'validation_failed', 'That is not a valid email address.');
        }
        $transport = (string) Config::get('mail_transport', 'smtp');
        $from = (string) (Config::get('from_address') ?: Config::get('smtp_user') ?: 'payroll@localhost');
        $msg = self::buildPlain($to, self::safe($subject), $html, $from, self::safe($fromName, 100) ?: 'Payroll360');

        if ($transport === 'file') {
            $dir = (string) Config::get('outbox_dir');
            @mkdir($dir, 0775, true);
            $file = $dir . '/' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.eml';
            $eml = implode("\r\n", array_merge($msg['headers'], ['To: <' . $to . '>', 'Subject: ' . self::word($msg['subject'])])) . "\r\n\r\n" . $msg['body'];
            if (file_put_contents($file, $eml) === false) {
                throw new ApiError(502, 'delivery_failed', 'Could not write the email to the outbox folder.');
            }
            return 'Saved to the outbox folder (test mode - no email was actually sent)';
        }
        if ($transport === 'mail') {
            if (!@mail($to, self::word($msg['subject']), $msg['body'], implode("\r\n", $msg['headers']))) {
                throw new ApiError(502, 'delivery_failed', 'The server mail agent could not send this email.');
            }
            return 'Server mail agent (PHP mail)';
        }
        if (!self::smtp($msg, $from)) {
            throw new ApiError(502, 'delivery_failed', 'The email could not be sent.');
        }
        return 'Microsoft 365 Exchange Online (TLS)';
    }

    private static function smtp(array $m, string $fromAddress): bool
    {
        $host = (string) Config::get('smtp_host');
        $port = (int) Config::get('smtp_port', 587);
        $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host]]);
        $s = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $context);
        if (!$s) {
            self::$lastError = "connect to {$host}:{$port} failed: {$errstr}";
            error_log('Payroll360 SMTP connect failed: ' . $errstr);
            return false;
        }
        stream_set_timeout($s, 30);
        $last = '';
        $expect = function (string $prefix) use ($s, &$last): bool {
            $last = '';
            while (($line = fgets($s, 515)) !== false) {
                $last .= $line;
                if (substr($line, 3, 1) === ' ') {
                    break;
                }
            }
            return strncmp($last, $prefix, strlen($prefix)) === 0;
        };
        $cmd = function (string $c, string $prefix) use ($s, $expect): bool {
            fwrite($s, $c . "\r\n");
            return $expect($prefix);
        };
        $fail = function (string $step) use ($s, &$last): bool {
            self::$lastError = "{$step}: " . (trim($last) ?: '(no response from server)');
            error_log("Payroll360 SMTP failed at {$step}: " . trim($last));
            @fwrite($s, "QUIT\r\n");
            fclose($s);
            return false;
        };
        $ehlo = 'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost');
        if (!$expect('220')) return $fail('greeting');
        if (!$cmd($ehlo, '250')) return $fail('EHLO');
        if (!$cmd('STARTTLS', '220')) return $fail('STARTTLS');
        $crypto = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
            $crypto |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
        }
        if (!@stream_socket_enable_crypto($s, true, $crypto)) return $fail('TLS');
        if (!$cmd($ehlo, '250')) return $fail('EHLO (TLS)');
        if (!$cmd('AUTH LOGIN', '334')) return $fail('AUTH');
        if (!$cmd(base64_encode((string) Config::get('smtp_user')), '334')) return $fail('AUTH user');
        if (!$cmd(base64_encode((string) Config::get('smtp_pass')), '235')) return $fail('AUTH password');
        if (!$cmd("MAIL FROM:<{$fromAddress}>", '250')) return $fail('MAIL FROM');
        if (!$cmd("RCPT TO:<{$m['to']}>", '25')) return $fail('RCPT TO');
        foreach ($m['headers'] as $h) {
            if (str_starts_with($h, 'Cc: <') && !$cmd('RCPT TO:<' . substr($h, 5, -1) . '>', '25')) return $fail('RCPT TO (cc)');
        }
        if (!$cmd('DATA', '354')) return $fail('DATA');
        $payload = implode("\r\n", array_merge($m['headers'], ["To: <{$m['to']}>", 'Subject: ' . self::word($m['subject'])])) . "\r\n\r\n" . $m['body'];
        $payload = preg_replace('/^\./m', '..', $payload);
        fwrite($s, $payload . "\r\n.\r\n");
        if (!$expect('250')) return $fail('message body');
        $cmd('QUIT', '221');
        fclose($s);
        return true;
    }
}
