<?php
declare(strict_types=1);

/**
 * Payroll360 command line tool.
 *
 *   php api/cli.php migrate
 *   php api/cli.php create-admin you@company.com "Your Name"      (password from PAYSLIP360_ADMIN_PASSWORD or prompt)
 *   php api/cli.php test-mail you@company.com                     (sends a real test email; prints the SMTP error if it fails)
 *   php api/cli.php seed-demo                                     (synthetic demo company; only on an empty database)
 *   php api/cli.php reset-demo --yes                              (deletes the database file, then seeds the demo)
 */

use P360\Assignments;
use P360\Auth;
use P360\Compensation;
use P360\Config;
use P360\Dates;
use P360\Db;
use P360\Departments;
use P360\Employees;
use P360\Mailer;
use P360\Entities;
use P360\Payroll;
use P360\ReferenceData;
use P360\Schedules;
use P360\Schema;
use P360\UserAdmin;
use P360\ControlDb;
use P360\Tenants;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

foreach (['Core', 'Schema', 'Auth', 'Engine', 'Reference', 'People', 'Payroll', 'Reports', 'Pdf', 'Mailer', 'Tenancy'] as $file) {
    require_once __DIR__ . '/src/' . $file . '.php';
}

/** Synthetic demo company. Every value here is fake. */
final class DemoSeed
{
    public const PASSWORD = 'Payslip360!Demo1';

    private static function logoDataUri(): ?string
    {
        $file = Config::root() . '/assets/company-logo.png';
        if (!is_file($file) || filesize($file) > 280000) {
            return null;
        }
        return 'data:image/png;base64,' . base64_encode((string) file_get_contents($file));
    }

    public static function run(): array
    {
        Schema::migrate();
        if (Auth::userCount() > 0) {
            throw new RuntimeException('This database already has users. Use "reset-demo --yes" to start over.');
        }
        ReferenceData::seedComponents();
        ReferenceData::seedTaxRules();
        $adminId = Auth::createUser('admin@demo.payslip360.test', 'Ada Admin', self::PASSWORD, 'ADMIN');
        Auth::actAs(['id' => $adminId, 'email' => 'admin@demo.payslip360.test', 'name' => 'Ada Admin', 'role' => 'ADMIN', 'employee_id' => null, 'active' => true]);

        $cid = fn(string $code) => (int) Db::val('SELECT id FROM components WHERE code = ?', [$code]);

        // ---- legal entities
        $us = Entities::create(['name' => 'Acme Cloud Technologies Inc.', 'legal_name' => 'Acme Cloud Technologies Inc.', 'country' => 'US',
            'address' => '100 Montgomery St, Suite 1800, San Francisco, CA 94104', 'payslip_title' => 'Pay Stub', 'sender_name' => 'Acme Cloud Payroll',
            'sender_email' => 'payroll@acmecloud.example', 'reply_to' => 'hr-helpdesk@acmecloud.example', 'cc' => 'payroll-records@acmecloud.example', 'logo' => self::logoDataUri()]);
        $uk = Entities::create(['name' => 'Acme Systems UK Limited', 'legal_name' => 'Acme Systems UK Limited', 'country' => 'GB',
            'address' => '25 Bank Street, Canary Wharf, London, E14 5JP', 'payslip_title' => 'Payslip', 'sender_name' => 'Acme UK Payroll', 'sender_email' => 'payroll@acmesystems.example']);
        $de = Entities::create(['name' => 'Acme Digital Europe GmbH', 'legal_name' => 'Acme Digital Europe GmbH', 'country' => 'DE',
            'address' => 'Friedrichstrasse 100, 10117 Berlin, Germany', 'payslip_title' => 'Gehaltsabrechnung / Payslip', 'sender_name' => 'Acme Europe Payroll', 'sender_email' => 'payroll@acmedigital.example']);
        $in = Entities::create(['name' => 'Acme Software India Pvt. Ltd.', 'legal_name' => 'Acme Software India Pvt. Ltd.', 'country' => 'IN',
            'address' => 'Level 9, Cyber City, DLF Phase 2, Gurugram, Haryana 122002', 'payslip_title' => 'Salary slip', 'sender_name' => 'Acme India Payroll', 'sender_email' => 'payroll@acme-india.example']);

        // ---- departments
        $dept = [];
        foreach (['Growth & Product', 'Engineering', 'Commercial Sales', 'Core Platform', 'Cloud Platform', 'People & HR', 'Finance', 'Customer Success', 'Support'] as $d) {
            $dept[$d] = Departments::create(['name' => $d])['id'];
        }

        // ---- schedules
        $sUS = Schedules::create(['name' => 'US monthly payroll', 'frequency' => 'monthly', 'entity_id' => $us['id'], 'cutoff_offset_days' => -10, 'pay_offset_days' => -5]);
        $sUK = Schedules::create(['name' => 'UK monthly payroll', 'frequency' => 'monthly', 'entity_id' => $uk['id'], 'cutoff_offset_days' => -8, 'pay_offset_days' => -3]);
        $sDE = Schedules::create(['name' => 'Germany monthly payroll', 'frequency' => 'monthly', 'entity_id' => $de['id'], 'cutoff_offset_days' => -10, 'pay_offset_days' => -5]);
        $sIN = Schedules::create(['name' => 'India monthly payroll', 'frequency' => 'monthly', 'entity_id' => $in['id'], 'cutoff_offset_days' => -5, 'pay_offset_days' => 0]);
        $sBW = Schedules::create(['name' => 'US biweekly payroll (hourly)', 'frequency' => 'biweekly', 'entity_id' => $us['id'], 'anchor_date' => '2026-01-05', 'cutoff_offset_days' => -4, 'pay_offset_days' => 5]);

        $n = 0;
        $bank = function (string $name) use (&$n): array {
            $n++;
            return ['tax_id' => sprintf('DEMO-TAX-%04d', $n), 'bank_name' => $name, 'bank_account' => sprintf('DEMO-ACCT-%06d', 1000 + $n)];
        };
        $mk = function (array $e) {
            return Employees::create($e);
        };
        $health = fn(string $emp, string $er) => ['component_id' => (int) Db::val("SELECT id FROM components WHERE code = 'BEN_HEALTH'"), 'amount' => $emp, 'employer_amount' => $er, 'frequency' => 'monthly', 'effective_from' => '2025-01-01', 'provider' => 'Northwind Health'];
        $comp = fn(string $code, string $amt, string $desc = '') => ['component_id' => $cid($code), 'amount' => $amt, 'frequency' => 'monthly', 'effective_from' => '2025-01-01', 'description' => $desc];
        $pension = fn(string $pct) => ['component_id' => $cid('RETIREMENT'), 'rate_pct' => $pct, 'frequency' => 'per_period', 'effective_from' => '2025-01-01'];

        // ---- US (California)
        $marcus = $mk(['first_name' => 'Marcus', 'last_name' => 'Brody', 'work_email' => 'marcus.brody@acmecloud.example', 'entity_id' => $us['id'], 'department_id' => $dept['Engineering'],
            'job_title' => 'Lead Data Platform Architect', 'employment_type' => 'FULL_TIME', 'joining_date' => '2022-03-14', 'work_location' => 'San Francisco, CA', 'country' => 'US', 'state_region' => 'CA',
            'payroll_frequency' => 'monthly', 'compensation' => ['pay_basis' => 'annual', 'base' => '138000.00', 'effective_from' => '2025-01-01'],
            'components' => [$comp('HOUSING', '800.00'), $comp('INTERNET', '100.00'), $health('210.00', '450.00'), $pension('5')]] + $bank('Chase Bank'));
        $sarah = $mk(['first_name' => 'Sarah', 'last_name' => 'Jenkins', 'work_email' => 'sarah.jenkins@acmecloud.example', 'personal_email' => 'sarah.j@example.com', 'phone' => '+1 415 555 0142',
            'entity_id' => $us['id'], 'department_id' => $dept['Growth & Product'], 'job_title' => 'Senior Product Marketing Manager', 'employment_type' => 'FULL_TIME',
            'joining_date' => '2021-06-01', 'work_location' => 'Remote (CA)', 'country' => 'US', 'state_region' => 'CA', 'payroll_frequency' => 'monthly',
            'compensation' => ['pay_basis' => 'annual', 'base' => '104000.00', 'effective_from' => '2025-01-01'],
            'components' => [$comp('OTHER_ALLOW', '250.00', 'Remote work allowance'), $health('210.00', '450.00'), $pension('5'),
                ['component_id' => $cid('BEN_RETIRE'), 'employer_rate_pct' => '3', 'frequency' => 'per_period', 'effective_from' => '2025-01-01', 'provider' => 'Vanguard 401(k)']]] + $bank('Chase Bank'));
        Compensation::change((int) $sarah['id'], ['pay_basis' => 'annual', 'base' => '114000.00', 'effective_from' => '2026-01-01', 'reason' => 'Annual performance review']);
        $david = $mk(['first_name' => 'David', 'last_name' => 'Chen', 'work_email' => 'david.chen@acmecloud.example', 'entity_id' => $us['id'], 'department_id' => $dept['Cloud Platform'],
            'manager_id' => $marcus['id'], 'job_title' => 'DevOps Engineer', 'employment_type' => 'FULL_TIME', 'joining_date' => '2023-02-20', 'work_location' => 'San Francisco, CA',
            'country' => 'US', 'state_region' => 'CA', 'payroll_frequency' => 'monthly', 'compensation' => ['pay_basis' => 'annual', 'base' => '132000.00', 'effective_from' => '2025-01-01'],
            'components' => [$comp('TRANSPORT', '200.00'), $health('210.00', '450.00'), $pension('4')]] + $bank('Wells Fargo'));
        $amanda = $mk(['first_name' => 'Amanda', 'last_name' => 'Vance', 'work_email' => 'amanda.vance@acmecloud.example', 'entity_id' => $us['id'], 'department_id' => $dept['Customer Success'],
            'manager_id' => $sarah['id'], 'job_title' => 'Enterprise Customer Success Lead', 'employment_type' => 'FULL_TIME', 'joining_date' => '2023-09-05', 'work_location' => 'Austin, TX',
            'country' => 'US', 'state_region' => 'TX', 'payroll_frequency' => 'monthly', 'compensation' => ['pay_basis' => 'annual', 'base' => '105600.00', 'effective_from' => '2025-01-01'],
            'components' => [$comp('MEAL', '150.00'), $health('180.00', '420.00')]] + $bank('Bank of America'));
        $mk(['first_name' => 'Priya', 'last_name' => 'Nair', 'work_email' => 'priya.nair@acmecloud.example', 'entity_id' => $us['id'], 'department_id' => $dept['Support'],
            'job_title' => 'Support Specialist', 'employment_type' => 'PART_TIME', 'joining_date' => '2024-04-01', 'work_location' => 'Austin, TX', 'country' => 'US', 'state_region' => 'TX',
            'payroll_frequency' => 'biweekly', 'compensation' => ['pay_basis' => 'hourly', 'base' => '42.50', 'effective_from' => '2025-01-01', 'overtime_multiplier_pct' => '150'],
            'components' => []] + $bank('Citibank'));

        // ---- UK
        $oliver = $mk(['first_name' => 'Oliver', 'last_name' => 'Harrison', 'work_email' => 'oliver.harrison@acmesystems.example', 'entity_id' => $uk['id'], 'department_id' => $dept['Engineering'],
            'job_title' => 'Lead Cloud Solutions Architect', 'employment_type' => 'FULL_TIME', 'joining_date' => '2021-11-08', 'work_location' => 'London', 'country' => 'GB',
            'payroll_frequency' => 'monthly', 'compensation' => ['pay_basis' => 'annual', 'base' => '84000.00', 'effective_from' => '2025-01-01'],
            'components' => [$comp('OTHER_ALLOW', '450.00', 'On-call allowance'), $comp('TRANSPORT', '300.00', 'Car allowance'), $pension('5'), $comp('LOAN', '285.00', 'Student loan (Plan 2)')]] + $bank('Barclays'));
        $mk(['first_name' => 'Emma', 'last_name' => 'Watson', 'work_email' => 'emma.watson@acmesystems.example', 'entity_id' => $uk['id'], 'department_id' => $dept['People & HR'], 'manager_id' => $oliver['id'],
            'job_title' => 'HR Operations Lead', 'employment_type' => 'FULL_TIME', 'joining_date' => '2022-09-01', 'work_location' => 'London', 'country' => 'GB', 'payroll_frequency' => 'monthly',
            'compensation' => ['pay_basis' => 'annual', 'base' => '62000.00', 'effective_from' => '2025-01-01'], 'components' => [$pension('5')]] + $bank('HSBC'));

        // ---- Germany
        $mk(['first_name' => 'Matthias', 'last_name' => 'Weber', 'work_email' => 'matthias.weber@acmedigital.example', 'entity_id' => $de['id'], 'department_id' => $dept['Commercial Sales'],
            'job_title' => 'Senior Enterprise Account Executive', 'employment_type' => 'FULL_TIME', 'joining_date' => '2022-01-10', 'work_location' => 'Berlin', 'country' => 'DE',
            'payroll_frequency' => 'monthly', 'compensation' => ['pay_basis' => 'annual', 'base' => '86400.00', 'effective_from' => '2025-01-01'],
            'components' => [$comp('TRAVEL', '350.00', 'Mobility allowance'), ['component_id' => $cid('COMMISSION'), 'rate_pct' => '3', 'frequency' => 'per_period', 'effective_from' => '2025-01-01']]] + $bank('ING'));
        $mk(['first_name' => 'Anna', 'last_name' => 'Becker', 'work_email' => 'anna.becker@acmedigital.example', 'entity_id' => $de['id'], 'department_id' => $dept['Finance'],
            'job_title' => 'Finance Manager', 'employment_type' => 'FULL_TIME', 'joining_date' => '2023-05-02', 'work_location' => 'Berlin', 'country' => 'DE', 'payroll_frequency' => 'monthly',
            'compensation' => ['pay_basis' => 'annual', 'base' => '78000.00', 'effective_from' => '2025-01-01'], 'components' => []] + $bank('Deutsche Bank'));

        // ---- India
        $mk(['first_name' => 'Rohan', 'last_name' => 'Varma', 'work_email' => 'rohan.varma@acme-india.example', 'entity_id' => $in['id'], 'department_id' => $dept['Core Platform'],
            'job_title' => 'Staff Software Engineer', 'employment_type' => 'FULL_TIME', 'joining_date' => '2020-08-17', 'work_location' => 'Gurugram', 'country' => 'IN', 'payroll_frequency' => 'monthly',
            'compensation' => ['pay_basis' => 'annual', 'base' => '1380000.00', 'effective_from' => '2025-01-01'],
            'components' => [$comp('OTHER_ALLOW', '57500.00', 'House rent allowance (HRA)'), $comp('OTHER_EARN', '48000.00', 'Special allowance'), $comp('INSURANCE', '1200.00', 'Group health insurance')]] + $bank('HDFC Bank'));
        $mk(['first_name' => 'Meera', 'last_name' => 'Iyer', 'work_email' => 'meera.iyer@acme-india.example', 'entity_id' => $in['id'], 'department_id' => $dept['Engineering'],
            'job_title' => 'Product Designer', 'employment_type' => 'FULL_TIME', 'joining_date' => '2022-12-05', 'work_location' => 'Gurugram', 'country' => 'IN', 'payroll_frequency' => 'monthly',
            'compensation' => ['pay_basis' => 'annual', 'base' => '1800000.00', 'effective_from' => '2025-01-01'], 'components' => []] + $bank('ICICI Bank'));

        // ---- logins for each role
        $users = [
            ['payroll@demo.payslip360.test', 'Pat Payroll', 'PAYROLL_ADMIN', null],
            ['hr@demo.payslip360.test', 'Hana HR', 'HR_ADMIN', null],
            ['manager@demo.payslip360.test', 'Marcus Brody (manager)', 'MANAGER', (int) $marcus['id']],
            ['employee@demo.payslip360.test', 'Sarah Jenkins', 'EMPLOYEE', (int) $sarah['id']],
            ['employee2@demo.payslip360.test', 'David Chen', 'EMPLOYEE', (int) $david['id']],
        ];
        foreach ($users as [$email, $name, $role, $empId]) {
            UserAdmin::create(['email' => $email, 'name' => $name, 'password' => self::PASSWORD, 'role' => $role, 'employee_id' => $empId]);
        }

        // ---- two months of finished payroll history (so reports, YTD and analytics have real data)
        $today = Dates::today();
        foreach ([Dates::addMonths($today, -2), Dates::addMonths($today, -1)] as $start) {
            foreach ([$sUS, $sUK, $sDE, $sIN] as $s) {
                $run = Payroll::create(['schedule_id' => $s['id'], 'period_start' => $start]);
                Payroll::calculate((int) $run['id']);
                Payroll::submit((int) $run['id']);
                Payroll::approve((int) $run['id'], 'Approved (demo history)');
                Payroll::process((int) $run['id']);
                Payroll::markPaid((int) $run['id']);
            }
        }

        // ---- employees with problems, added after the history so the current payroll shows exceptions
        $recent = Dates::add($today, -20);
        $mk(['first_name' => 'Zoe', 'last_name' => 'Carter', 'work_email' => 'zoe.carter@acmecloud.example', 'entity_id' => $us['id'], 'department_id' => $dept['Growth & Product'],
            'job_title' => 'Marketing Coordinator', 'employment_type' => 'FULL_TIME', 'joining_date' => $recent, 'work_location' => 'Remote (NY)', 'country' => 'US', 'state_region' => 'NY',
            'payroll_frequency' => 'monthly', 'compensation' => ['pay_basis' => 'annual', 'base' => '66000.00', 'effective_from' => $recent], 'components' => []]);
        $mk(['first_name' => 'Liam', 'last_name' => 'Foster', 'work_email' => 'liam.foster@acmesystems.example', 'entity_id' => $uk['id'], 'department_id' => $dept['Engineering'],
            'job_title' => 'Software Engineer', 'employment_type' => 'FULL_TIME', 'joining_date' => Dates::add($today, -40), 'work_location' => 'London', 'country' => 'GB',
            'payroll_frequency' => 'monthly', 'components' => []] + $bank('Lloyds Bank'));
        $mk(['first_name' => 'Ivy', 'last_name' => 'Lopez', 'work_email' => 'ivy.lopez@acmecloud.example', 'entity_id' => $us['id'], 'department_id' => $dept['Support'],
            'job_title' => 'Support Associate', 'employment_type' => 'FULL_TIME', 'joining_date' => Dates::add($today, -200), 'work_location' => 'Austin, TX', 'country' => 'US', 'state_region' => 'TX',
            'payroll_frequency' => 'monthly', 'compensation' => ['pay_basis' => 'annual', 'base' => '30000.00', 'effective_from' => Dates::add($today, -200)],
            'components' => [$comp('LOAN', '2400.00', 'Salary advance repayment')]] + $bank('Chase Bank'));

        Auth::actAs(null);
        return [
            'password' => self::PASSWORD,
            'users' => array_merge([['admin@demo.payslip360.test', 'ADMIN']], array_map(fn($u) => [$u[0], $u[2]], $users)),
            'history_months' => 2,
        ];
    }
}

$cmd = $argv[1] ?? 'help';
try {
    switch ($cmd) {
        case 'migrate':
            Schema::migrate();
            echo "Database is up to date: " . Config::get('db_path') . "\n";
            break;

        case 'create-admin':
            $email = strtolower((string) ($argv[2] ?? ''));
            $name = (string) ($argv[3] ?? 'Administrator');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                fwrite(STDERR, "Usage: php api/cli.php create-admin you@company.com \"Your Name\"\n");
                exit(1);
            }
            $pw = getenv('PAYSLIP360_ADMIN_PASSWORD');
            if ($pw === false || $pw === '') {
                echo 'Password (min 10 characters, with a letter and a number): ';
                $pw = trim((string) fgets(STDIN));
            }
            Schema::migrate();
            Auth::createUser($email, $name, $pw, 'ADMIN');
            ReferenceData::seedComponents();
            echo "Administrator {$email} created.\n";
            break;

        case 'test-mail':
            $to = (string) ($argv[2] ?? '');
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                fwrite(STDERR, "Usage: php api/cli.php test-mail you@company.com\n");
                exit(1);
            }
            $cfg = Config::all();
            echo 'transport=' . $cfg['mail_transport'] . ' host=' . ($cfg['smtp_host'] ?? '') . ' port=' . ($cfg['smtp_port'] ?? '')
                . ' user=' . ($cfg['smtp_user'] ?? '(not set)') . ' from=' . ($cfg['from_address'] ?: ($cfg['smtp_user'] ?? '(not set)')) . "\n";
            try {
                $result = Mailer::sendPlain($to, 'Payroll360 test email', '<p>This is a test email sent from the Payroll360 command line.</p>', 'Payroll360');
                echo "Sent OK: {$result}\n";
            } catch (\Throwable $e) {
                echo 'FAILED: ' . $e->getMessage() . "\n";
                $detail = Mailer::lastError();
                if ($detail) {
                    echo "Server said: {$detail}\n";
                }
            }
            break;

        case 'seed-demo':
            $r = DemoSeed::run();
            echo "Demo data loaded. Password for every demo user: {$r['password']}\n";
            foreach ($r['users'] as [$e, $role]) {
                echo str_pad($role, 14) . $e . "\n";
            }
            break;

        case 'reset-demo':
            if (!in_array('--yes', $argv, true)) {
                fwrite(STDERR, "This DELETES the database file. Re-run with --yes to confirm.\n");
                exit(1);
            }
            $path = (string) Config::get('db_path');
            Db::reset();
            foreach ([$path, $path . '-wal', $path . '-shm'] as $f) {
                if (is_file($f)) {
                    unlink($f);
                }
            }
            $r = DemoSeed::run();
            echo "Database reset and demo data loaded. Password for every demo user: {$r['password']}\n";
            break;

        case 'list-tenants':
            if (!ControlDb::enabled()) {
                echo "Multi-tenant mode is off (set PAYSLIP360_MULTITENANT=1 to turn on self-service sign-up).\n";
                break;
            }
            $rows = Tenants::list();
            if (!$rows) {
                echo "No tenants yet.\n";
                break;
            }
            foreach ($rows as $t) {
                echo str_pad($t['slug'], 24) . str_pad($t['status'], 10) . str_pad($t['plan'], 8) . str_pad($t['company_name'], 28) . $t['admin_email'] . ' (' . $t['created_at'] . ")\n";
            }
            echo count($rows) . " tenant(s).\n";
            break;

        case 'backup':
            $out = null;
            foreach ($argv as $a) {
                if (str_starts_with((string) $a, '--out=')) {
                    $out = substr((string) $a, 6);
                }
            }
            $dbPath = (string) Config::get('db_path');
            $keyPath = (string) Config::get('key_path');
            if (!is_file($dbPath)) {
                fwrite(STDERR, "No database found at {$dbPath}.\n");
                exit(1);
            }
            $dir = $out ?: (dirname($dbPath) . '/backups');
            if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
                fwrite(STDERR, "Could not create backup folder: {$dir}\n");
                exit(1);
            }
            $stamp = gmdate('Ymd-His');
            $snap = $dir . '/' . $stamp;
            mkdir($snap, 0700);
            // VACUUM INTO takes a consistent, uncorrupted snapshot even while the live app is reading or writing
            // (safe with WAL mode). A raw file copy of a live SQLite database is not safe and can copy a torn write.
            Db::pdo()->exec("VACUUM INTO '" . str_replace("'", "''", $snap . '/payslip360.sqlite') . "'");
            if (is_file($keyPath)) {
                copy($keyPath, $snap . '/app.key');
            } else {
                fwrite(STDERR, "Warning: no encryption key found at {$keyPath}. Without it, tax IDs and bank accounts in this backup cannot be read back.\n");
            }
            $size = filesize($snap . '/payslip360.sqlite');
            echo "Backup written to {$snap} (" . number_format($size / 1024, 1) . " KB).\n";
            // Retention: keep the newest 30 snapshots, remove older ones.
            $keep = 30;
            $snaps = glob($dir . '/*', GLOB_ONLYDIR) ?: [];
            usort($snaps, fn($a, $b) => strcmp(basename($b), basename($a)));
            foreach (array_slice($snaps, $keep) as $old) {
                foreach ((glob($old . '/*') ?: []) as $f) {
                    unlink($f);
                }
                rmdir($old);
            }
            if (count($snaps) > $keep) {
                echo 'Removed ' . (count($snaps) - $keep) . " backup(s) older than the newest {$keep}.\n";
            }
            break;

        case 'restore':
            $from = $argv[2] ?? null;
            if (!$from || !is_dir($from) || !is_file($from . '/payslip360.sqlite')) {
                fwrite(STDERR, "Usage: php api/cli.php restore <backup-folder> --yes\n");
                exit(1);
            }
            if (!in_array('--yes', $argv, true)) {
                fwrite(STDERR, "This REPLACES the current database with the backup. Stop the app first, then re-run with --yes to confirm.\n");
                exit(1);
            }
            $dbPath = (string) Config::get('db_path');
            $keyPath = (string) Config::get('key_path');
            Db::reset();
            foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $f) {
                if (is_file($f)) {
                    unlink($f);
                }
            }
            copy($from . '/payslip360.sqlite', $dbPath);
            if (is_file($from . '/app.key')) {
                copy($from . '/app.key', $keyPath);
            } else {
                fwrite(STDERR, "Note: this backup has no app.key. If the live key differs, existing tax IDs and bank accounts will not decrypt.\n");
            }
            echo "Restored {$dbPath} from {$from}.\n";
            break;

        default:
            echo "Commands: migrate | create-admin <email> <name> | seed-demo | reset-demo --yes | backup [--out=dir] | restore <backup-folder> --yes | list-tenants\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    if ($e instanceof P360\ApiError && $e->fields) {
        fwrite(STDERR, json_encode($e->fields) . "\n");
    }
    exit(1);
}
