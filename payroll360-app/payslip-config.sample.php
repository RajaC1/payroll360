<?php
/**
 * Copy this file to payslip-config.php and fill it in. Every value can also be set as an
 * environment variable (PAYSLIP360_DB, PAYSLIP360_KEY_PATH, PAYSLIP360_OUTBOX, PAYSLIP360_MAIL_TRANSPORT,
 * PAYSLIP360_SMTP_HOST, PAYSLIP360_SMTP_USER, PAYSLIP360_SMTP_PASS, PAYSLIP360_FROM_ADDRESS, PAYSLIP360_DEBUG=1).
 * Environment variables win over this file.
 *
 * payslip-config.php is blocked from web access. Do not commit it. Better still, keep it OUTSIDE the web
 * root and point PAYSLIP360_CONFIG at it.
 */
return [
    // Storage. Keep both OUTSIDE the web root in production and back them up together:
    // the key decrypts tax IDs and bank accounts, and losing it makes them unreadable.
    // 'db_path'  => '/var/lib/payslip360/payslip360.sqlite',
    // 'key_path' => '/var/lib/payslip360/app.key',

    // Email. transport: smtp | mail | file
    //   smtp = send through your mailbox (Microsoft 365 shown below)
    //   mail = the server's own mail agent
    //   file = TEST MODE: saves .eml files to outbox_dir and sends nothing
    'mail_transport' => 'smtp',
    'smtp_host' => 'smtp.office365.com',
    'smtp_port' => 587,
    'smtp_user' => 'no-reply@your-domain.com',
    'smtp_pass' => '',              // use an app password, never a personal password
    'from_address' => '',           // defaults to smtp_user
    // 'outbox_dir' => '/var/lib/payslip360/outbox',

    // Password reset links are built from this address, not from the request's Host header,
    // so set it to the public address users open in their browser.
    'public_url' => 'https://appz360.com/payroll360-app',

    // Sign-in
    'session_hours' => 8,
    'login_max_attempts' => 5,      // failed sign-ins allowed ...
    'login_window_minutes' => 15,   // ... in this many minutes
];
