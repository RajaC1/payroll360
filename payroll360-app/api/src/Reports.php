<?php
declare(strict_types=1);

namespace P360;

/**
 * Dashboard, reports and analytics. Everything is computed from finalised payroll (PROCESSED / PAID) in the database.
 * Totals are always grouped by currency: amounts in different currencies are never added together or converted.
 */
final class Reports
{
    public const TYPES = ['summary', 'gross_to_net', 'earnings', 'deductions', 'taxes', 'department', 'employee_history', 'cost'];

    private static function filters(array $q): array
    {
        $where = ["pe.included = 1", "r.status IN ('PROCESSED','PAID')"];
        $p = [];
        if (!empty($q['from'])) {
            $where[] = 'r.pay_date >= ?';
            $p[] = $q['from'];
        }
        if (!empty($q['to'])) {
            $where[] = 'r.pay_date <= ?';
            $p[] = $q['to'];
        }
        foreach (['employee_id' => 'pe.employee_id', 'department' => 'pe.department_name', 'country' => 'pe.country', 'currency' => 'r.currency', 'entity_id' => 'r.entity_id'] as $k => $col) {
            if (!empty($q[$k])) {
                $where[] = $col . ' = ?';
                $p[] = $q[$k];
            }
        }
        foreach (['from', 'to'] as $d) {
            if (!empty($q[$d]) && !Dates::valid((string) $q[$d])) {
                throw new ApiError(422, 'validation_failed', 'Dates must be in YYYY-MM-DD format.', [$d => 'Invalid date.']);
            }
        }
        return [implode(' AND ', $where), $p];
    }

    private static function col(string $key, string $label, string $type = 'text'): array
    {
        return ['key' => $key, 'label' => $label, 'type' => $type];
    }

    /** @param array<int,array> $rows rows carrying integer minor amounts under $sumKeys */
    private static function totalsByCurrency(array $rows, array $sumKeys): array
    {
        $by = [];
        foreach ($rows as $r) {
            $c = $r['_cur'];
            foreach ($sumKeys as $k) {
                $by[$c][$k] = ($by[$c][$k] ?? 0) + (int) $r['_' . $k];
            }
            $by[$c]['_n'] = ($by[$c]['_n'] ?? 0) + 1;
        }
        $out = [];
        foreach ($by as $cur => $sums) {
            $row = ['currency' => $cur];
            foreach ($sumKeys as $k) {
                $row[$k] = Money::fmt((int) $sums[$k], $cur);
            }
            $out[] = $row;
        }
        return $out;
    }

    public static function run(string $type, array $q): array
    {
        Auth::require('reports.read');
        if (!in_array($type, self::TYPES, true)) {
            throw new ApiError(404, 'not_found', 'Unknown report.');
        }
        [$w, $p] = self::filters($q);
        $m = fn(int|string|null $v, string $c) => Money::fmt((int) $v, $c);

        switch ($type) {
            case 'summary':
                $rows = Db::all(
                    "SELECT r.id, r.name, r.pay_date, r.period_start, r.period_end, r.currency, COUNT(pe.id) AS employees, SUM(pe.gross_minor) AS g, SUM(pe.taxes_minor) AS t,
                            SUM(pe.benefits_minor) AS b, SUM(pe.deductions_minor) AS d, SUM(pe.net_minor) AS n, SUM(pe.employer_contrib_minor) AS ec, SUM(pe.employer_cost_minor) AS ecost
                       FROM payroll_employees pe JOIN payroll_runs r ON r.id = pe.run_id WHERE {$w} GROUP BY r.id ORDER BY r.pay_date DESC, r.id DESC",
                    $p
                );
                $cols = [self::col('name', 'Payroll'), self::col('pay_date', 'Pay date', 'date'), self::col('currency', 'Currency'), self::col('employees', 'Employees', 'int'),
                    self::col('gross', 'Gross pay', 'money'), self::col('taxes', 'Taxes', 'money'), self::col('benefits', 'Benefits', 'money'),
                    self::col('deductions', 'Deductions', 'money'), self::col('net', 'Net pay', 'money'), self::col('employer_contrib', 'Employer contributions', 'money'),
                    self::col('employer_cost', 'Employer cost', 'money')];
                $out = array_map(fn($r) => [
                    'name' => $r['name'], 'pay_date' => $r['pay_date'], 'currency' => $r['currency'], 'employees' => (int) $r['employees'],
                    'gross' => $m($r['g'], $r['currency']), 'taxes' => $m($r['t'], $r['currency']), 'benefits' => $m($r['b'], $r['currency']),
                    'deductions' => $m($r['d'], $r['currency']), 'net' => $m($r['n'], $r['currency']), 'employer_contrib' => $m($r['ec'], $r['currency']),
                    'employer_cost' => $m($r['ecost'], $r['currency']),
                    '_cur' => $r['currency'], '_gross' => $r['g'], '_taxes' => $r['t'], '_benefits' => $r['b'], '_deductions' => $r['d'], '_net' => $r['n'],
                    '_employer_contrib' => $r['ec'], '_employer_cost' => $r['ecost'],
                ], $rows);
                $keys = ['gross', 'taxes', 'benefits', 'deductions', 'net', 'employer_contrib', 'employer_cost'];
                return self::wrap($type, 'Payroll summary', $cols, $out, self::totalsByCurrency($out, $keys));

            case 'gross_to_net':
                $rows = Db::all(
                    "SELECT pe.*, r.pay_date, r.period_start, r.period_end, r.currency, e.employee_no, e.first_name, e.last_name, e.middle_name
                       FROM payroll_employees pe JOIN payroll_runs r ON r.id = pe.run_id JOIN employees e ON e.id = pe.employee_id
                      WHERE {$w} ORDER BY r.pay_date DESC, e.last_name, e.first_name",
                    $p
                );
                $cols = [self::col('employee', 'Employee'), self::col('employee_no', 'Employee ID'), self::col('department', 'Department'), self::col('period', 'Pay period'),
                    self::col('pay_date', 'Pay date', 'date'), self::col('currency', 'Currency'), self::col('base', 'Base pay', 'money'), self::col('allowances', 'Allowances', 'money'),
                    self::col('gross', 'Gross pay', 'money'), self::col('taxes', 'Taxes', 'money'), self::col('benefits', 'Benefits', 'money'),
                    self::col('deductions', 'Deductions', 'money'), self::col('net', 'Net pay', 'money')];
                $out = array_map(fn($r) => [
                    'employee' => Employees::fullName($r), 'employee_no' => $r['employee_no'], 'department' => $r['department_name'],
                    'period' => $r['period_start'] . ' to ' . $r['period_end'], 'pay_date' => $r['pay_date'], 'currency' => $r['currency'],
                    'base' => $m($r['base_minor'], $r['currency']), 'allowances' => $m($r['allowances_minor'], $r['currency']), 'gross' => $m($r['gross_minor'], $r['currency']),
                    'taxes' => $m($r['taxes_minor'], $r['currency']), 'benefits' => $m($r['benefits_minor'], $r['currency']), 'deductions' => $m($r['deductions_minor'], $r['currency']),
                    'net' => $m($r['net_minor'], $r['currency']),
                    '_cur' => $r['currency'], '_gross' => $r['gross_minor'], '_taxes' => $r['taxes_minor'], '_benefits' => $r['benefits_minor'],
                    '_deductions' => $r['deductions_minor'], '_net' => $r['net_minor'],
                ], $rows);
                return self::wrap($type, 'Gross-to-net report', $cols, $out, self::totalsByCurrency($out, ['gross', 'taxes', 'benefits', 'deductions', 'net']));

            case 'earnings':
            case 'deductions':
            case 'taxes':
                $kinds = ['earnings' => ["'earning'"], 'deductions' => ["'deduction'", "'benefit'"], 'taxes' => ["'tax'"]][$type];
                $rows = Db::all(
                    "SELECT substr(r.pay_date, 1, 7) AS period, l.kind, l.name, r.currency, COUNT(DISTINCT pe.employee_id) AS employees, SUM(l.amount_minor) AS total, SUM(l.employer_minor) AS employer
                       FROM payroll_lines l JOIN payroll_employees pe ON pe.id = l.payroll_employee_id JOIN payroll_runs r ON r.id = pe.run_id
                      WHERE l.kind IN (" . implode(',', $kinds) . ") AND {$w} GROUP BY period, l.kind, l.name, r.currency ORDER BY period DESC, total DESC",
                    $p
                );
                $cols = [self::col('period', 'Month'), self::col('name', ucfirst($type === 'taxes' ? 'Tax' : ($type === 'earnings' ? 'Earning' : 'Deduction / benefit'))),
                    self::col('currency', 'Currency'), self::col('employees', 'Employees', 'int'), self::col('total', 'Employee amount', 'money')];
                if ($type === 'taxes') {
                    $cols[] = self::col('employer', 'Employer amount', 'money');
                }
                $out = array_map(fn($r) => [
                    'period' => $r['period'], 'name' => $r['name'], 'kind' => $r['kind'], 'currency' => $r['currency'], 'employees' => (int) $r['employees'],
                    'total' => $m($r['total'], $r['currency']), 'employer' => $m($r['employer'], $r['currency']),
                    '_cur' => $r['currency'], '_total' => $r['total'], '_employer' => $r['employer'],
                ], $rows);
                $titles = ['earnings' => 'Earnings report', 'deductions' => 'Deduction and benefit report', 'taxes' => 'Tax report'];
                return self::wrap($type, $titles[$type], $cols, $out, self::totalsByCurrency($out, $type === 'taxes' ? ['total', 'employer'] : ['total']));

            case 'department':
                $rows = Db::all(
                    "SELECT COALESCE(pe.department_name, 'No department') AS dept, r.currency, COUNT(DISTINCT pe.employee_id) AS employees, SUM(pe.gross_minor) AS g,
                            SUM(pe.taxes_minor) AS t, SUM(pe.benefits_minor + pe.deductions_minor) AS d, SUM(pe.net_minor) AS n, SUM(pe.employer_cost_minor) AS ecost
                       FROM payroll_employees pe JOIN payroll_runs r ON r.id = pe.run_id WHERE {$w} GROUP BY dept, r.currency ORDER BY r.currency, g DESC",
                    $p
                );
                $cols = [self::col('department', 'Department'), self::col('currency', 'Currency'), self::col('employees', 'Employees', 'int'), self::col('gross', 'Gross pay', 'money'),
                    self::col('taxes', 'Taxes', 'money'), self::col('deductions', 'Deductions and benefits', 'money'), self::col('net', 'Net pay', 'money'), self::col('employer_cost', 'Employer cost', 'money')];
                $out = array_map(fn($r) => [
                    'department' => $r['dept'], 'currency' => $r['currency'], 'employees' => (int) $r['employees'], 'gross' => $m($r['g'], $r['currency']),
                    'taxes' => $m($r['t'], $r['currency']), 'deductions' => $m($r['d'], $r['currency']), 'net' => $m($r['n'], $r['currency']), 'employer_cost' => $m($r['ecost'], $r['currency']),
                    '_cur' => $r['currency'], '_gross' => $r['g'], '_taxes' => $r['t'], '_deductions' => $r['d'], '_net' => $r['n'], '_employer_cost' => $r['ecost'],
                ], $rows);
                return self::wrap($type, 'Department payroll report', $cols, $out, self::totalsByCurrency($out, ['gross', 'taxes', 'deductions', 'net', 'employer_cost']));

            case 'employee_history':
                if (empty($q['employee_id'])) {
                    throw new ApiError(422, 'validation_failed', 'Choose an employee for this report.', ['employee_id' => 'Required for this report.']);
                }
                $rows = Db::all(
                    "SELECT pe.*, r.name AS run_name, r.pay_date, r.period_start, r.period_end, r.currency, e.employee_no, e.first_name, e.last_name, e.middle_name
                       FROM payroll_employees pe JOIN payroll_runs r ON r.id = pe.run_id JOIN employees e ON e.id = pe.employee_id
                      WHERE {$w} ORDER BY r.pay_date DESC",
                    $p
                );
                $cols = [self::col('employee', 'Employee'), self::col('period', 'Pay period'), self::col('pay_date', 'Pay date', 'date'), self::col('currency', 'Currency'),
                    self::col('gross', 'Gross pay', 'money'), self::col('taxes', 'Taxes', 'money'), self::col('benefits', 'Benefits', 'money'),
                    self::col('deductions', 'Deductions', 'money'), self::col('net', 'Net pay', 'money')];
                $out = array_map(fn($r) => [
                    'employee' => Employees::fullName($r) . ' (' . $r['employee_no'] . ')', 'period' => $r['period_start'] . ' to ' . $r['period_end'], 'pay_date' => $r['pay_date'],
                    'currency' => $r['currency'], 'gross' => $m($r['gross_minor'], $r['currency']), 'taxes' => $m($r['taxes_minor'], $r['currency']),
                    'benefits' => $m($r['benefits_minor'], $r['currency']), 'deductions' => $m($r['deductions_minor'], $r['currency']), 'net' => $m($r['net_minor'], $r['currency']),
                    '_cur' => $r['currency'], '_gross' => $r['gross_minor'], '_taxes' => $r['taxes_minor'], '_benefits' => $r['benefits_minor'],
                    '_deductions' => $r['deductions_minor'], '_net' => $r['net_minor'],
                ], $rows);
                return self::wrap($type, 'Employee payroll history', $cols, $out, self::totalsByCurrency($out, ['gross', 'taxes', 'benefits', 'deductions', 'net']));

            case 'cost':
                $rows = Db::all(
                    "SELECT substr(r.pay_date, 1, 7) AS period, r.currency, COUNT(DISTINCT pe.employee_id) AS employees, SUM(pe.gross_minor) AS g, SUM(pe.employer_contrib_minor) AS ec,
                            SUM(pe.employer_cost_minor) AS ecost
                       FROM payroll_employees pe JOIN payroll_runs r ON r.id = pe.run_id WHERE {$w} GROUP BY period, r.currency ORDER BY period DESC",
                    $p
                );
                $cols = [self::col('period', 'Month'), self::col('currency', 'Currency'), self::col('employees', 'Employees', 'int'), self::col('gross', 'Gross pay', 'money'),
                    self::col('employer_contrib', 'Employer contributions', 'money'), self::col('employer_cost', 'Total employer cost', 'money'),
                    self::col('cost_per_employee', 'Cost per employee', 'money')];
                $out = array_map(fn($r) => [
                    'period' => $r['period'], 'currency' => $r['currency'], 'employees' => (int) $r['employees'], 'gross' => $m($r['g'], $r['currency']),
                    'employer_contrib' => $m($r['ec'], $r['currency']), 'employer_cost' => $m($r['ecost'], $r['currency']),
                    'cost_per_employee' => $m(Money::divRound((int) $r['ecost'], max(1, (int) $r['employees'])), $r['currency']),
                    '_cur' => $r['currency'], '_gross' => $r['g'], '_employer_contrib' => $r['ec'], '_employer_cost' => $r['ecost'],
                ], $rows);
                return self::wrap($type, 'Payroll cost report', $cols, $out, self::totalsByCurrency($out, ['gross', 'employer_contrib', 'employer_cost']));
        }
        throw new ApiError(404, 'not_found', 'Unknown report.');
    }

    private static function wrap(string $type, string $title, array $cols, array $rows, array $totals): array
    {
        $clean = array_map(fn($r) => array_filter($r, fn($k) => $k[0] !== '_', ARRAY_FILTER_USE_KEY), $rows);
        return ['type' => $type, 'title' => $title, 'columns' => $cols, 'rows' => array_values($clean), 'totals' => $totals, 'row_count' => count($clean), 'generated_at' => Db::now()];
    }

    public static function csv(array $report): string
    {
        $lines = [implode(',', array_map(fn($c) => csv_cell($c['label']), $report['columns']))];
        foreach ($report['rows'] as $r) {
            $lines[] = implode(',', array_map(fn($c) => csv_cell($r[$c['key']] ?? ''), $report['columns']));
        }
        return "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n";
    }

    public static function analytics(array $q): array
    {
        Auth::require('reports.read');
        if (empty($q['from'])) {
            $q['from'] = Dates::add(Dates::today(), -366);
        }
        [$w, $p] = self::filters($q);
        $monthly = Db::all(
            "SELECT substr(r.pay_date, 1, 7) AS period, r.currency, COUNT(DISTINCT pe.employee_id) AS headcount, SUM(pe.gross_minor) AS g, SUM(pe.net_minor) AS n, SUM(pe.taxes_minor) AS t,
                    SUM(pe.benefits_minor + pe.deductions_minor) AS d, SUM(pe.employer_cost_minor) AS ecost
               FROM payroll_employees pe JOIN payroll_runs r ON r.id = pe.run_id WHERE {$w} GROUP BY period, r.currency ORDER BY period",
            $p
        );
        $depts = Db::all(
            "SELECT COALESCE(pe.department_name, 'No department') AS dept, r.currency, COUNT(DISTINCT pe.employee_id) AS headcount, SUM(pe.gross_minor) AS g, SUM(pe.net_minor) AS n, SUM(pe.employer_cost_minor) AS ecost
               FROM payroll_employees pe JOIN payroll_runs r ON r.id = pe.run_id WHERE {$w} GROUP BY dept, r.currency ORDER BY ecost DESC",
            $p
        );
        $series = [];
        foreach ($monthly as $r) {
            $c = $r['currency'];
            $series[$c][] = [
                'period' => $r['period'], 'headcount' => (int) $r['headcount'], 'gross' => Money::fmt((int) $r['g'], $c), 'net' => Money::fmt((int) $r['n'], $c),
                'taxes' => Money::fmt((int) $r['t'], $c), 'deductions' => Money::fmt((int) $r['d'], $c), 'employer_cost' => Money::fmt((int) $r['ecost'], $c),
                'cost_per_employee' => Money::fmt(Money::divRound((int) $r['ecost'], max(1, (int) $r['headcount'])), $c),
            ];
        }
        $byDept = [];
        foreach ($depts as $r) {
            $c = $r['currency'];
            $byDept[$c][] = ['department' => $r['dept'], 'headcount' => (int) $r['headcount'], 'gross' => Money::fmt((int) $r['g'], $c), 'net' => Money::fmt((int) $r['n'], $c), 'employer_cost' => Money::fmt((int) $r['ecost'], $c)];
        }
        return ['currencies' => array_keys($series), 'monthly' => $series, 'departments' => $byDept, 'from' => $q['from'], 'has_data' => count($monthly) > 0];
    }

    public static function dashboard(): array
    {
        $u = Auth::requireUser();
        $out = ['employees' => null, 'payroll' => null, 'setup' => null];

        if (Auth::can('employees.read')) {
            $by = [];
            foreach (Db::all('SELECT employment_status AS s, COUNT(*) AS n FROM employees GROUP BY 1') as $r) {
                $by[$r['s']] = (int) $r['n'];
            }
            $out['employees'] = ['total' => array_sum($by), 'active' => ($by['ACTIVE'] ?? 0) + ($by['ON_LEAVE'] ?? 0), 'by_status' => $by];
            $active = "employment_status IN ('ACTIVE','ON_LEAVE')";
            $out['setup'] = [
                'no_compensation' => (int) Db::val("SELECT COUNT(*) FROM employees e WHERE {$active} AND NOT EXISTS (SELECT 1 FROM compensation c WHERE c.employee_id = e.id)"),
                'no_tax_id' => (int) Db::val("SELECT COUNT(*) FROM employees WHERE {$active} AND tax_id_enc IS NULL"),
                'no_bank' => (int) Db::val("SELECT COUNT(*) FROM employees WHERE {$active} AND bank_account_enc IS NULL"),
                'no_schedule' => (int) Db::val("SELECT COUNT(*) FROM employees WHERE {$active} AND schedule_id IS NULL"),
            ];
        }

        if (Auth::can('payroll.read')) {
            $cur = Db::one("SELECT id FROM payroll_runs WHERE status NOT IN ('PAID','CANCELLED') ORDER BY period_start DESC, id DESC LIMIT 1");
            $last = Db::one("SELECT id FROM payroll_runs WHERE status IN ('PROCESSED','PAID') ORDER BY period_start DESC, id DESC LIMIT 1");
            $current = $cur ? Payroll::get((int) $cur['id']) : null;
            $ex = $cur ? Payroll::exceptions((int) $cur['id']) : null;
            $out['payroll'] = [
                'current' => $current,
                'exceptions' => $ex ? ['employees_with_errors' => $ex['employees_with_errors'], 'employees_with_warnings' => $ex['employees_with_warnings'], 'message' => $ex['message'], 'blocking' => $ex['blocking']] : null,
                'last_processed' => $last ? Payroll::fmt(Payroll::row((int) $last['id'])) : null,
                'pending_count' => (int) Db::val("SELECT COUNT(*) FROM payroll_runs WHERE status IN ('DRAFT','PREPARING','CALCULATING','REVIEW','APPROVAL','APPROVED','PROCESSING')"),
                'awaiting_approval' => (int) Db::val("SELECT COUNT(*) FROM payroll_runs WHERE status = 'APPROVAL'"),
                'payslips_generated' => (int) Db::val('SELECT COUNT(*) FROM payslips'),
                'recent' => array_slice(Payroll::list([]), 0, 6),
            ];
        }
        return $out;
    }
}
