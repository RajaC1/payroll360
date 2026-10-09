<?php
declare(strict_types=1);

namespace P360;

/** Payroll runs: schedule -> period -> employees -> calculate -> review -> approve -> process -> payslips. */
final class Payroll
{
    private const LOCKED = ['APPROVED', 'PROCESSING', 'PROCESSED', 'PAID'];
    private const EDITABLE = ['DRAFT', 'PREPARING', 'REVIEW'];
    private const TRANSITIONS = [
        'DRAFT' => ['PREPARING', 'CANCELLED'],
        'PREPARING' => ['CALCULATING', 'CANCELLED'],
        'CALCULATING' => ['REVIEW', 'PREPARING'],
        'REVIEW' => ['APPROVAL', 'PREPARING', 'CANCELLED'],
        'APPROVAL' => ['APPROVED', 'REVIEW', 'CANCELLED'],
        'APPROVED' => ['PROCESSING', 'REVIEW'],
        'PROCESSING' => ['PROCESSED'],
        'PROCESSED' => ['PAID'],
        'PAID' => [],
        'CANCELLED' => [],
    ];
    private const STAGE_NAMES = ['Employee data', 'Payroll preparation', 'Calculation', 'Review', 'Approval', 'Processing', 'Payslip generation'];
    private const RUN_SELECT = "SELECT r.*, s.name AS schedule_name, en.name AS entity_name, ua.name AS approved_by_name, up.name AS processed_by_name,
            (SELECT COUNT(*) FROM payroll_employees x WHERE x.run_id = r.id AND x.included = 1) AS included_count
        FROM payroll_runs r
        JOIN payroll_schedules s ON s.id = r.schedule_id
        JOIN legal_entities en ON en.id = r.entity_id
        LEFT JOIN users ua ON ua.id = r.approved_by
        LEFT JOIN users up ON up.id = r.processed_by";

    // ------------------------------------------------------------------ formatting
    /** 1-based index of the stage a run is currently in (7 = all done). */
    public static function stageIndex(string $status): int
    {
        return match ($status) {
            'DRAFT' => 1, 'PREPARING' => 2, 'CALCULATING' => 3, 'REVIEW' => 4, 'APPROVAL' => 5,
            'APPROVED', 'PROCESSING' => 6, 'PROCESSED', 'PAID' => 7, default => 0,
        };
    }

    public static function stages(string $status): array
    {
        $cur = self::stageIndex($status);
        $finished = in_array($status, ['PROCESSED', 'PAID'], true);
        $out = [];
        foreach (self::STAGE_NAMES as $i => $name) {
            $n = $i + 1;
            $state = $status === 'CANCELLED' ? 'todo' : ($finished || $n < $cur ? 'done' : ($n === $cur ? 'current' : 'todo'));
            if ($status === 'APPROVED' && $n === 5) {
                $state = 'done';
            }
            $out[] = ['n' => $n, 'name' => $name, 'state' => $state];
        }
        return $out;
    }

    private static function money(int $minor, string $cur): string
    {
        return Money::fmt($minor, $cur);
    }

    public static function fmt(array $r): array
    {
        $c = $r['currency'];
        return [
            'id' => (int) $r['id'], 'name' => $r['name'], 'schedule_id' => (int) $r['schedule_id'], 'schedule_name' => $r['schedule_name'],
            'entity_id' => (int) $r['entity_id'], 'entity_name' => $r['entity_name'], 'frequency' => $r['frequency'], 'currency' => $c,
            'period_start' => $r['period_start'], 'period_end' => $r['period_end'], 'cutoff_date' => $r['cutoff_date'], 'pay_date' => $r['pay_date'],
            'status' => $r['status'], 'stage' => self::stageIndex($r['status']), 'stages' => self::stages($r['status']),
            'calculated_at' => $r['calculated_at'], 'employee_count' => (int) $r['employee_count'], 'included_count' => (int) $r['included_count'],
            'gross' => self::money((int) $r['gross_minor'], $c), 'taxes' => self::money((int) $r['taxes_minor'], $c),
            'benefits' => self::money((int) $r['benefits_minor'], $c), 'deductions' => self::money((int) $r['deductions_minor'], $c),
            'net' => self::money((int) $r['net_minor'], $c), 'employer_contrib' => self::money((int) $r['employer_contrib_minor'], $c),
            'employer_cost' => self::money((int) $r['employer_cost_minor'], $c),
            'exception_count' => (int) $r['exception_count'], 'created_at' => $r['created_at'],
            'approved_at' => $r['approved_at'], 'approved_by_name' => $r['approved_by_name'], 'processed_at' => $r['processed_at'],
            'processed_by_name' => $r['processed_by_name'], 'paid_at' => $r['paid_at'], 'cancelled_reason' => $r['cancelled_reason'],
            'locked' => in_array($r['status'], self::LOCKED, true),
        ];
    }

    public static function row(int $id): array
    {
        $r = Db::one(self::RUN_SELECT . ' WHERE r.id = ?', [$id]);
        if (!$r) {
            throw new ApiError(404, 'not_found', 'Payroll not found.');
        }
        return $r;
    }

    /** Actions the current user may take on a run in its current state. */
    public static function actions(array $r): array
    {
        $a = [];
        $st = $r['status'];
        $run = Auth::can('payroll.run');
        $approve = Auth::can('payroll.approve');
        $process = Auth::can('payroll.process');
        if ($run && in_array($st, ['PREPARING', 'REVIEW'], true)) {
            $a[] = 'calculate';
        }
        if ($run && $st === 'REVIEW') {
            $a[] = 'submit';
            $a[] = 'back_to_preparation';
        }
        if ($approve && $st === 'APPROVAL') {
            $a[] = 'approve';
        }
        if ($approve && in_array($st, ['APPROVAL', 'APPROVED'], true)) {
            $a[] = 'reopen';
        }
        if ($process && $st === 'APPROVED') {
            $a[] = 'process';
        }
        if ($process && $st === 'PROCESSED') {
            $a[] = 'mark_paid';
        }
        if ($run && in_array($st, ['DRAFT', 'PREPARING', 'REVIEW', 'APPROVAL'], true)) {
            $a[] = 'cancel';
        }
        return $a;
    }

    public static function get(int $id): array
    {
        $r = self::row($id);
        $out = self::fmt($r);
        $out['actions'] = self::actions($r);
        $out['stale'] = $r['calculated_at'] !== null && in_array($r['status'], ['REVIEW', 'APPROVAL', 'APPROVED'], true) && self::isStale($r);
        $out['unverified_tax_lines'] = (int) Db::val(
            "SELECT COUNT(*) FROM payroll_lines l JOIN payroll_employees pe ON pe.id = l.payroll_employee_id WHERE pe.run_id = ? AND pe.included = 1 AND l.kind = 'tax' AND l.note IS NOT NULL",
            [$id]
        );
        $out['payslip_count'] = (int) Db::val('SELECT COUNT(*) FROM payslips WHERE run_id = ?', [$id]);
        $out['history'] = Db::all(
            'SELECT h.from_status, h.to_status, h.note, h.at, u.name AS user_name FROM payroll_status_history h LEFT JOIN users u ON u.id = h.user_id WHERE h.run_id = ? ORDER BY h.id',
            [$id]
        );
        $out['approvals'] = array_map(function ($a) use ($r) {
            $t = $a['totals_json'] ? json_decode($a['totals_json'], true) : null;
            return ['action' => $a['action'], 'user_email' => $a['user_email'], 'at' => $a['at'], 'note' => $a['note'], 'totals' => $t];
        }, Db::all('SELECT * FROM payroll_approvals WHERE run_id = ? ORDER BY id', [$id]));
        return $out;
    }

    public static function list(array $q): array
    {
        $where = ['1=1'];
        $p = [];
        foreach (['status' => 'r.status', 'schedule_id' => 'r.schedule_id', 'entity_id' => 'r.entity_id', 'currency' => 'r.currency'] as $k => $col) {
            if (!empty($q[$k])) {
                $where[] = $col . ' = ?';
                $p[] = $q[$k];
            }
        }
        if (!empty($q['from'])) {
            $where[] = 'r.pay_date >= ?';
            $p[] = $q['from'];
        }
        if (!empty($q['to'])) {
            $where[] = 'r.pay_date <= ?';
            $p[] = $q['to'];
        }
        $rows = Db::all(self::RUN_SELECT . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY r.period_start DESC, r.id DESC LIMIT 200', $p);
        return array_map([self::class, 'fmt'], $rows);
    }

    // ------------------------------------------------------------------ transitions
    private static function setStatus(array $run, string $to, ?string $note = null): array
    {
        $from = $run['status'];
        if (!in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            throw new ApiError(409, 'invalid_transition', "Payroll cannot move from {$from} to {$to}.");
        }
        $n = Db::run('UPDATE payroll_runs SET status = ?, updated_at = ? WHERE id = ? AND status = ?', [$to, Db::now(), $run['id'], $from]);
        if ($n !== 1) {
            throw new ApiError(409, 'conflict', 'This payroll was changed by someone else. Reload and try again.');
        }
        Db::insert('payroll_status_history', ['run_id' => $run['id'], 'from_status' => $from, 'to_status' => $to, 'user_id' => Auth::uid(), 'note' => $note, 'at' => Db::now()]);
        $run['status'] = $to;
        return $run;
    }

    private static function requireStatus(array $run, array $allowed, string $what): void
    {
        if (!in_array($run['status'], $allowed, true)) {
            throw new ApiError(409, 'invalid_state', "{$what} is not possible while the payroll is {$run['status']}.");
        }
    }

    // ------------------------------------------------------------------ create
    private static function periodLabel(array $s, string $ps, string $pe): string
    {
        return $s['frequency'] === 'monthly'
            ? gmdate('F Y', (int) strtotime($ps . ' UTC'))
            : gmdate('M j', (int) strtotime($ps . ' UTC')) . ' - ' . gmdate('M j, Y', (int) strtotime($pe . ' UTC'));
    }

    public static function create(array $in): array
    {
        Auth::require('payroll.run');
        $v = new Validator($in);
        $sid = $v->int('schedule_id', true, 1);
        $start = $v->date('period_start', true);
        $v->done();
        $s = Schedules::row($sid);
        if ($s['status'] !== 'active') {
            throw new ApiError(422, 'validation_failed', 'That payroll schedule is not active.', ['schedule_id' => 'Schedule is inactive.']);
        }
        [$ps, $pe] = Schedules::periodContaining($s, $start);
        if ($ps !== $start) {
            throw new ApiError(422, 'validation_failed', 'That is not the start of a ' . $s['frequency'] . ' pay period. The period containing it starts on ' . $ps . '.', ['period_start' => 'Choose a valid period start (' . $ps . ').']);
        }
        $clash = Db::one("SELECT name FROM payroll_runs WHERE schedule_id = ? AND status <> 'CANCELLED' AND NOT (period_end < ? OR period_start > ?)", [$sid, $ps, $pe]);
        if ($clash) {
            throw new ApiError(409, 'duplicate_payroll', 'A payroll already exists for this period: ' . $clash['name'] . '.', ['period_start' => 'Period already has a payroll.']);
        }
        $d = Schedules::dates($s, $pe);
        return Db::tx(function () use ($s, $ps, $pe, $d) {
            $id = Db::insert('payroll_runs', [
                'name' => $s['name'] . ' - ' . self::periodLabel($s, $ps, $pe), 'schedule_id' => (int) $s['id'], 'entity_id' => (int) $s['entity_id'],
                'frequency' => $s['frequency'], 'currency' => $s['currency'], 'period_start' => $ps, 'period_end' => $pe,
                'cutoff_date' => $d['cutoff_date'], 'pay_date' => $d['pay_date'], 'status' => 'DRAFT',
                'created_by' => Auth::uid(), 'created_at' => Db::now(), 'updated_at' => Db::now(),
            ]);
            Db::insert('payroll_status_history', ['run_id' => $id, 'from_status' => null, 'to_status' => 'DRAFT', 'user_id' => Auth::uid(), 'note' => 'Payroll created', 'at' => Db::now()]);
            $run = self::row($id);
            $eligible = Db::all(
                "SELECT id FROM employees WHERE schedule_id = ? AND joining_date <= ? AND (termination_date IS NULL OR termination_date >= ?)
                   AND employment_status IN ('ACTIVE','ON_LEAVE','TERMINATED') ORDER BY last_name, first_name",
                [(int) $s['id'], $pe, $ps]
            );
            foreach ($eligible as $e) {
                self::addRow($id, (int) $e['id']);
            }
            self::setStatus($run, 'PREPARING', 'Employees added from the schedule');
            Audit::log('Payroll Created', 'payroll', $id, null, ['name' => $s['name'], 'period_start' => $ps, 'period_end' => $pe, 'employees' => count($eligible)]);
            return self::get($id);
        });
    }

    private static function addRow(int $runId, int $empId, bool $included = true): void
    {
        Db::run(
            'INSERT INTO payroll_employees (run_id, employee_id, included, status) VALUES (?, ?, ?, ?) ON CONFLICT(run_id, employee_id) DO UPDATE SET included = excluded.included',
            [$runId, $empId, $included ? 1 : 0, 'pending']
        );
    }

    /** Include or exclude employees in a run (the "confirm employees" step). */
    public static function setEmployees(int $id, array $ids, bool $include): array
    {
        Auth::require('payroll.run');
        $run = self::row($id);
        self::requireStatus($run, self::EDITABLE, 'Changing employees');
        if (!$ids) {
            throw new ApiError(422, 'validation_failed', 'Choose at least one employee.');
        }
        Db::tx(function () use ($id, $ids, $include, $run) {
            foreach ($ids as $eid) {
                $eid = (int) $eid;
                if (!Db::one('SELECT id FROM employees WHERE id = ?', [$eid])) {
                    throw new ApiError(422, 'validation_failed', 'One of the selected employees does not exist.');
                }
                if (!$include && !Db::one('SELECT id FROM payroll_employees WHERE run_id = ? AND employee_id = ?', [$id, $eid])) {
                    continue;
                }
                self::addRow($id, $eid, $include);
            }
            Audit::log($include ? 'Payroll Employees Added' : 'Payroll Employees Excluded', 'payroll', $id, null, ['employee_ids' => array_map('intval', $ids)]);
            if ($run['status'] === 'REVIEW') {
                self::computeAll(self::row($id));
            }
        });
        return self::get($id);
    }

    // ------------------------------------------------------------------ engine input
    private static function engineInput(array $run, int $empId): array
    {
        $e = Employees::row($empId);
        $ps = $run['period_start'];
        $pe = $run['period_end'];
        $comp = Compensation::current($empId, $pe);
        $adj = array_map(fn($a) => [
            'kind' => $a['kind'], 'name' => $a['name'], 'amount_minor' => $a['amount_minor'] !== null ? (int) $a['amount_minor'] : null,
            'hours_hundredths' => $a['hours_hundredths'] !== null ? (int) $a['hours_hundredths'] : null, 'taxable' => (int) $a['taxable'], 'reason' => $a['reason'],
        ], Db::all('SELECT * FROM payroll_adjustments WHERE run_id = ? AND employee_id = ? ORDER BY id', [(int) $run['id'], $empId]));
        $ytd = (int) Db::val(
            "SELECT COALESCE(SUM(pe.taxable_gross_minor), 0) FROM payroll_employees pe JOIN payroll_runs r ON r.id = pe.run_id
              WHERE pe.employee_id = ? AND pe.included = 1 AND r.status IN ('PROCESSED','PAID') AND r.id <> ? AND substr(r.pay_date, 1, 4) = ? AND r.pay_date <= ?",
            [$empId, (int) $run['id'], substr((string) $run['pay_date'], 0, 4), $run['pay_date']]
        );
        $dup = Db::one(
            "SELECT r.name FROM payroll_employees pe JOIN payroll_runs r ON r.id = pe.run_id
              WHERE pe.employee_id = ? AND pe.included = 1 AND r.id <> ? AND r.status <> 'CANCELLED' AND NOT (r.period_end < ? OR r.period_start > ?) LIMIT 1",
            [$empId, (int) $run['id'], $ps, $pe]
        );
        $rules = array_map(fn($r) => [
            'code' => $r['code'], 'name' => $r['name'], 'tax_type' => $r['tax_type'], 'method' => $r['method'], 'base_kind' => $r['base_kind'], 'cap_scope' => $r['cap_scope'],
            'rate_bp' => $r['rate_bp'] !== null ? (int) $r['rate_bp'] : null, 'threshold_minor' => (int) $r['threshold_minor'],
            'cap_minor' => $r['cap_minor'] !== null ? (int) $r['cap_minor'] : null, 'brackets_json' => $r['brackets_json'],
            'fixed_minor' => $r['fixed_minor'] !== null ? (int) $r['fixed_minor'] : null, 'employer_rate_bp' => (int) $r['employer_rate_bp'],
            'employer_threshold_minor' => (int) $r['employer_threshold_minor'], 'verified' => (int) $r['verified'],
        ], TaxRules::applicable($e['country'], $e['state_region'], $ps, $pe));
        return [
            'employee' => [
                'id' => $empId, 'employee_no' => $e['employee_no'], 'name' => Employees::fullName($e), 'country' => $e['country'], 'state_region' => $e['state_region'],
                'currency' => $e['currency'], 'payroll_frequency' => $e['payroll_frequency'], 'employment_status' => $e['employment_status'],
                'joining_date' => $e['joining_date'], 'termination_date' => $e['termination_date'],
                'tax_id_present' => $e['tax_id_enc'] !== null, 'bank_present' => $e['bank_account_enc'] !== null,
            ],
            'run' => ['frequency' => $run['frequency'], 'currency' => $run['currency'], 'period_start' => $ps, 'period_end' => $pe],
            'compensation' => $comp ? ['pay_basis' => $comp['pay_basis'], 'base_minor' => (int) $comp['base_minor'], 'currency' => $comp['currency'], 'overtime_multiplier_bp' => (int) $comp['overtime_multiplier_bp']] : null,
            'components' => Assignments::forEngine($empId),
            'adjustments' => $adj,
            'tax_rules' => $rules,
            'ytd' => ['taxable_gross_minor' => $ytd],
            'duplicate_run' => $dup['name'] ?? null,
        ];
    }

    private static function hashInputs(array $byEmployee): string
    {
        ksort($byEmployee);
        return hash('sha256', (string) json_encode($byEmployee, JSON_UNESCAPED_UNICODE));
    }

    public static function isStale(array $run): bool
    {
        $inputs = [];
        foreach (Db::all('SELECT employee_id FROM payroll_employees WHERE run_id = ? AND included = 1', [(int) $run['id']]) as $r) {
            $inputs[(int) $r['employee_id']] = self::engineInput($run, (int) $r['employee_id']);
        }
        return self::hashInputs($inputs) !== $run['inputs_hash'];
    }

    // ------------------------------------------------------------------ calculate
    private static function computeAll(array $run): void
    {
        $rows = Db::all('SELECT * FROM payroll_employees WHERE run_id = ? ORDER BY id', [(int) $run['id']]);
        $tot = ['gross' => 0, 'taxes' => 0, 'benefits' => 0, 'deductions' => 0, 'net' => 0, 'employer_contrib' => 0, 'employer_cost' => 0];
        $inputs = [];
        $withErrors = 0;
        $included = 0;
        foreach ($rows as $pe) {
            $eid = (int) $pe['employee_id'];
            Db::run('DELETE FROM payroll_lines WHERE payroll_employee_id = ?', [(int) $pe['id']]);
            if ((int) $pe['included'] !== 1) {
                Db::update('payroll_employees', (int) $pe['id'], [
                    'status' => 'excluded', 'exceptions_json' => null, 'base_minor' => 0, 'earnings_minor' => 0, 'allowances_minor' => 0, 'gross_minor' => 0,
                    'taxable_gross_minor' => 0, 'taxes_minor' => 0, 'benefits_minor' => 0, 'deductions_minor' => 0, 'net_minor' => 0,
                    'employer_contrib_minor' => 0, 'employer_cost_minor' => 0,
                ]);
                continue;
            }
            $included++;
            $in = self::engineInput($run, $eid);
            $inputs[$eid] = $in;
            $res = Engine::calculate($in);
            $t = $res['totals'];
            $hasError = (bool) array_filter($res['exceptions'], fn($x) => $x['severity'] === 'error');
            $withErrors += $hasError ? 1 : 0;
            $e = Employees::row($eid);
            Db::update('payroll_employees', (int) $pe['id'], [
                'status' => $hasError ? 'exception' : 'ok',
                'snapshot_json' => json_encode([
                    'employee_no' => $e['employee_no'], 'name' => Employees::fullName($e), 'job_title' => $e['job_title'], 'department' => $e['department'],
                    'country' => $e['country'], 'state_region' => $e['state_region'], 'work_email' => $e['work_email'], 'currency' => $e['currency'],
                    'tax_id_masked' => Crypto::mask(Crypto::dec($e['tax_id_enc'])), 'bank_name' => $e['bank_name'],
                    'bank_account_masked' => Crypto::mask(Crypto::dec($e['bank_account_enc'])),
                ], JSON_UNESCAPED_UNICODE),
                'department_name' => $e['department'], 'country' => $e['country'],
                'base_minor' => $t['base_minor'], 'earnings_minor' => $t['earnings_minor'], 'allowances_minor' => $t['allowances_minor'],
                'gross_minor' => $t['gross_minor'], 'taxable_gross_minor' => $t['taxable_gross_minor'], 'taxes_minor' => $t['taxes_minor'],
                'benefits_minor' => $t['benefits_minor'], 'deductions_minor' => $t['deductions_minor'], 'net_minor' => $t['net_minor'],
                'employer_contrib_minor' => $t['employer_contrib_minor'], 'employer_cost_minor' => $t['employer_cost_minor'],
                'exceptions_json' => json_encode($res['exceptions']),
            ]);
            foreach ($res['lines'] as $l) {
                Db::insert('payroll_lines', [
                    'payroll_employee_id' => (int) $pe['id'], 'kind' => $l['kind'], 'code' => $l['code'], 'name' => $l['name'], 'category' => $l['category'],
                    'method' => $l['method'], 'amount_minor' => $l['amount_minor'], 'employer_minor' => $l['employer_minor'], 'taxable' => $l['taxable'],
                    'note' => $l['note'], 'sort' => $l['sort'],
                ]);
            }
            $tot['gross'] += $t['gross_minor'];
            $tot['taxes'] += $t['taxes_minor'];
            $tot['benefits'] += $t['benefits_minor'];
            $tot['deductions'] += $t['deductions_minor'];
            $tot['net'] += $t['net_minor'];
            $tot['employer_contrib'] += $t['employer_contrib_minor'];
            $tot['employer_cost'] += $t['employer_cost_minor'];
        }
        Db::update('payroll_runs', (int) $run['id'], [
            'employee_count' => $included, 'gross_minor' => $tot['gross'], 'taxes_minor' => $tot['taxes'], 'benefits_minor' => $tot['benefits'],
            'deductions_minor' => $tot['deductions'], 'net_minor' => $tot['net'], 'employer_contrib_minor' => $tot['employer_contrib'],
            'employer_cost_minor' => $tot['employer_cost'], 'exception_count' => $withErrors, 'calculated_at' => Db::now(),
            'inputs_hash' => self::hashInputs($inputs), 'updated_at' => Db::now(),
        ]);
    }

    public static function calculate(int $id): array
    {
        Auth::require('payroll.run');
        $run = self::row($id);
        self::requireStatus($run, ['PREPARING', 'REVIEW'], 'Calculating');
        Db::tx(function () use ($run, $id) {
            $was = $run['status'];
            if ($was === 'PREPARING') {
                $run = self::setStatus($run, 'CALCULATING', 'Calculating payroll');
            }
            self::computeAll($run);
            $run = self::row($id);
            if ($was === 'PREPARING') {
                self::setStatus($run, 'REVIEW', 'Calculation complete');
            } else {
                Db::insert('payroll_status_history', ['run_id' => $id, 'from_status' => 'REVIEW', 'to_status' => 'REVIEW', 'user_id' => Auth::uid(), 'note' => 'Recalculated', 'at' => Db::now()]);
            }
            $after = self::row($id);
            Audit::log('Payroll Calculated', 'payroll', $id, null, ['employees' => (int) $after['employee_count'], 'gross_minor' => (int) $after['gross_minor'], 'net_minor' => (int) $after['net_minor'], 'exceptions' => (int) $after['exception_count']]);
        });
        return self::get($id);
    }

    // ------------------------------------------------------------------ adjustments
    public static function addAdjustment(int $id, int $empId, array $in): array
    {
        Auth::require('payroll.run');
        $run = self::row($id);
        self::requireStatus($run, ['PREPARING', 'REVIEW'], 'Adjusting pay');
        if (!Db::one('SELECT id FROM payroll_employees WHERE run_id = ? AND employee_id = ? AND included = 1', [$id, $empId])) {
            throw new ApiError(404, 'not_found', 'That employee is not part of this payroll.');
        }
        $v = new Validator($in);
        $kind = $v->enum('kind', Meta::ADJ_KINDS);
        $reason = $v->str('reason', true, 200);
        $name = $v->str('name', $kind !== 'overtime_hours', 100);
        $amount = null;
        $hours = null;
        if ($kind === 'overtime_hours') {
            $h = $v->raw('hours');
            if (!is_numeric($h) || !preg_match('/^\d{1,3}(\.\d{1,2})?$/', (string) $h) || (float) $h <= 0 || (float) $h > 400) {
                $v->fail('hours', 'Enter overtime hours between 0.01 and 400.');
            } else {
                $hours = (int) round(((float) $h) * 100);
            }
        } elseif ($kind !== null) {
            $amount = $v->money('amount', $run['currency'], true);
            if ($amount !== null && $amount <= 0) {
                $v->fail('amount', 'Amount must be greater than zero.');
            }
        }
        $v->done();
        $aid = Db::tx(function () use ($id, $empId, $kind, $name, $amount, $hours, $in, $reason, $run) {
            $aid = Db::insert('payroll_adjustments', [
                'run_id' => $id, 'employee_id' => $empId, 'kind' => $kind, 'name' => $name ?? 'Overtime', 'amount_minor' => $amount, 'hours_hundredths' => $hours,
                'taxable' => array_key_exists('taxable', $in) ? (int) (bool) $in['taxable'] : 1, 'reason' => $reason, 'created_by' => Auth::uid(), 'created_at' => Db::now(),
            ]);
            Audit::log('Payroll Adjusted', 'payroll', $id, null, ['employee_id' => $empId, 'kind' => $kind, 'name' => $name, 'amount_minor' => $amount, 'hours_hundredths' => $hours, 'reason' => $reason]);
            if ($run['status'] === 'REVIEW') {
                self::computeAll(self::row($id));
            }
            return $aid;
        });
        return ['id' => $aid, 'run' => self::get($id)];
    }

    public static function removeAdjustment(int $id, int $adjId): array
    {
        Auth::require('payroll.run');
        $run = self::row($id);
        self::requireStatus($run, ['PREPARING', 'REVIEW'], 'Adjusting pay');
        $a = Db::one('SELECT * FROM payroll_adjustments WHERE id = ? AND run_id = ?', [$adjId, $id]);
        if (!$a) {
            throw new ApiError(404, 'not_found', 'Adjustment not found.');
        }
        Db::tx(function () use ($adjId, $id, $a, $run) {
            Db::run('DELETE FROM payroll_adjustments WHERE id = ?', [$adjId]);
            Audit::log('Payroll Adjustment Removed', 'payroll', $id, ['employee_id' => (int) $a['employee_id'], 'kind' => $a['kind'], 'name' => $a['name'], 'amount_minor' => $a['amount_minor']], null);
            if ($run['status'] === 'REVIEW') {
                self::computeAll(self::row($id));
            }
        });
        return self::get($id);
    }

    // ------------------------------------------------------------------ review data
    public static function employees(int $id, array $q): array
    {
        $run = self::row($id);
        $c = $run['currency'];
        $where = ['pe.run_id = ?'];
        $p = [$id];
        if (!empty($q['q'])) {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $q['q']) . '%';
            $where[] = "(e.first_name LIKE ? ESCAPE '\\' OR e.last_name LIKE ? ESCAPE '\\' OR e.employee_no LIKE ? ESCAPE '\\')";
            array_push($p, $like, $like, $like);
        }
        if (!empty($q['department'])) {
            $where[] = 'pe.department_name = ?';
            $p[] = $q['department'];
        }
        if (!empty($q['country'])) {
            $where[] = 'pe.country = ?';
            $p[] = $q['country'];
        }
        if (!empty($q['status'])) {
            $where[] = 'pe.status = ?';
            $p[] = $q['status'];
        }
        if (!empty($q['employee_id'])) {
            $where[] = 'pe.employee_id = ?';
            $p[] = (int) $q['employee_id'];
        }
        $sortMap = ['name' => 'e.last_name, e.first_name', 'gross' => 'pe.gross_minor', 'net' => 'pe.net_minor', 'taxes' => 'pe.taxes_minor', 'department' => 'pe.department_name', 'status' => 'pe.status'];
        $order = $sortMap[$q['sort'] ?? 'name'] ?? $sortMap['name'];
        $dir = (($q['dir'] ?? 'asc') === 'desc') ? 'DESC' : 'ASC';
        $rows = Db::all(
            "SELECT pe.*, e.employee_no, e.first_name, e.last_name, e.middle_name, d.name AS live_department, e.country AS live_country FROM payroll_employees pe JOIN employees e ON e.id = pe.employee_id LEFT JOIN departments d ON d.id = e.department_id
              WHERE " . implode(' AND ', $where) . " ORDER BY {$order} {$dir}, pe.id",
            $p
        );
        $items = array_map(function ($r) use ($c) {
            $ex = $r['exceptions_json'] ? json_decode($r['exceptions_json'], true) : [];
            $m = fn(string $k) => Money::fmt((int) $r[$k], $c);
            return [
                'employee_id' => (int) $r['employee_id'], 'employee_no' => $r['employee_no'], 'name' => Employees::fullName($r), 'department' => $r['department_name'] ?? $r['live_department'],
                'country' => $r['country'] ?? $r['live_country'], 'included' => (int) $r['included'] === 1, 'status' => $r['status'],
                'base' => $m('base_minor'), 'earnings' => $m('earnings_minor'), 'allowances' => $m('allowances_minor'), 'gross' => $m('gross_minor'),
                'taxes' => $m('taxes_minor'), 'benefits' => $m('benefits_minor'), 'deductions' => $m('deductions_minor'), 'net' => $m('net_minor'),
                'employer_contrib' => $m('employer_contrib_minor'), 'employer_cost' => $m('employer_cost_minor'),
                'errors' => count(array_filter($ex, fn($x) => $x['severity'] === 'error')), 'warnings' => count(array_filter($ex, fn($x) => $x['severity'] === 'warning')),
                'exceptions' => $ex,
            ];
        }, $rows);
        $filters = [
            'departments' => Db::all('SELECT DISTINCT department_name AS v FROM payroll_employees WHERE run_id = ? AND department_name IS NOT NULL ORDER BY 1', [$id]),
            'countries' => Db::all('SELECT DISTINCT country AS v FROM payroll_employees WHERE run_id = ? AND country IS NOT NULL ORDER BY 1', [$id]),
        ];
        return ['items' => $items, 'filters' => ['departments' => array_column($filters['departments'], 'v'), 'countries' => array_column($filters['countries'], 'v')]];
    }

    public static function employeeDetail(int $id, int $empId): array
    {
        $run = self::row($id);
        $c = $run['currency'];
        $pe = Db::one('SELECT pe.*, e.employee_no FROM payroll_employees pe JOIN employees e ON e.id = pe.employee_id WHERE pe.run_id = ? AND pe.employee_id = ?', [$id, $empId]);
        if (!$pe) {
            throw new ApiError(404, 'not_found', 'That employee is not part of this payroll.');
        }
        $snap = $pe['snapshot_json'] ? json_decode($pe['snapshot_json'], true) : [];
        $lines = Db::all('SELECT * FROM payroll_lines WHERE payroll_employee_id = ? ORDER BY sort', [(int) $pe['id']]);
        $fmtLine = fn($l) => ['name' => $l['name'], 'code' => $l['code'], 'category' => $l['category'], 'calculation' => $l['method'], 'amount' => Money::fmt((int) $l['amount_minor'], $c),
            'employer' => (int) $l['employer_minor'] !== 0 ? Money::fmt((int) $l['employer_minor'], $c) : null, 'note' => $l['note']];
        $by = fn(string $k) => array_values(array_map($fmtLine, array_filter($lines, fn($l) => $l['kind'] === $k)));
        $m = fn(string $k) => Money::fmt((int) $pe[$k], $c);
        $adjustments = array_map(fn($a) => [
            'id' => (int) $a['id'], 'kind' => $a['kind'], 'name' => $a['name'], 'amount' => $a['amount_minor'] !== null ? Money::fmt((int) $a['amount_minor'], $c) : null,
            'hours' => $a['hours_hundredths'] !== null ? number_format((int) $a['hours_hundredths'] / 100, 2, '.', '') : null, 'taxable' => (int) $a['taxable'] === 1, 'reason' => $a['reason'],
        ], Db::all('SELECT * FROM payroll_adjustments WHERE run_id = ? AND employee_id = ? ORDER BY id', [$id, $empId]));
        return [
            'run' => ['id' => (int) $run['id'], 'name' => $run['name'], 'status' => $run['status'], 'currency' => $c, 'period_start' => $run['period_start'], 'period_end' => $run['period_end'], 'pay_date' => $run['pay_date']],
            'employee' => $snap + ['employee_id' => $empId, 'employee_no' => $pe['employee_no']],
            'included' => (int) $pe['included'] === 1, 'status' => $pe['status'],
            'earnings' => $by('earning'), 'taxes' => $by('tax'), 'benefits' => $by('benefit'), 'deductions' => $by('deduction'),
            'summary' => ['gross' => $m('gross_minor'), 'taxes' => $m('taxes_minor'), 'benefits' => $m('benefits_minor'), 'deductions' => $m('deductions_minor'),
                'total_deductions' => Money::fmt((int) $pe['taxes_minor'] + (int) $pe['benefits_minor'] + (int) $pe['deductions_minor'], $c),
                'employer_contrib' => $m('employer_contrib_minor'), 'employer_cost' => $m('employer_cost_minor'), 'net' => $m('net_minor')],
            'exceptions' => $pe['exceptions_json'] ? json_decode($pe['exceptions_json'], true) : [],
            'adjustments' => $adjustments,
            'editable' => in_array($run['status'], ['PREPARING', 'REVIEW'], true),
        ];
    }

    /** Everything that needs attention before payroll can be approved. */
    public static function exceptions(int $id): array
    {
        $run = self::row($id);
        $runLevel = [];
        $included = (int) $run['included_count'];
        if ($included === 0) {
            $runLevel[] = ['code' => 'no_employees', 'severity' => 'error', 'message' => 'No employees are included in this payroll.'];
        }
        $sched = Db::one('SELECT status FROM payroll_schedules WHERE id = ?', [(int) $run['schedule_id']]);
        if (!$sched || $sched['status'] !== 'active') {
            $runLevel[] = ['code' => 'schedule_inactive', 'severity' => 'error', 'message' => 'The payroll schedule is not active.'];
        }
        if ($run['calculated_at'] === null) {
            $runLevel[] = ['code' => 'not_calculated', 'severity' => 'error', 'message' => 'Payroll has not been calculated yet.'];
        } elseif (in_array($run['status'], ['REVIEW', 'APPROVAL', 'APPROVED'], true) && self::isStale($run)) {
            $runLevel[] = ['code' => 'stale', 'severity' => 'error', 'message' => 'Employee or pay data has changed since the last calculation. Recalculate before continuing.'];
        }
        $rows = Db::all(
            "SELECT pe.employee_id, pe.exceptions_json, e.employee_no, e.first_name, e.last_name, e.middle_name FROM payroll_employees pe JOIN employees e ON e.id = pe.employee_id
              WHERE pe.run_id = ? AND pe.included = 1 AND pe.exceptions_json IS NOT NULL AND pe.exceptions_json <> '[]' ORDER BY e.last_name, e.first_name",
            [$id]
        );
        $emps = [];
        $errEmps = 0;
        $warnEmps = 0;
        foreach ($rows as $r) {
            $ex = json_decode($r['exceptions_json'], true) ?: [];
            $errs = count(array_filter($ex, fn($x) => $x['severity'] === 'error'));
            $errEmps += $errs > 0 ? 1 : 0;
            $warnEmps += ($errs === 0 && $ex) ? 1 : 0;
            $emps[] = ['employee_id' => (int) $r['employee_id'], 'employee_no' => $r['employee_no'], 'name' => Employees::fullName($r), 'exceptions' => $ex, 'errors' => $errs];
        }
        $runErrors = count(array_filter($runLevel, fn($x) => $x['severity'] === 'error'));
        $blocking = $errEmps + $runErrors;
        $msg = null;
        if ($errEmps > 0) {
            $msg = $errEmps . ' employee' . ($errEmps === 1 ? '' : 's') . ($errEmps === 1 ? ' requires' : ' require') . ' attention before payroll can be approved.';
        } elseif ($runErrors > 0) {
            $msg = $runLevel[0]['message'];
        }
        return ['run_level' => $runLevel, 'employees' => $emps, 'employees_with_errors' => $errEmps, 'employees_with_warnings' => $warnEmps, 'blocking' => $blocking > 0, 'message' => $msg];
    }

    // ------------------------------------------------------------------ workflow actions
    private static function assertReady(array $run): void
    {
        $ex = self::exceptions((int) $run['id']);
        if ($ex['blocking']) {
            throw new ApiError(422, 'exceptions_block', (string) $ex['message'], ['employees_with_errors' => (string) $ex['employees_with_errors']]);
        }
    }

    public static function submit(int $id): array
    {
        Auth::require('payroll.run');
        $run = self::row($id);
        self::requireStatus($run, ['REVIEW'], 'Submitting for approval');
        self::assertReady($run);
        Db::tx(function () use ($run, $id) {
            self::setStatus($run, 'APPROVAL', 'Submitted for approval');
            Audit::log('Payroll Submitted For Approval', 'payroll', $id, null, ['net_minor' => (int) $run['net_minor']]);
        });
        return self::get($id);
    }

    public static function approve(int $id, ?string $note): array
    {
        $u = Auth::require('payroll.approve');
        $run = self::row($id);
        self::requireStatus($run, ['APPROVAL'], 'Approval');
        self::assertReady($run);
        Db::tx(function () use ($run, $id, $u, $note) {
            self::setStatus($run, 'APPROVED', $note ?: 'Approved');
            Db::run('UPDATE payroll_runs SET approved_by = ?, approved_at = ?, updated_at = ? WHERE id = ?', [$u['id'], Db::now(), Db::now(), $id]);
            $r = self::row($id);
            $totals = ['employees' => (int) $r['employee_count'], 'gross' => Money::fmt((int) $r['gross_minor'], $r['currency']), 'taxes' => Money::fmt((int) $r['taxes_minor'], $r['currency']),
                'deductions' => Money::fmt((int) $r['deductions_minor'] + (int) $r['benefits_minor'], $r['currency']), 'net' => Money::fmt((int) $r['net_minor'], $r['currency']),
                'employer_contrib' => Money::fmt((int) $r['employer_contrib_minor'], $r['currency']), 'currency' => $r['currency']];
            Db::insert('payroll_approvals', ['run_id' => $id, 'action' => 'approved', 'user_id' => $u['id'], 'user_email' => $u['email'], 'at' => Db::now(), 'totals_json' => json_encode($totals), 'note' => $note]);
            Audit::log('Payroll Approved', 'payroll', $id, null, $totals);
        });
        return self::get($id);
    }

    public static function reopen(int $id, ?string $reason): array
    {
        $u = Auth::require('payroll.approve');
        $run = self::row($id);
        self::requireStatus($run, ['APPROVAL', 'APPROVED'], 'Reopening');
        if ($reason === null || mb_strlen(trim($reason)) < 5) {
            throw new ApiError(422, 'validation_failed', 'Please give a reason for reopening this payroll.', ['note' => 'A reason of at least 5 characters is required.']);
        }
        Db::tx(function () use ($run, $id, $u, $reason) {
            self::setStatus($run, 'REVIEW', 'Reopened: ' . $reason);
            Db::run('UPDATE payroll_runs SET approved_by = NULL, approved_at = NULL, updated_at = ? WHERE id = ?', [Db::now(), $id]);
            Db::insert('payroll_approvals', ['run_id' => $id, 'action' => 'reopened', 'user_id' => $u['id'], 'user_email' => $u['email'], 'at' => Db::now(), 'totals_json' => null, 'note' => $reason]);
            Audit::log('Payroll Reopened', 'payroll', $id, ['status' => $run['status']], ['status' => 'REVIEW', 'reason' => $reason]);
        });
        return self::get($id);
    }

    public static function backToPreparation(int $id): array
    {
        Auth::require('payroll.run');
        $run = self::row($id);
        self::requireStatus($run, ['REVIEW'], 'Going back');
        Db::tx(fn() => self::setStatus($run, 'PREPARING', 'Returned to preparation'));
        return self::get($id);
    }

    public static function cancel(int $id, ?string $reason): array
    {
        Auth::require('payroll.run');
        $run = self::row($id);
        self::requireStatus($run, ['DRAFT', 'PREPARING', 'REVIEW', 'APPROVAL'], 'Cancelling');
        if ($reason === null || mb_strlen(trim($reason)) < 3) {
            throw new ApiError(422, 'validation_failed', 'Please give a reason for cancelling.', ['note' => 'A reason is required.']);
        }
        Db::tx(function () use ($run, $id, $reason) {
            self::setStatus($run, 'CANCELLED', $reason);
            Db::run('UPDATE payroll_runs SET cancelled_reason = ?, updated_at = ? WHERE id = ?', [$reason, Db::now(), $id]);
            Audit::log('Payroll Cancelled', 'payroll', $id, null, ['reason' => $reason]);
        });
        return self::get($id);
    }

    public static function process(int $id): array
    {
        $u = Auth::require('payroll.process');
        $run = self::row($id);
        self::requireStatus($run, ['APPROVED'], 'Processing');
        if (self::isStale($run)) {
            throw new ApiError(409, 'stale', 'Pay data changed after approval. Reopen the payroll, recalculate and approve it again.');
        }
        Db::tx(function () use ($run, $id, $u) {
            $run = self::setStatus($run, 'PROCESSING', 'Processing started');
            $count = Payslips::generateForRun(self::row($id), $u['id']);
            Db::run('UPDATE payroll_runs SET processed_by = ?, processed_at = ?, updated_at = ? WHERE id = ?', [$u['id'], Db::now(), Db::now(), $id]);
            self::setStatus(self::row($id), 'PROCESSED', $count . ' payslips generated');
            Audit::log('Payroll Processed', 'payroll', $id, null, ['payslips' => $count]);
            Audit::log('Payslips Generated', 'payroll', $id, null, ['count' => $count]);
        });
        return self::get($id);
    }

    public static function markPaid(int $id): array
    {
        Auth::require('payroll.process');
        $run = self::row($id);
        self::requireStatus($run, ['PROCESSED'], 'Marking as paid');
        Db::tx(function () use ($run, $id) {
            self::setStatus($run, 'PAID', 'Marked as paid');
            Db::run('UPDATE payroll_runs SET paid_at = ?, updated_at = ? WHERE id = ?', [Db::now(), Db::now(), $id]);
            Audit::log('Payroll Marked Paid', 'payroll', $id, null, null);
        });
        return self::get($id);
    }
}

/** Payslips: generated once at processing time and frozen. */
final class Payslips
{
    private const SELECT = "SELECT p.*, pe.department_name, e.employee_no, TRIM(e.first_name || ' ' || COALESCE(e.middle_name || ' ', '') || e.last_name) AS employee_name, r.name AS run_name
        FROM payslips p JOIN payroll_employees pe ON pe.id = p.payroll_employee_id JOIN employees e ON e.id = p.employee_id JOIN payroll_runs r ON r.id = p.run_id";

    public static function fmt(array $r): array
    {
        $c = $r['currency'];
        return [
            'id' => (int) $r['id'], 'number' => $r['number'], 'run_id' => (int) $r['run_id'], 'run_name' => $r['run_name'], 'employee_id' => (int) $r['employee_id'],
            'employee_no' => $r['employee_no'], 'employee_name' => $r['employee_name'], 'department' => $r['department_name'],
            'period_start' => $r['period_start'], 'period_end' => $r['period_end'], 'pay_date' => $r['pay_date'], 'currency' => $c,
            'gross' => Money::fmt((int) $r['gross_minor'], $c), 'deductions' => Money::fmt((int) $r['deductions_minor'], $c), 'net' => Money::fmt((int) $r['net_minor'], $c),
            'status' => $r['status'], 'generated_at' => $r['generated_at'], 'sent_at' => $r['sent_at'], 'sent_to' => $r['sent_to'], 'sent_count' => (int) $r['sent_count'],
        ];
    }

    /** Build the frozen payslip document for one employee of a run. */
    private static function buildDoc(array $run, array $pe): array
    {
        $c = $run['currency'];
        $snap = json_decode((string) $pe['snapshot_json'], true) ?: [];
        $entity = Entities::row((int) $run['entity_id']);
        $lines = Db::all('SELECT * FROM payroll_lines WHERE payroll_employee_id = ? ORDER BY sort', [(int) $pe['id']]);
        $by = fn(string $k) => array_values(array_map(fn($l) => ['name' => $l['name'], 'amount' => Money::fmt((int) $l['amount_minor'], $c), 'note' => $l['note']], array_filter($lines, fn($l) => $l['kind'] === $k)));
        $prior = Db::one(
            "SELECT COALESCE(SUM(pe.gross_minor),0) AS g, COALESCE(SUM(pe.taxes_minor),0) AS t, COALESCE(SUM(pe.benefits_minor + pe.deductions_minor),0) AS d, COALESCE(SUM(pe.net_minor),0) AS n
               FROM payroll_employees pe JOIN payroll_runs r ON r.id = pe.run_id
              WHERE pe.employee_id = ? AND pe.included = 1 AND r.status IN ('PROCESSED','PAID') AND r.id <> ? AND substr(r.pay_date,1,4) = ? AND r.pay_date <= ?",
            [(int) $pe['employee_id'], (int) $run['id'], substr((string) $run['pay_date'], 0, 4), $run['pay_date']]
        );
        $totalDed = (int) $pe['taxes_minor'] + (int) $pe['benefits_minor'] + (int) $pe['deductions_minor'];
        $ytdG = (int) $prior['g'] + (int) $pe['gross_minor'];
        $ytdT = (int) $prior['t'] + (int) $pe['taxes_minor'];
        $ytdD = (int) $prior['d'] + (int) $pe['benefits_minor'] + (int) $pe['deductions_minor'];
        $ytdN = (int) $prior['n'] + (int) $pe['net_minor'];
        $unverified = (bool) array_filter($lines, fn($l) => $l['kind'] === 'tax' && $l['note'] !== null);
        return [
            'number' => sprintf('PS-%s-%04d-%s', str_replace('-', '', substr((string) $run['pay_date'], 0, 7)), (int) $run['id'], $snap['employee_no'] ?? $pe['employee_id']),
            'employer' => ['name' => $entity['legal_name'] ?: $entity['name'], 'address' => $entity['address'], 'country' => $entity['country'], 'logo' => $entity['logo'], 'title' => $entity['payslip_title'] ?: 'Payslip'],
            'employee' => [
                'name' => $snap['name'] ?? '', 'employee_no' => $snap['employee_no'] ?? '', 'department' => $snap['department'] ?? null, 'job_title' => $snap['job_title'] ?? null,
                'country' => $snap['country'] ?? null, 'state_region' => $snap['state_region'] ?? null, 'work_email' => $snap['work_email'] ?? null,
                'tax_id' => $snap['tax_id_masked'] ?? null, 'bank_name' => $snap['bank_name'] ?? null, 'bank_account' => $snap['bank_account_masked'] ?? null,
            ],
            'period' => ['start' => $run['period_start'], 'end' => $run['period_end'], 'pay_date' => $run['pay_date'], 'frequency' => $run['frequency']],
            'currency' => $c,
            'earnings' => $by('earning'), 'taxes' => $by('tax'), 'benefits' => $by('benefit'), 'deductions' => $by('deduction'),
            'totals' => ['gross' => Money::fmt((int) $pe['gross_minor'], $c), 'taxes' => Money::fmt((int) $pe['taxes_minor'], $c), 'benefits' => Money::fmt((int) $pe['benefits_minor'], $c),
                'deductions' => Money::fmt((int) $pe['deductions_minor'], $c), 'total_deductions' => Money::fmt($totalDed, $c), 'net' => Money::fmt((int) $pe['net_minor'], $c),
                'employer_contrib' => Money::fmt((int) $pe['employer_contrib_minor'], $c)],
            'ytd' => ['gross' => Money::fmt($ytdG, $c), 'taxes' => Money::fmt($ytdT, $c), 'deductions' => Money::fmt($ytdD, $c), 'net' => Money::fmt($ytdN, $c)],
            'run' => ['id' => (int) $run['id'], 'name' => $run['name'], 'approved_at' => $run['approved_at']],
            'notes' => $unverified ? ['Tax figures on this payslip use sample rules that have not been verified.'] : [],
        ];
    }

    public static function generateForRun(array $run, int $userId): int
    {
        $rows = Db::all('SELECT * FROM payroll_employees WHERE run_id = ? AND included = 1 ORDER BY id', [(int) $run['id']]);
        $n = 0;
        foreach ($rows as $pe) {
            $doc = self::buildDoc($run, $pe);
            $n++;
            Db::insert('payslips', [
                'number' => $doc['number'], 'run_id' => (int) $run['id'], 'payroll_employee_id' => (int) $pe['id'], 'employee_id' => (int) $pe['employee_id'],
                'period_start' => $run['period_start'], 'period_end' => $run['period_end'], 'pay_date' => $run['pay_date'], 'currency' => $run['currency'],
                'gross_minor' => (int) $pe['gross_minor'], 'deductions_minor' => (int) $pe['taxes_minor'] + (int) $pe['benefits_minor'] + (int) $pe['deductions_minor'],
                'net_minor' => (int) $pe['net_minor'], 'status' => 'generated', 'doc_json' => json_encode($doc, JSON_UNESCAPED_UNICODE),
                'generated_at' => Db::now(), 'generated_by' => $userId,
            ]);
        }
        return $n;
    }

    /** A payslip the caller may see, or 404. Employees can only ever see their own. */
    public static function authorized(int $id): array
    {
        $u = Auth::requireUser();
        $r = Db::one(self::SELECT . ' WHERE p.id = ?', [$id]);
        if (!$r) {
            throw new ApiError(404, 'not_found', 'Payslip not found.');
        }
        $own = $u['employee_id'] !== null && (int) $r['employee_id'] === $u['employee_id'];
        if (!Auth::can('payslips.read') && !$own) {
            throw new ApiError(404, 'not_found', 'Payslip not found.');
        }
        return $r;
    }

    public static function get(int $id): array
    {
        $r = self::authorized($id);
        return self::fmt($r) + ['doc' => json_decode($r['doc_json'], true)];
    }

    public static function list(array $q, bool $onlyMine = false): array
    {
        $u = Auth::requireUser();
        $where = ['1=1'];
        $p = [];
        if ($onlyMine || !Auth::can('payslips.read')) {
            if ($u['employee_id'] === null) {
                return ['items' => [], 'total' => 0];
            }
            $where[] = 'p.employee_id = ?';
            $p[] = $u['employee_id'];
        } elseif (!empty($q['employee_id'])) {
            $where[] = 'p.employee_id = ?';
            $p[] = (int) $q['employee_id'];
        }
        if (!empty($q['q'])) {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $q['q']) . '%';
            $where[] = "(e.first_name LIKE ? ESCAPE '\\' OR e.last_name LIKE ? ESCAPE '\\' OR e.employee_no LIKE ? ESCAPE '\\' OR p.number LIKE ? ESCAPE '\\')";
            array_push($p, $like, $like, $like, $like);
        }
        foreach (['run_id' => 'p.run_id', 'status' => 'p.status', 'department' => 'pe.department_name', 'currency' => 'p.currency'] as $k => $col) {
            if (!empty($q[$k])) {
                $where[] = $col . ' = ?';
                $p[] = $q[$k];
            }
        }
        if (!empty($q['period_start'])) {
            $where[] = 'p.period_start = ?';
            $p[] = $q['period_start'];
        }
        if (!empty($q['from'])) {
            $where[] = 'p.pay_date >= ?';
            $p[] = $q['from'];
        }
        if (!empty($q['to'])) {
            $where[] = 'p.pay_date <= ?';
            $p[] = $q['to'];
        }
        $limit = max(1, min(200, (int) ($q['limit'] ?? 100)));
        $offset = max(0, (int) ($q['offset'] ?? 0));
        $sql = ' WHERE ' . implode(' AND ', $where);
        $total = (int) Db::val('SELECT COUNT(*) FROM payslips p JOIN payroll_employees pe ON pe.id = p.payroll_employee_id JOIN employees e ON e.id = p.employee_id' . $sql, $p);
        $rows = Db::all(self::SELECT . $sql . " ORDER BY p.pay_date DESC, e.last_name, p.id DESC LIMIT {$limit} OFFSET {$offset}", $p);
        return ['items' => array_map([self::class, 'fmt'], $rows), 'total' => $total];
    }

    public static function recordSent(int $id, string $to): void
    {
        Db::run("UPDATE payslips SET status = 'sent', sent_at = ?, sent_to = ?, sent_count = sent_count + 1 WHERE id = ?", [Db::now(), $to, $id]);
        Audit::log('Payslip Sent', 'payslip', $id, null, ['to' => $to]);
    }
}
