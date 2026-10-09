<?php
declare(strict_types=1);

namespace P360;

/** Employees and their payroll profile. Authorization is decided here, on the server. */
final class Employees
{
    private const SELECT = "SELECT e.*, d.name AS department, en.name AS entity_name, TRIM(m.first_name || ' ' || m.last_name) AS manager_name,
            s.name AS schedule_name, (SELECT COUNT(*) FROM users u WHERE u.employee_id = e.id) AS has_user
        FROM employees e
        LEFT JOIN departments d ON d.id = e.department_id
        JOIN legal_entities en ON en.id = e.entity_id
        LEFT JOIN employees m ON m.id = e.manager_id
        LEFT JOIN payroll_schedules s ON s.id = e.schedule_id";

    public static function fullName(array $r): string
    {
        return trim($r['first_name'] . ' ' . ($r['middle_name'] ? $r['middle_name'] . ' ' : '') . $r['last_name']);
    }

    public static function row(int $id): array
    {
        $r = Db::one(self::SELECT . ' WHERE e.id = ?', [$id]);
        if (!$r) {
            throw new ApiError(404, 'not_found', 'Employee not found.');
        }
        return $r;
    }

    /** Access level the signed-in user has on an employee: full | limited | self | team | null. */
    public static function level(array $emp): ?string
    {
        $u = Auth::requireUser();
        if (Auth::can('employees.read')) {
            return Auth::can('employees.sensitive') ? 'full' : 'limited';
        }
        if ($u['employee_id'] !== null && $u['employee_id'] === (int) $emp['id']) {
            return 'self';
        }
        if (Auth::can('team.read') && $u['employee_id'] !== null && (int) ($emp['manager_id'] ?? 0) === $u['employee_id']) {
            return 'team';
        }
        return null;
    }

    /** Load an employee the caller may see, or 404 (never reveals that the record exists). */
    public static function authorized(int $id): array
    {
        $r = Db::one(self::SELECT . ' WHERE e.id = ?', [$id]);
        if (!$r || self::level($r) === null) {
            throw new ApiError(404, 'not_found', 'Employee not found.');
        }
        return $r;
    }

    public static function fmt(array $r, string $level): array
    {
        $team = [
            'id' => (int) $r['id'], 'employee_no' => $r['employee_no'], 'first_name' => $r['first_name'], 'middle_name' => $r['middle_name'],
            'last_name' => $r['last_name'], 'full_name' => self::fullName($r), 'work_email' => $r['work_email'], 'job_title' => $r['job_title'],
            'department_id' => $r['department_id'] !== null ? (int) $r['department_id'] : null, 'department' => $r['department'],
            'employment_type' => $r['employment_type'], 'employment_status' => $r['employment_status'], 'joining_date' => $r['joining_date'],
            'work_location' => $r['work_location'], 'country' => $r['country'],
        ];
        if ($level === 'team') {
            return $team;
        }
        $taxId = Crypto::dec($r['tax_id_enc']);
        $bank = Crypto::dec($r['bank_account_enc']);
        $full = $level === 'full';
        return $team + [
            'personal_email' => $r['personal_email'], 'phone' => $r['phone'],
            'entity_id' => (int) $r['entity_id'], 'entity_name' => $r['entity_name'],
            'manager_id' => $r['manager_id'] !== null ? (int) $r['manager_id'] : null, 'manager_name' => $r['manager_name'],
            'probation_end' => $r['probation_end'], 'termination_date' => $r['termination_date'],
            'state_region' => $r['state_region'], 'currency' => $r['currency'], 'tax_jurisdiction' => $r['tax_jurisdiction'],
            'payroll_frequency' => $r['payroll_frequency'], 'schedule_id' => $r['schedule_id'] !== null ? (int) $r['schedule_id'] : null,
            'schedule_name' => $r['schedule_name'],
            'tax_id' => $full ? $taxId : Crypto::mask($taxId), 'tax_id_set' => $taxId !== null,
            'bank_name' => $r['bank_name'], 'bank_account' => $full ? $bank : Crypto::mask($bank), 'bank_set' => $bank !== null,
            'has_login' => (int) $r['has_user'] > 0, 'created_at' => $r['created_at'], 'updated_at' => $r['updated_at'],
        ];
    }

    public static function list(array $q): array
    {
        $u = Auth::requireUser();
        $canAll = Auth::can('employees.read');
        if (!$canAll && !Auth::can('team.read')) {
            throw new ApiError(403, 'forbidden', 'You do not have permission to do this.');
        }
        $where = ['1=1'];
        $p = [];
        if (!$canAll) {
            $where[] = 'e.manager_id = ?';
            $p[] = (int) ($u['employee_id'] ?? 0);
        }
        if (!empty($q['q'])) {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $q['q']) . '%';
            $where[] = "(e.first_name LIKE ? ESCAPE '\\' OR e.last_name LIKE ? ESCAPE '\\' OR e.employee_no LIKE ? ESCAPE '\\' OR e.work_email LIKE ? ESCAPE '\\')";
            array_push($p, $like, $like, $like, $like);
        }
        foreach (['status' => 'e.employment_status', 'department_id' => 'e.department_id', 'country' => 'e.country', 'entity_id' => 'e.entity_id',
            'employment_type' => 'e.employment_type', 'schedule_id' => 'e.schedule_id', 'frequency' => 'e.payroll_frequency'] as $k => $col) {
            if (!empty($q[$k])) {
                $where[] = $col . ' = ?';
                $p[] = $q[$k];
            }
        }
        $sortMap = ['name' => 'e.last_name, e.first_name', 'employee_no' => 'e.employee_no', 'joining_date' => 'e.joining_date', 'status' => 'e.employment_status', 'department' => 'd.name'];
        $order = $sortMap[$q['sort'] ?? 'name'] ?? $sortMap['name'];
        $dir = (($q['dir'] ?? 'asc') === 'desc') ? 'DESC' : 'ASC';
        $limit = max(1, min(200, (int) ($q['limit'] ?? 50)));
        $offset = max(0, (int) ($q['offset'] ?? 0));
        $sqlWhere = ' WHERE ' . implode(' AND ', $where);
        $total = (int) Db::val('SELECT COUNT(*) FROM employees e LEFT JOIN departments d ON d.id = e.department_id' . $sqlWhere, $p);
        $rows = Db::all(self::SELECT . $sqlWhere . " ORDER BY {$order} {$dir} LIMIT {$limit} OFFSET {$offset}", $p);
        $items = array_map(fn($r) => self::fmt($r, self::level($r) ?? 'team'), $rows);
        return ['items' => $items, 'total' => $total, 'limit' => $limit, 'offset' => $offset];
    }

    public static function get(int $id): array
    {
        $r = self::authorized($id);
        $level = self::level($r);
        $out = self::fmt($r, $level);
        if ($level === 'full' || $level === 'limited' || $level === 'self') {
            if (Auth::can('compensation.read') || $level === 'self') {
                $c = Compensation::current($id);
                $out['current_compensation'] = $c ? Compensation::fmt($c) : null;
            }
        }
        $out['access_level'] = $level;
        return $out;
    }

    /** Validate employee fields shared by create and update. */
    private static function validate(array $in, ?array $cur): array
    {
        $v = new Validator($in);
        $creating = $cur === null;
        $d = [];
        $d['first_name'] = $v->str('first_name', $creating, 60);
        $d['middle_name'] = $v->str('middle_name', false, 60);
        $d['last_name'] = $v->str('last_name', $creating, 60);
        $d['work_email'] = $v->email('work_email', $creating);
        $d['personal_email'] = $v->email('personal_email', false);
        $d['phone'] = $v->str('phone', false, 30);
        if ($d['phone'] !== null && !preg_match('/^[0-9+()\-\s.]{5,30}$/', $d['phone'])) {
            $v->fail('phone', 'Enter a valid phone number.');
        }
        $d['employee_no'] = $v->str('employee_no', false, 30);
        $entityId = $v->int('entity_id', $creating, 1);
        $entity = $entityId !== null ? Db::one('SELECT * FROM legal_entities WHERE id = ?', [$entityId]) : null;
        if ($entityId !== null && (!$entity || (int) $entity['active'] !== 1)) {
            $v->fail('entity_id', 'Choose an active legal entity.');
        }
        $d['entity_id'] = $entityId;
        $dept = $v->int('department_id', false, 1);
        if ($dept !== null && !Db::one('SELECT id FROM departments WHERE id = ?', [$dept])) {
            $v->fail('department_id', 'Choose a valid department.');
        }
        $d['department_id'] = $dept;
        $mgr = $v->int('manager_id', false, 1);
        if ($mgr !== null && (!Db::one('SELECT id FROM employees WHERE id = ?', [$mgr]) || ($cur && $mgr === (int) $cur['id']))) {
            $v->fail('manager_id', 'Choose a valid manager.');
        }
        $d['manager_id'] = $mgr;
        $d['job_title'] = $v->str('job_title', false, 100);
        $d['employment_type'] = $v->enum('employment_type', Meta::EMPLOYMENT_TYPES, $creating);
        $d['employment_status'] = $v->enum('employment_status', Meta::STATUSES, false, $creating ? 'ACTIVE' : null);
        $d['joining_date'] = $v->date('joining_date', $creating);
        $d['probation_end'] = $v->date('probation_end', false);
        $d['termination_date'] = $v->date('termination_date', false);
        $d['work_location'] = $v->str('work_location', false, 100);
        $d['country'] = $v->enum('country', array_keys(Meta::COUNTRIES), $creating);
        $d['state_region'] = $v->str('state_region', false, 40);
        $country = $d['country'] ?? ($cur['country'] ?? null);
        if ($d['state_region'] !== null && $country === 'US' && !in_array(strtoupper($d['state_region']), Meta::US_STATES, true)) {
            $v->fail('state_region', 'Use a two-letter US state code (e.g. CA).');
        } elseif ($d['state_region'] !== null && $country === 'US') {
            $d['state_region'] = strtoupper($d['state_region']);
        }
        $d['currency'] = $v->enum('currency', Meta::currencies(), false, $creating ? (Meta::COUNTRIES[$country][1] ?? null) : null);
        $d['tax_jurisdiction'] = $v->str('tax_jurisdiction', false, 60);
        $d['payroll_frequency'] = $v->enum('payroll_frequency', Engine::FREQUENCIES, $creating);
        $d['schedule_id'] = $v->int('schedule_id', false, 1);

        $joining = $d['joining_date'] ?? ($cur['joining_date'] ?? null);
        if ($joining && $d['probation_end'] && $d['probation_end'] < $joining) {
            $v->fail('probation_end', 'Probation cannot end before the joining date.');
        }
        if ($joining && $d['termination_date'] && $d['termination_date'] < $joining) {
            $v->fail('termination_date', 'Termination cannot be before the joining date.');
        }
        $status = $d['employment_status'] ?? ($cur['employment_status'] ?? null);
        $term = array_key_exists('termination_date', $in) ? $d['termination_date'] : ($cur['termination_date'] ?? null);
        if ($status === 'TERMINATED' && !$term) {
            $v->fail('termination_date', 'A termination date is required for terminated employees.');
        }
        if (!$creating) {
            // An update may leave a field out, but it may not blank out one that payroll depends on.
            foreach (['first_name', 'last_name', 'work_email', 'entity_id', 'employment_type', 'employment_status', 'joining_date', 'country', 'currency', 'payroll_frequency'] as $req) {
                if (array_key_exists($req, $in) && ($in[$req] === null || $in[$req] === '')) {
                    $v->fail($req, 'This field is required.');
                }
            }
        }
        $v->errors += self::sensitiveGuard($in);
        $v->done();
        return [$d, $entity];
    }

    private static function sensitiveGuard(array $in): array
    {
        foreach (['tax_id', 'bank_account', 'bank_name'] as $k) {
            if (!empty($in[$k]) && !Auth::can('employees.sensitive')) {
                throw new ApiError(403, 'forbidden', 'You do not have permission to change tax or bank details.');
            }
        }
        $err = [];
        foreach (['tax_id' => 40, 'bank_account' => 60, 'bank_name' => 100] as $k => $max) {
            if (isset($in[$k]) && is_string($in[$k]) && mb_strlen($in[$k]) > $max) {
                $err[$k] = "Must be {$max} characters or fewer.";
            }
        }
        return $err;
    }

    /** Pick the active schedule matching an employee's entity, frequency and currency. */
    private static function autoSchedule(int $entityId, string $frequency, string $currency): ?int
    {
        $r = Db::one("SELECT id FROM payroll_schedules WHERE status = 'active' AND entity_id = ? AND frequency = ? AND currency = ? ORDER BY id LIMIT 1", [$entityId, $frequency, $currency]);
        return $r ? (int) $r['id'] : null;
    }

    private static function checkSchedule(?int $scheduleId, array $row, Validator $v): void
    {
        if ($scheduleId === null) {
            return;
        }
        $s = Db::one('SELECT * FROM payroll_schedules WHERE id = ?', [$scheduleId]);
        if (!$s) {
            $v->fail('schedule_id', 'Choose a valid payroll schedule.');
        } elseif ($s['frequency'] !== $row['payroll_frequency']) {
            $v->fail('schedule_id', 'That schedule is ' . $s['frequency'] . ' but the employee is paid ' . $row['payroll_frequency'] . '.');
        } elseif ($s['currency'] !== $row['currency']) {
            $v->fail('schedule_id', 'That schedule pays in ' . $s['currency'] . ' but the employee is paid in ' . $row['currency'] . '.');
        }
    }

    public static function create(array $in): array
    {
        [$d, $entity] = self::validate($in, null);
        if (Db::one('SELECT id FROM employees WHERE work_email = ? COLLATE NOCASE', [$d['work_email']])) {
            throw new ApiError(409, 'duplicate', 'An employee with this work email already exists.', ['work_email' => 'Already in use.']);
        }
        $no = $d['employee_no'];
        if ($no === null) {
            $no = sprintf('E%05d', ((int) Db::val('SELECT COALESCE(MAX(id), 0) FROM employees')) + 1);
        }
        if (Db::one('SELECT id FROM employees WHERE employee_no = ? COLLATE NOCASE', [$no])) {
            throw new ApiError(409, 'duplicate', 'This employee ID is already in use.', ['employee_no' => 'Already in use.']);
        }
        $row = [
            'employee_no' => $no, 'first_name' => $d['first_name'], 'middle_name' => $d['middle_name'], 'last_name' => $d['last_name'],
            'work_email' => $d['work_email'], 'personal_email' => $d['personal_email'], 'phone' => $d['phone'], 'entity_id' => $d['entity_id'],
            'department_id' => $d['department_id'], 'manager_id' => $d['manager_id'], 'job_title' => $d['job_title'],
            'employment_type' => $d['employment_type'], 'employment_status' => $d['employment_status'], 'joining_date' => $d['joining_date'],
            'probation_end' => $d['probation_end'], 'termination_date' => $d['termination_date'], 'work_location' => $d['work_location'],
            'country' => $d['country'], 'state_region' => $d['state_region'], 'currency' => $d['currency'],
            'tax_jurisdiction' => $d['tax_jurisdiction'] ?? ($d['country'] . ($d['state_region'] ? '-' . $d['state_region'] : '')),
            'payroll_frequency' => $d['payroll_frequency'],
            'tax_id_enc' => Crypto::enc(isset($in['tax_id']) ? trim((string) $in['tax_id']) : null),
            'bank_name' => isset($in['bank_name']) && trim((string) $in['bank_name']) !== '' ? trim((string) $in['bank_name']) : null,
            'bank_account_enc' => Crypto::enc(isset($in['bank_account']) ? trim((string) $in['bank_account']) : null),
            'created_at' => Db::now(), 'updated_at' => Db::now(),
        ];
        $sv = new Validator([]);
        self::checkSchedule($d['schedule_id'], $row, $sv);
        $sv->done();
        $row['schedule_id'] = $d['schedule_id'] ?? self::autoSchedule((int) $row['entity_id'], $row['payroll_frequency'], $row['currency']);

        return Db::tx(function () use ($row, $in) {
            $id = Db::insert('employees', $row);
            if (isset($in['compensation']) && is_array($in['compensation'])) {
                Compensation::setInitial($id, $in['compensation'], $row['currency'], $row['joining_date']);
            }
            foreach ((array) ($in['components'] ?? []) as $i => $a) {
                if (!is_array($a)) {
                    continue;
                }
                try {
                    Assignments::addFor($id, $row['currency'], $a);
                } catch (ApiError $e) {
                    throw new ApiError(422, 'validation_failed', 'Component ' . ($i + 1) . ': ' . $e->getMessage(), ['components' => $e->getMessage()] + $e->fields);
                }
            }
            Audit::log('Employee Created', 'employee', $id, null, $row);
            return self::get($id);
        });
    }

    public static function update(int $id, array $in): array
    {
        $cur = self::row($id);
        [$d, $entity] = self::validate($in, $cur);
        $row = [];
        foreach (['first_name', 'middle_name', 'last_name', 'work_email', 'personal_email', 'phone', 'entity_id', 'department_id', 'manager_id', 'job_title',
            'employment_type', 'employment_status', 'joining_date', 'probation_end', 'termination_date', 'work_location', 'country', 'state_region',
            'currency', 'tax_jurisdiction', 'payroll_frequency', 'schedule_id'] as $k) {
            if (array_key_exists($k, $in)) {
                $row[$k] = $d[$k];
            }
        }
        if (array_key_exists('employee_no', $in) && $d['employee_no'] !== null && $d['employee_no'] !== $cur['employee_no']) {
            if (Db::one('SELECT id FROM employees WHERE employee_no = ? COLLATE NOCASE AND id <> ?', [$d['employee_no'], $id])) {
                throw new ApiError(409, 'duplicate', 'This employee ID is already in use.', ['employee_no' => 'Already in use.']);
            }
            $row['employee_no'] = $d['employee_no'];
        }
        if (isset($row['work_email']) && Db::one('SELECT id FROM employees WHERE work_email = ? COLLATE NOCASE AND id <> ?', [$row['work_email'], $id])) {
            throw new ApiError(409, 'duplicate', 'An employee with this work email already exists.', ['work_email' => 'Already in use.']);
        }
        if (isset($row['currency']) && $row['currency'] !== $cur['currency'] && Compensation::current($id)) {
            throw new ApiError(422, 'validation_failed', 'Currency cannot change while compensation exists. Add a new compensation record instead.', ['currency' => 'Locked once pay is set.']);
        }
        $merged = array_merge($cur, $row);
        $sv = new Validator([]);
        self::checkSchedule($merged['schedule_id'] !== null ? (int) $merged['schedule_id'] : null, $merged, $sv);
        $sv->done();
        if (!empty($in['tax_id'])) {
            $row['tax_id_enc'] = Crypto::enc(trim((string) $in['tax_id']));
        }
        if (!empty($in['bank_account'])) {
            $row['bank_account_enc'] = Crypto::enc(trim((string) $in['bank_account']));
        }
        if (array_key_exists('bank_name', $in) && Auth::can('employees.sensitive')) {
            $row['bank_name'] = trim((string) $in['bank_name']) !== '' ? trim((string) $in['bank_name']) : null;
        }
        if (!$row) {
            return self::get($id);
        }
        $row['updated_at'] = Db::now();
        $before = array_intersect_key($cur, $row);
        Db::update('employees', $id, $row);
        Audit::log('Employee Updated', 'employee', $id, $before, $row);
        // Ending employment should also end their access. Deactivate (never delete) so compensation and audit
        // history keep their author, and sign them out everywhere. The last active administrator is never touched.
        if (array_key_exists('employment_status', $row) && $row['employment_status'] === 'TERMINATED' && $cur['employment_status'] !== 'TERMINATED') {
            $linked = Db::all('SELECT id, role FROM users WHERE employee_id = ? AND active = 1', [$id]);
            $changed = 0;
            foreach ($linked as $u) {
                if ($u['role'] === 'ADMIN' && (int) Db::val("SELECT COUNT(*) FROM users WHERE role = 'ADMIN' AND active = 1") <= 1) {
                    continue; // never lock out the only administrator automatically
                }
                Db::update('users', (int) $u['id'], ['active' => 0]);
                Db::run('DELETE FROM sessions WHERE user_id = ?', [(int) $u['id']]);
                $changed++;
            }
            if ($changed > 0) {
                Audit::log('Sign-in deactivated (employment ended)', 'employee', $id, null, ['users_deactivated' => $changed]);
            }
        }
        return self::get($id);
    }

    /** Bulk import (from CSV). Each row is created independently; failures are reported per row. */
    public static function import(array $rows): array
    {
        if (count($rows) > 500) {
            throw new ApiError(422, 'too_many_rows', 'Import at most 500 employees at a time.');
        }
        $results = [];
        foreach (array_values($rows) as $i => $r) {
            $line = $i + 1;
            try {
                if (!is_array($r)) {
                    throw new ApiError(422, 'validation_failed', 'Row is not valid.');
                }
                if (!empty($r['department']) && empty($r['department_id'])) {
                    $r['department_id'] = Departments::findOrCreate((string) $r['department']);
                }
                if (empty($r['entity_id']) && !empty($r['entity'])) {
                    $e = Db::one('SELECT id FROM legal_entities WHERE name = ? COLLATE NOCASE', [(string) $r['entity']]);
                    $r['entity_id'] = $e ? (int) $e['id'] : null;
                }
                if (!empty($r['base']) && empty($r['compensation'])) {
                    $r['compensation'] = ['pay_basis' => $r['pay_basis'] ?? 'annual', 'base' => $r['base'], 'effective_from' => $r['effective_from'] ?? ($r['joining_date'] ?? null)];
                }
                $emp = self::create($r);
                $results[] = ['row' => $line, 'ok' => true, 'id' => $emp['id'], 'employee_no' => $emp['employee_no']];
            } catch (ApiError $e) {
                $msg = $e->getMessage();
                if ($e->fields) {
                    $msg .= ' ' . implode(' ', array_map(fn($k, $v) => "{$k}: {$v}", array_keys($e->fields), $e->fields));
                }
                $results[] = ['row' => $line, 'ok' => false, 'error' => $msg];
            }
        }
        $ok = count(array_filter($results, fn($x) => $x['ok']));
        Audit::log('Employees Imported', 'employee', null, null, ['created' => $ok, 'failed' => count($results) - $ok]);
        return ['results' => $results, 'created' => $ok, 'failed' => count($results) - $ok];
    }
}

/** Compensation with full history. Rows are never overwritten. */
final class Compensation
{
    public static function fmt(array $r): array
    {
        $cur = $r['currency'];
        return [
            'id' => (int) $r['id'], 'employee_id' => (int) $r['employee_id'], 'pay_basis' => $r['pay_basis'], 'base' => Money::fmt((int) $r['base_minor'], $cur),
            'currency' => $cur, 'annual_equivalent' => Money::fmt(Engine::annualize((int) $r['base_minor'], $r['pay_basis']), $cur),
            'overtime_multiplier_pct' => Money::fmt((int) $r['overtime_multiplier_bp'], 'USD'),
            'effective_from' => $r['effective_from'], 'effective_to' => $r['effective_to'], 'reason' => $r['reason'],
            'previous_id' => $r['previous_id'] !== null ? (int) $r['previous_id'] : null,
            'created_by_name' => $r['created_by_name'] ?? null, 'created_at' => $r['created_at'],
        ];
    }

    public static function current(int $empId, ?string $date = null): ?array
    {
        $date ??= Dates::today();
        return Db::one(
            'SELECT * FROM compensation WHERE employee_id = ? AND effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?) ORDER BY effective_from DESC LIMIT 1',
            [$empId, $date, $date]
        );
    }

    public static function history(int $empId): array
    {
        $rows = Db::all(
            'SELECT c.*, u.name AS created_by_name FROM compensation c LEFT JOIN users u ON u.id = c.created_by WHERE c.employee_id = ? ORDER BY c.effective_from DESC, c.id DESC',
            [$empId]
        );
        $byId = [];
        foreach ($rows as $r) {
            $byId[(int) $r['id']] = $r;
        }
        return array_map(function ($r) use ($byId) {
            $out = self::fmt($r);
            $p = $r['previous_id'] !== null ? ($byId[(int) $r['previous_id']] ?? null) : null;
            $out['previous'] = $p ? ['base' => Money::fmt((int) $p['base_minor'], $p['currency']), 'pay_basis' => $p['pay_basis'], 'currency' => $p['currency'], 'effective_from' => $p['effective_from']] : null;
            return $out;
        }, $rows);
    }

    private static function validate(array $in, string $currency, bool $reasonRequired): array
    {
        $v = new Validator($in);
        $basis = $v->enum('pay_basis', Meta::PAY_BASES);
        $base = $v->money('base', $currency, true);
        if ($base !== null && $base <= 0) {
            $v->fail('base', 'Compensation must be greater than zero.');
        }
        $from = $v->date('effective_from', true);
        $reason = $v->str('reason', $reasonRequired, 300);
        $ot = $v->pct('overtime_multiplier_pct', false, 400.0);
        if ($ot !== null && $ot < 10000) {
            $v->fail('overtime_multiplier_pct', 'Overtime multiplier must be at least 100%.');
        }
        $v->done();
        return [$basis, $base, $from, $reason, $ot ?? 15000];
    }

    public static function setInitial(int $empId, array $in, string $currency, string $joining): void
    {
        $in['effective_from'] = $in['effective_from'] ?? $joining;
        [$basis, $base, $from, $reason, $ot] = self::validate($in, $currency, false);
        Db::insert('compensation', [
            'employee_id' => $empId, 'pay_basis' => $basis, 'base_minor' => $base, 'currency' => $currency, 'overtime_multiplier_bp' => $ot,
            'effective_from' => $from, 'effective_to' => null, 'reason' => $reason ?? 'Initial compensation', 'previous_id' => null,
            'created_by' => Auth::uid(), 'created_at' => Db::now(),
        ]);
        Audit::log('Employee Compensation Set', 'employee', $empId, null, ['pay_basis' => $basis, 'base_minor' => $base, 'currency' => $currency, 'effective_from' => $from]);
    }

    public static function change(int $empId, array $in): array
    {
        $emp = Employees::row($empId);
        [$basis, $base, $from, $reason, $ot] = self::validate($in, $emp['currency'], true);
        return Db::tx(function () use ($empId, $emp, $basis, $base, $from, $reason, $ot) {
            $latest = Db::one('SELECT * FROM compensation WHERE employee_id = ? ORDER BY effective_from DESC, id DESC LIMIT 1', [$empId]);
            if ($latest && $from <= $latest['effective_from']) {
                throw new ApiError(422, 'validation_failed', 'The effective date must be after the current compensation started (' . $latest['effective_from'] . ').', ['effective_from' => 'Must be after ' . $latest['effective_from'] . '.']);
            }
            if ($latest && $latest['effective_to'] === null) {
                Db::run('UPDATE compensation SET effective_to = ? WHERE id = ?', [Dates::add($from, -1), $latest['id']]);
            }
            $id = Db::insert('compensation', [
                'employee_id' => $empId, 'pay_basis' => $basis, 'base_minor' => $base, 'currency' => $emp['currency'], 'overtime_multiplier_bp' => $ot,
                'effective_from' => $from, 'effective_to' => null, 'reason' => $reason, 'previous_id' => $latest ? (int) $latest['id'] : null,
                'created_by' => Auth::uid(), 'created_at' => Db::now(),
            ]);
            Audit::log(
                'Employee Compensation Changed',
                'employee',
                $empId,
                $latest ? ['pay_basis' => $latest['pay_basis'], 'base_minor' => (int) $latest['base_minor'], 'effective_from' => $latest['effective_from']] : null,
                ['pay_basis' => $basis, 'base_minor' => $base, 'effective_from' => $from, 'reason' => $reason]
            );
            return ['current' => self::fmt(Db::one('SELECT * FROM compensation WHERE id = ?', [$id])), 'history' => self::history($empId)];
        });
    }
}

/** Allowances, benefits and deductions assigned to one employee. */
final class Assignments
{
    private static function taxableOf(array $r): bool
    {
        return (int) ($r['a_taxable'] ?? $r['c_taxable']) === 1;
    }

    public static function fmt(array $r, string $cur): array
    {
        return [
            'id' => (int) $r['id'], 'employee_id' => (int) $r['employee_id'],
            'component' => ['id' => (int) $r['component_id'], 'code' => $r['code'], 'name' => $r['name'], 'kind' => $r['kind'], 'category' => $r['category'], 'calc' => $r['calc'], 'basis' => $r['basis']],
            'amount' => $r['amount_minor'] !== null ? Money::fmt((int) $r['amount_minor'], $cur) : null,
            'rate_pct' => $r['rate_bp'] !== null ? Money::fmt((int) $r['rate_bp'], 'USD') : null,
            'employer_amount' => $r['employer_amount_minor'] !== null ? Money::fmt((int) $r['employer_amount_minor'], $cur) : null,
            'employer_rate_pct' => $r['employer_rate_bp'] !== null ? Money::fmt((int) $r['employer_rate_bp'], 'USD') : null,
            'frequency' => $r['frequency'], 'effective_from' => $r['effective_from'], 'effective_to' => $r['effective_to'],
            'taxable' => self::taxableOf($r), 'pre_tax' => (int) ($r['a_pre_tax'] ?? $r['c_pre_tax']) === 1,
            'provider' => $r['a_provider'] ?? $r['c_provider'], 'description' => $r['description'], 'status' => $r['status'],
        ];
    }

    private const SELECT = 'SELECT a.*, c.code, c.name, c.kind, c.category, c.calc, c.basis, c.taxable AS c_taxable, c.pre_tax AS c_pre_tax,
            c.provider AS c_provider, a.taxable AS a_taxable, a.pre_tax AS a_pre_tax, a.provider AS a_provider
        FROM employee_components a JOIN components c ON c.id = a.component_id';

    public static function rows(int $empId): array
    {
        return Db::all(self::SELECT . ' WHERE a.employee_id = ? ORDER BY c.kind, a.effective_from DESC, a.id DESC', [$empId]);
    }

    public static function forEmployee(int $empId): array
    {
        $emp = Employees::row($empId);
        return array_map(fn($r) => self::fmt($r, $emp['currency']), self::rows($empId));
    }

    /** Shape used by the payroll engine. */
    public static function forEngine(int $empId): array
    {
        return array_map(fn($r) => [
            'kind' => $r['kind'], 'code' => $r['code'], 'name' => $r['name'], 'category' => $r['category'], 'calc' => $r['calc'], 'basis' => $r['basis'],
            'taxable' => self::taxableOf($r) ? 1 : 0, 'pre_tax' => (int) ($r['a_pre_tax'] ?? $r['c_pre_tax']),
            'amount_minor' => $r['amount_minor'] !== null ? (int) $r['amount_minor'] : null, 'rate_bp' => $r['rate_bp'] !== null ? (int) $r['rate_bp'] : null,
            'employer_amount_minor' => $r['employer_amount_minor'] !== null ? (int) $r['employer_amount_minor'] : null,
            'employer_rate_bp' => $r['employer_rate_bp'] !== null ? (int) $r['employer_rate_bp'] : null,
            'frequency' => $r['frequency'], 'effective_from' => $r['effective_from'], 'effective_to' => $r['effective_to'], 'status' => $r['status'],
            'provider' => $r['a_provider'] ?? $r['c_provider'], 'description' => $r['description'],
        ], self::rows($empId));
    }

    private static function validate(array $in, array $component, string $currency, ?array $cur): array
    {
        $v = new Validator($in);
        $kind = $component['kind'];
        $percent = $component['calc'] === 'percent';
        $amount = $v->money('amount', $currency, false);
        $rate = $v->pct('rate_pct', false);
        $eAmount = $v->money('employer_amount', $currency, false);
        $eRate = $v->pct('employer_rate_pct', false);
        $freq = $v->enum('frequency', Meta::COMP_FREQS, false, $cur['frequency'] ?? $component['frequency']);
        $from = $v->date('effective_from', $cur === null);
        $to = $v->date('effective_to', false);
        $provider = $v->str('provider', false, 100);
        $desc = $v->str('description', false, 200);
        $status = $v->enum('status', ['active', 'inactive'], false, $cur['status'] ?? 'active');

        if ($cur === null || array_key_exists('amount', $in) || array_key_exists('rate_pct', $in) || array_key_exists('employer_amount', $in) || array_key_exists('employer_rate_pct', $in)) {
            if ($kind === 'benefit') {
                if ($percent ? ($rate === null && $eRate === null) : ($amount === null && $eAmount === null)) {
                    $v->fail($percent ? 'rate_pct' : 'amount', 'Enter the employee and/or employer contribution.');
                }
            } elseif ($percent) {
                if ($rate === null) {
                    $v->fail('rate_pct', 'Enter a percentage.');
                }
            } elseif ($amount === null) {
                $v->fail('amount', 'Enter an amount.');
            } elseif ($amount <= 0) {
                $v->fail('amount', 'Amount must be greater than zero.');
            }
        }
        if ($from && $to && $to < $from) {
            $v->fail('effective_to', 'Must be on or after the effective date.');
        }
        $v->done();

        $row = ['frequency' => $freq, 'status' => $status];
        $set = fn(string $k, string $col, mixed $val) => (array_key_exists($k, $in) || $cur === null) ? [$col => $val] : [];
        $row += $set('amount', 'amount_minor', $percent ? null : $amount);
        $row += $set('rate_pct', 'rate_bp', $percent ? $rate : null);
        $row += $set('employer_amount', 'employer_amount_minor', $percent ? null : $eAmount);
        $row += $set('employer_rate_pct', 'employer_rate_bp', $percent ? $eRate : null);
        if ($from) {
            $row['effective_from'] = $from;
        }
        if (array_key_exists('effective_to', $in)) {
            $row['effective_to'] = $to;
        }
        foreach (['taxable', 'pre_tax'] as $flag) {
            if (array_key_exists($flag, $in)) {
                $row[$flag] = ($in[$flag] === null || $in[$flag] === '') ? null : ($v->bool($flag) ? 1 : 0);
            }
        }
        foreach (['provider' => $provider, 'description' => $desc] as $k => $val) {
            if (array_key_exists($k, $in) || $cur === null) {
                $row[$k] = $val;
            }
        }
        return $row;
    }

    public static function addFor(int $empId, string $currency, array $in): int
    {
        $cid = (new Validator($in))->int('component_id', true, 1);
        if ($cid === null) {
            throw new ApiError(422, 'validation_failed', 'Choose a component.', ['component_id' => 'Choose a component.']);
        }
        $component = Components::row($cid);
        if ($component['status'] !== 'active') {
            throw new ApiError(422, 'validation_failed', 'That component is inactive.', ['component_id' => 'Component is inactive.']);
        }
        $in['effective_from'] = $in['effective_from'] ?? Dates::today();
        $row = self::validate($in, $component, $currency, null);
        $row += ['employee_id' => $empId, 'component_id' => $cid, 'created_by' => Auth::uid(), 'created_at' => Db::now(), 'updated_at' => Db::now()];
        $id = Db::insert('employee_components', $row);
        Audit::log('Employee Component Added', 'employee', $empId, null, ['component' => $component['code']] + $row);
        return $id;
    }

    public static function add(int $empId, array $in): array
    {
        $emp = Employees::row($empId);
        $id = self::addFor($empId, $emp['currency'], $in);
        return self::fmt(Db::one(self::SELECT . ' WHERE a.id = ?', [$id]), $emp['currency']);
    }

    public static function update(int $id, array $in): array
    {
        $cur = Db::one(self::SELECT . ' WHERE a.id = ?', [$id]);
        if (!$cur) {
            throw new ApiError(404, 'not_found', 'Assignment not found.');
        }
        $emp = Employees::row((int) $cur['employee_id']);
        $component = Components::row((int) $cur['component_id']);
        $row = self::validate($in, $component, $emp['currency'], $cur);
        $row['updated_at'] = Db::now();
        $before = array_intersect_key($cur, $row);
        Db::update('employee_components', $id, $row);
        Audit::log('Employee Component Updated', 'employee', (int) $cur['employee_id'], ['component' => $cur['code']] + $before, $row);
        return self::fmt(Db::one(self::SELECT . ' WHERE a.id = ?', [$id]), $emp['currency']);
    }

    /** Stop an assignment (kept for history; never deleted). */
    public static function end(int $id, ?string $date = null): array
    {
        $cur = Db::one(self::SELECT . ' WHERE a.id = ?', [$id]);
        if (!$cur) {
            throw new ApiError(404, 'not_found', 'Assignment not found.');
        }
        $date = $date && Dates::valid($date) ? $date : Dates::today();
        if ($date < $cur['effective_from']) {
            $date = $cur['effective_from'];
        }
        Db::run("UPDATE employee_components SET effective_to = ?, status = 'inactive', updated_at = ? WHERE id = ?", [$date, Db::now(), $id]);
        Audit::log('Employee Component Ended', 'employee', (int) $cur['employee_id'], ['component' => $cur['code'], 'status' => $cur['status']], ['effective_to' => $date, 'status' => 'inactive']);
        $emp = Employees::row((int) $cur['employee_id']);
        return self::fmt(Db::one(self::SELECT . ' WHERE a.id = ?', [$id]), $emp['currency']);
    }
}
