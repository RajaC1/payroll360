<?php
declare(strict_types=1);

namespace P360;

/** Static lists used by validation and by the UI dropdowns. */
final class Meta
{
    public const EMPLOYMENT_TYPES = ['FULL_TIME', 'PART_TIME', 'TEMPORARY', 'CONTRACT', 'INTERN'];
    public const STATUSES = ['ACTIVE', 'ON_LEAVE', 'SUSPENDED', 'TERMINATED', 'INACTIVE'];
    public const PAY_BASES = ['annual', 'monthly', 'semimonthly', 'biweekly', 'weekly', 'hourly'];
    public const COMP_FREQS = ['weekly', 'biweekly', 'semimonthly', 'monthly', 'annual', 'per_period', 'one_time'];
    public const KINDS = ['earning', 'deduction', 'benefit'];
    public const CALCS = ['fixed', 'percent'];
    public const BASES = ['base', 'gross', 'taxable_gross'];
    public const TAX_TYPES = ['income_tax', 'social_security', 'medicare', 'provident_fund', 'local_tax', 'other'];
    public const TAX_METHODS = ['flat', 'bracket', 'capped', 'fixed'];
    public const BASE_KINDS = ['taxable_income', 'gross_taxable'];
    public const ADJ_KINDS = ['earning', 'deduction', 'overtime_hours'];

    /** ISO code => [name, currency] */
    public const COUNTRIES = [
        'US' => ['United States', 'USD'], 'GB' => ['United Kingdom', 'GBP'], 'DE' => ['Germany', 'EUR'], 'FR' => ['France', 'EUR'],
        'NL' => ['Netherlands', 'EUR'], 'ES' => ['Spain', 'EUR'], 'IT' => ['Italy', 'EUR'], 'IE' => ['Ireland', 'EUR'],
        'IN' => ['India', 'INR'], 'AU' => ['Australia', 'AUD'], 'CA' => ['Canada', 'CAD'], 'SG' => ['Singapore', 'SGD'],
        'AE' => ['United Arab Emirates', 'AED'], 'JP' => ['Japan', 'JPY'], 'CH' => ['Switzerland', 'CHF'], 'SE' => ['Sweden', 'SEK'],
        'NO' => ['Norway', 'NOK'], 'DK' => ['Denmark', 'DKK'], 'PL' => ['Poland', 'PLN'], 'BR' => ['Brazil', 'BRL'],
        'MX' => ['Mexico', 'MXN'], 'ZA' => ['South Africa', 'ZAR'], 'NZ' => ['New Zealand', 'NZD'], 'PH' => ['Philippines', 'PHP'],
        'MY' => ['Malaysia', 'MYR'], 'ID' => ['Indonesia', 'IDR'], 'TH' => ['Thailand', 'THB'], 'VN' => ['Vietnam', 'VND'],
        'KR' => ['South Korea', 'KRW'], 'HK' => ['Hong Kong', 'HKD'], 'CN' => ['China', 'CNY'], 'SA' => ['Saudi Arabia', 'SAR'],
        'NG' => ['Nigeria', 'NGN'], 'KE' => ['Kenya', 'KES'], 'EG' => ['Egypt', 'EGP'], 'TR' => ['Turkey', 'TRY'],
        'IL' => ['Israel', 'ILS'], 'AR' => ['Argentina', 'ARS'], 'CO' => ['Colombia', 'COP'], 'CL' => ['Chile', 'CLP'],
    ];

    public const US_STATES = ['AL', 'AK', 'AZ', 'AR', 'CA', 'CO', 'CT', 'DE', 'DC', 'FL', 'GA', 'HI', 'ID', 'IL', 'IN', 'IA', 'KS', 'KY', 'LA', 'ME', 'MD', 'MA', 'MI', 'MN', 'MS', 'MO', 'MT', 'NE', 'NV', 'NH', 'NJ', 'NM', 'NY', 'NC', 'ND', 'OH', 'OK', 'OR', 'PA', 'RI', 'SC', 'SD', 'TN', 'TX', 'UT', 'VT', 'VA', 'WA', 'WV', 'WI', 'WY'];

    public static function currencies(): array
    {
        return array_values(array_unique(array_column(self::COUNTRIES, 1)));
    }

    public static function payload(): array
    {
        $countries = [];
        foreach (self::COUNTRIES as $code => [$name, $cur]) {
            $countries[] = ['code' => $code, 'name' => $name, 'currency' => $cur];
        }
        return [
            'employment_types' => self::EMPLOYMENT_TYPES,
            'employment_statuses' => self::STATUSES,
            'pay_bases' => self::PAY_BASES,
            'frequencies' => Engine::FREQUENCIES,
            'component_frequencies' => self::COMP_FREQS,
            'component_kinds' => self::KINDS,
            'calc_methods' => self::CALCS,
            'bases' => self::BASES,
            'tax_types' => self::TAX_TYPES,
            'tax_methods' => self::TAX_METHODS,
            'adjustment_kinds' => self::ADJ_KINDS,
            'roles' => Perms::ROLES,
            'countries' => $countries,
            'currencies' => self::currencies(),
            'us_states' => self::US_STATES,
            'run_statuses' => ['DRAFT', 'PREPARING', 'CALCULATING', 'REVIEW', 'APPROVAL', 'APPROVED', 'PROCESSING', 'PROCESSED', 'PAID', 'CANCELLED'],
        ];
    }
}

final class Entities
{
    public static function fmt(array $r, bool $logo = true): array
    {
        return [
            'id' => (int) $r['id'], 'name' => $r['name'], 'legal_name' => $r['legal_name'], 'country' => $r['country'], 'currency' => $r['currency'],
            'address' => $r['address'], 'logo' => $logo ? $r['logo'] : null, 'has_logo' => !empty($r['logo']), 'payslip_title' => $r['payslip_title'],
            'sender_name' => $r['sender_name'], 'sender_email' => $r['sender_email'], 'reply_to' => $r['reply_to'], 'cc' => $r['cc'],
            'active' => (int) $r['active'] === 1,
        ];
    }

    public static function all(bool $logo = false): array
    {
        return array_map(fn($r) => self::fmt($r, $logo), Db::all('SELECT * FROM legal_entities ORDER BY name'));
    }

    public static function row(int $id): array
    {
        $r = Db::one('SELECT * FROM legal_entities WHERE id = ?', [$id]);
        if (!$r) {
            throw new ApiError(404, 'not_found', 'Legal entity not found.');
        }
        return $r;
    }

    private static function data(array $in, ?array $cur): array
    {
        $v = new Validator($in);
        $name = $v->str('name', $cur === null, 120);
        $legal = $v->str('legal_name', false, 160);
        $country = $v->enum('country', array_keys(Meta::COUNTRIES), $cur === null);
        $currency = $v->enum('currency', Meta::currencies(), false);
        $address = $v->str('address', false, 300);
        $title = $v->str('payslip_title', false, 60);
        $sn = $v->str('sender_name', false, 100);
        $se = $v->email('sender_email', false);
        $rt = $v->email('reply_to', false);
        $cc = $v->email('cc', false);
        $logo = null;
        $logoGiven = array_key_exists('logo', $in);
        if ($logoGiven && $in['logo'] !== null && $in['logo'] !== '') {
            $l = (string) $in['logo'];
            if (!preg_match('#^data:image/(png|jpeg);base64,([A-Za-z0-9+/=]+)$#', $l, $m) || strlen($m[2]) > 400000) {
                $v->fail('logo', 'Upload a PNG or JPEG image under 300 KB.');
            } else {
                $info = @getimagesizefromstring((string) base64_decode($m[2], true));
                if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
                    $v->fail('logo', 'That file is not a valid PNG or JPEG image.');
                }
                $logo = $l;
            }
        }
        $v->done();
        $row = [];
        foreach (['name' => $name, 'legal_name' => $legal, 'country' => $country, 'address' => $address, 'payslip_title' => $title,
            'sender_name' => $sn, 'sender_email' => $se, 'reply_to' => $rt, 'cc' => $cc] as $k => $val) {
            if ($val !== null || array_key_exists($k, $in)) {
                $row[$k] = $val;
            }
        }
        $c = $country ?? ($cur['country'] ?? null);
        $row['currency'] = $currency ?? ($cur['currency'] ?? Meta::COUNTRIES[$c][1] ?? 'USD');
        if ($logoGiven) {
            $row['logo'] = $logo;
        }
        if (array_key_exists('active', $in)) {
            $row['active'] = $v->bool('active', true) ? 1 : 0;
        }
        return $row;
    }

    public static function create(array $in): array
    {
        $row = self::data($in, null);
        $row['created_at'] = $row['updated_at'] = Db::now();
        $id = Db::insert('legal_entities', $row);
        Audit::log('Legal Entity Created', 'legal_entity', $id, null, $row);
        return self::fmt(self::row($id));
    }

    public static function update(int $id, array $in): array
    {
        $cur = self::row($id);
        $row = self::data($in, $cur);
        $row['updated_at'] = Db::now();
        Db::update('legal_entities', $id, $row);
        Audit::log('Legal Entity Updated', 'legal_entity', $id, $cur, $row);
        return self::fmt(self::row($id));
    }
}

final class Departments
{
    public static function all(): array
    {
        return array_map(fn($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'active' => (int) $r['active'] === 1], Db::all('SELECT * FROM departments ORDER BY name'));
    }

    public static function create(array $in): array
    {
        $v = new Validator($in);
        $name = $v->str('name', true, 80);
        $v->done();
        if (Db::one('SELECT id FROM departments WHERE name = ? COLLATE NOCASE', [$name])) {
            throw new ApiError(409, 'duplicate', 'That department already exists.', ['name' => 'Already exists.']);
        }
        $id = Db::insert('departments', ['name' => $name, 'active' => 1]);
        Audit::log('Department Created', 'department', $id, null, ['name' => $name]);
        return ['id' => $id, 'name' => $name, 'active' => true];
    }

    public static function findOrCreate(string $name): int
    {
        $r = Db::one('SELECT id FROM departments WHERE name = ? COLLATE NOCASE', [$name]);
        return $r ? (int) $r['id'] : Db::insert('departments', ['name' => $name, 'active' => 1]);
    }
}

/** Pay component catalogue (earnings, deductions, benefits). */
final class Components
{
    public static function fmt(array $r): array
    {
        return [
            'id' => (int) $r['id'], 'code' => $r['code'], 'name' => $r['name'], 'kind' => $r['kind'], 'category' => $r['category'],
            'calc' => $r['calc'], 'basis' => $r['basis'], 'taxable' => (int) $r['taxable'] === 1, 'pre_tax' => (int) $r['pre_tax'] === 1,
            'default_rate_pct' => $r['default_rate_bp'] !== null ? Money::fmt((int) $r['default_rate_bp'], 'USD') : null,
            'default_employer_rate_pct' => $r['default_employer_rate_bp'] !== null ? Money::fmt((int) $r['default_employer_rate_bp'], 'USD') : null,
            'frequency' => $r['frequency'], 'provider' => $r['provider'], 'effective_from' => $r['effective_from'], 'status' => $r['status'],
        ];
    }

    public static function all(?string $kind = null): array
    {
        $rows = $kind ? Db::all('SELECT * FROM components WHERE kind = ? ORDER BY name', [$kind]) : Db::all('SELECT * FROM components ORDER BY kind, name');
        return array_map([self::class, 'fmt'], $rows);
    }

    public static function row(int $id): array
    {
        $r = Db::one('SELECT * FROM components WHERE id = ?', [$id]);
        if (!$r) {
            throw new ApiError(404, 'not_found', 'Component not found.');
        }
        return $r;
    }

    private static function data(array $in, ?array $cur): array
    {
        $v = new Validator($in);
        $code = $v->str('code', $cur === null, 40);
        if ($code !== null && !preg_match('/^[A-Z][A-Z0-9_]{1,39}$/', $code)) {
            $v->fail('code', 'Use capital letters, digits and underscores (e.g. HOUSING).');
        }
        $name = $v->str('name', $cur === null, 100);
        $kind = $v->enum('kind', Meta::KINDS, $cur === null);
        $cat = $v->str('category', false, 40);
        $calc = $v->enum('calc', Meta::CALCS, false, $cur['calc'] ?? 'fixed');
        $basis = $v->enum('basis', Meta::BASES, false, $cur['basis'] ?? 'base');
        $freq = $v->enum('frequency', Meta::COMP_FREQS, false, $cur['frequency'] ?? 'monthly');
        $rate = $v->pct('default_rate_pct', false);
        $erate = $v->pct('default_employer_rate_pct', false);
        $prov = $v->str('provider', false, 100);
        $eff = $v->date('effective_from', false);
        $status = $v->enum('status', ['active', 'inactive'], false, $cur['status'] ?? 'active');
        $v->done();
        $row = ['calc' => $calc, 'basis' => $basis, 'frequency' => $freq, 'status' => $status];
        foreach (['code' => $code, 'name' => $name, 'kind' => $kind, 'category' => $cat, 'provider' => $prov, 'effective_from' => $eff] as $k => $val) {
            if ($val !== null || array_key_exists($k, $in)) {
                $row[$k] = $val;
            }
        }
        if (array_key_exists('default_rate_pct', $in)) {
            $row['default_rate_bp'] = $rate;
        }
        if (array_key_exists('default_employer_rate_pct', $in)) {
            $row['default_employer_rate_bp'] = $erate;
        }
        if (array_key_exists('taxable', $in)) {
            $row['taxable'] = $v->bool('taxable', true) ? 1 : 0;
        }
        if (array_key_exists('pre_tax', $in)) {
            $row['pre_tax'] = $v->bool('pre_tax') ? 1 : 0;
        }
        return $row;
    }

    public static function create(array $in): array
    {
        $row = self::data($in, null);
        if (Db::one('SELECT id FROM components WHERE code = ?', [$row['code']])) {
            throw new ApiError(409, 'duplicate', 'A component with this code already exists.', ['code' => 'Already in use.']);
        }
        $row['created_at'] = $row['updated_at'] = Db::now();
        $id = Db::insert('components', $row);
        Audit::log('Pay Component Created', 'component', $id, null, $row);
        return self::fmt(self::row($id));
    }

    public static function update(int $id, array $in): array
    {
        $cur = self::row($id);
        $row = self::data($in, $cur);
        if (isset($row['code']) && $row['code'] !== $cur['code'] && Db::one('SELECT id FROM components WHERE code = ?', [$row['code']])) {
            throw new ApiError(409, 'duplicate', 'A component with this code already exists.', ['code' => 'Already in use.']);
        }
        $row['updated_at'] = Db::now();
        Db::update('components', $id, $row);
        Audit::log('Pay Component Updated', 'component', $id, $cur, $row);
        return self::fmt(self::row($id));
    }
}

/** Jurisdiction-aware tax and statutory rules. Amounts in a rule are annual figures. */
final class TaxRules
{
    public static function fmt(array $r): array
    {
        $cur = $r['currency'];
        $brackets = $r['brackets_json'] ? json_decode($r['brackets_json'], true) : [];
        return [
            'id' => (int) $r['id'], 'code' => $r['code'], 'name' => $r['name'], 'country' => $r['country'], 'region' => $r['region'],
            'tax_year' => $r['tax_year'] !== null ? (int) $r['tax_year'] : null, 'tax_type' => $r['tax_type'], 'method' => $r['method'],
            'base_kind' => $r['base_kind'], 'cap_scope' => $r['cap_scope'], 'currency' => $cur,
            'rate_pct' => $r['rate_bp'] !== null ? Money::fmt((int) $r['rate_bp'], 'USD') : null,
            'threshold' => Money::fmt((int) $r['threshold_minor'], $cur),
            'cap' => $r['cap_minor'] !== null ? Money::fmt((int) $r['cap_minor'], $cur) : null,
            'fixed' => $r['fixed_minor'] !== null ? Money::fmt((int) $r['fixed_minor'], $cur) : null,
            'brackets' => array_map(fn($b) => ['up_to' => ($b['up_to_minor'] ?? null) !== null ? Money::fmt((int) $b['up_to_minor'], $cur) : null, 'rate_pct' => Money::fmt((int) $b['rate_bp'], 'USD')], $brackets),
            'employer_rate_pct' => Money::fmt((int) $r['employer_rate_bp'], 'USD'),
            'employer_threshold' => Money::fmt((int) $r['employer_threshold_minor'], $cur),
            'effective_from' => $r['effective_from'], 'effective_to' => $r['effective_to'], 'verified' => (int) $r['verified'] === 1,
            'source_note' => $r['source_note'], 'status' => $r['status'],
        ];
    }

    public static function all(array $f = []): array
    {
        $sql = 'SELECT * FROM tax_rules WHERE 1=1';
        $p = [];
        if (!empty($f['country'])) {
            $sql .= ' AND country = ?';
            $p[] = $f['country'];
        }
        return array_map([self::class, 'fmt'], Db::all($sql . ' ORDER BY country, region, tax_type, name', $p));
    }

    public static function row(int $id): array
    {
        $r = Db::one('SELECT * FROM tax_rules WHERE id = ?', [$id]);
        if (!$r) {
            throw new ApiError(404, 'not_found', 'Tax rule not found.');
        }
        return $r;
    }

    /** Rules that apply to a jurisdiction for a pay period. */
    public static function applicable(string $country, ?string $region, string $ps, string $pe): array
    {
        $year = Dates::year($pe);
        return Db::all(
            "SELECT * FROM tax_rules WHERE status = 'active' AND country = ? AND (region IS NULL OR region = '' OR region = ?)
               AND (tax_year IS NULL OR tax_year = ?) AND effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?) ORDER BY id",
            [$country, $region ?? '', $year, $pe, $ps]
        );
    }

    private static function data(array $in, ?array $cur): array
    {
        $v = new Validator($in);
        $code = $v->str('code', $cur === null, 40);
        $name = $v->str('name', $cur === null, 120);
        $country = $v->enum('country', array_keys(Meta::COUNTRIES), $cur === null);
        $region = $v->str('region', false, 40);
        $year = $v->int('tax_year', false, 1990, 2100);
        $type = $v->enum('tax_type', Meta::TAX_TYPES, $cur === null);
        $method = $v->enum('method', Meta::TAX_METHODS, $cur === null);
        $baseKind = $v->enum('base_kind', Meta::BASE_KINDS, false, $cur['base_kind'] ?? 'taxable_income');
        $capScope = $v->enum('cap_scope', ['ytd', 'period'], false, $cur['cap_scope'] ?? 'ytd');
        $currency = $v->enum('currency', Meta::currencies(), $cur === null);
        $cc = $currency ?? ($cur['currency'] ?? 'USD');
        $rate = $v->pct('rate_pct', false);
        $threshold = $v->money('threshold', $cc, false);
        $cap = $v->money('cap', $cc, false);
        $fixed = $v->money('fixed', $cc, false);
        $erate = $v->pct('employer_rate_pct', false);
        $ethr = $v->money('employer_threshold', $cc, false);
        $from = $v->date('effective_from', $cur === null);
        $to = $v->date('effective_to', false);
        $note = $v->str('source_note', false, 300);
        $status = $v->enum('status', ['active', 'inactive'], false, $cur['status'] ?? 'active');

        $mth = $method ?? ($cur['method'] ?? null);
        if ($mth === 'flat' || $mth === 'capped') {
            if ($rate === null && ($cur === null || array_key_exists('rate_pct', $in))) {
                $v->fail('rate_pct', 'A rate is required for this method.');
            }
        }
        if ($mth === 'fixed' && $fixed === null && ($cur === null || array_key_exists('fixed', $in))) {
            $v->fail('fixed', 'A fixed annual amount is required.');
        }
        $bracketsJson = null;
        $bracketsGiven = array_key_exists('brackets', $in);
        if ($mth === 'bracket' && ($cur === null || $bracketsGiven)) {
            $bs = $in['brackets'] ?? [];
            if (!is_array($bs) || !$bs) {
                $v->fail('brackets', 'Add at least one band.');
            } else {
                $out = [];
                $prev = -1;
                foreach (array_values($bs) as $i => $b) {
                    $bv = new Validator(is_array($b) ? $b : []);
                    $up = ($b['up_to'] ?? null) === null || ($b['up_to'] ?? '') === '' ? null : $bv->money('up_to', $cc, true);
                    $rt = $bv->pct('rate_pct', true);
                    if ($bv->errors) {
                        $v->fail('brackets', 'Band ' . ($i + 1) . ': ' . implode(' ', $bv->errors));
                        break;
                    }
                    if ($up === null && $i !== count($bs) - 1) {
                        $v->fail('brackets', 'Only the last band can be open-ended.');
                        break;
                    }
                    if ($up !== null && $up <= $prev) {
                        $v->fail('brackets', 'Band limits must increase.');
                        break;
                    }
                    $prev = $up ?? $prev;
                    $out[] = ['up_to_minor' => $up, 'rate_bp' => $rt];
                }
                if (!isset($v->errors['brackets'])) {
                    if (end($out)['up_to_minor'] !== null) {
                        $out[] = ['up_to_minor' => null, 'rate_bp' => end($out)['rate_bp']];
                    }
                    $bracketsJson = json_encode($out);
                }
            }
        }
        if ($from && $to && $to < $from) {
            $v->fail('effective_to', 'Must be on or after the effective-from date.');
        }
        $v->done();

        $row = ['base_kind' => $baseKind, 'cap_scope' => $capScope, 'status' => $status];
        foreach (['code' => $code, 'name' => $name, 'country' => $country, 'region' => $region, 'tax_year' => $year, 'tax_type' => $type,
            'method' => $method, 'currency' => $currency, 'effective_from' => $from, 'effective_to' => $to, 'source_note' => $note] as $k => $val) {
            if ($val !== null || array_key_exists($k, $in)) {
                $row[$k] = $val;
            }
        }
        foreach (['rate_pct' => ['rate_bp', $rate], 'threshold' => ['threshold_minor', $threshold ?? 0], 'cap' => ['cap_minor', $cap],
            'fixed' => ['fixed_minor', $fixed], 'employer_rate_pct' => ['employer_rate_bp', $erate ?? 0], 'employer_threshold' => ['employer_threshold_minor', $ethr ?? 0]] as $k => [$col, $val]) {
            if (array_key_exists($k, $in) || $cur === null) {
                $row[$col] = $val;
            }
        }
        if ($bracketsJson !== null) {
            $row['brackets_json'] = $bracketsJson;
        }
        if (array_key_exists('verified', $in)) {
            $row['verified'] = $v->bool('verified') ? 1 : 0;
        }
        return $row;
    }

    public static function create(array $in): array
    {
        $row = self::data($in, null);
        $row += ['threshold_minor' => 0, 'employer_rate_bp' => 0, 'employer_threshold_minor' => 0];
        $row['created_at'] = $row['updated_at'] = Db::now();
        $id = Db::insert('tax_rules', $row);
        Audit::log('Tax Configuration Changed', 'tax_rule', $id, null, $row);
        return self::fmt(self::row($id));
    }

    public static function update(int $id, array $in): array
    {
        $cur = self::row($id);
        $row = self::data($in, $cur);
        $row['updated_at'] = Db::now();
        Db::update('tax_rules', $id, $row);
        Audit::log('Tax Configuration Changed', 'tax_rule', $id, $cur, $row);
        return self::fmt(self::row($id));
    }
}

/** Payroll schedules and pay-period generation. */
final class Schedules
{
    public static function fmt(array $r): array
    {
        return [
            'id' => (int) $r['id'], 'name' => $r['name'], 'frequency' => $r['frequency'], 'entity_id' => (int) $r['entity_id'],
            'entity_name' => $r['entity_name'] ?? null, 'country' => $r['country'], 'currency' => $r['currency'], 'anchor_date' => $r['anchor_date'],
            'cutoff_offset_days' => (int) $r['cutoff_offset_days'], 'pay_offset_days' => (int) $r['pay_offset_days'], 'status' => $r['status'],
            'employee_count' => isset($r['employee_count']) ? (int) $r['employee_count'] : null,
        ];
    }

    public static function all(): array
    {
        return array_map([self::class, 'fmt'], Db::all(
            "SELECT s.*, e.name AS entity_name,
                    (SELECT COUNT(*) FROM employees m WHERE m.schedule_id = s.id AND m.employment_status IN ('ACTIVE','ON_LEAVE')) AS employee_count
               FROM payroll_schedules s JOIN legal_entities e ON e.id = s.entity_id ORDER BY s.name"
        ));
    }

    public static function row(int $id): array
    {
        $r = Db::one('SELECT * FROM payroll_schedules WHERE id = ?', [$id]);
        if (!$r) {
            throw new ApiError(404, 'not_found', 'Payroll schedule not found.');
        }
        return $r;
    }

    /** [start, end] of the pay period that contains $date. */
    public static function periodContaining(array $s, string $date): array
    {
        switch ($s['frequency']) {
            case 'monthly':
                return [Dates::som($date), Dates::eom($date)];
            case 'semimonthly':
                $som = Dates::som($date);
                return ((int) substr($date, 8, 2)) <= 15 ? [$som, Dates::add($som, 14)] : [Dates::add($som, 15), Dates::eom($date)];
            case 'weekly':
            case 'biweekly':
                $len = $s['frequency'] === 'weekly' ? 7 : 14;
                $offset = Dates::diff($s['anchor_date'], $date);
                $start = Dates::add($s['anchor_date'], $offset - ((($offset % $len) + $len) % $len));
                return [$start, Dates::add($start, $len - 1)];
        }
        throw new \InvalidArgumentException('Unknown frequency');
    }

    public static function isValidPeriod(array $s, string $start, string $end): bool
    {
        return self::periodContaining($s, $start) === [$start, $end];
    }

    public static function dates(array $s, string $end): array
    {
        return ['cutoff_date' => Dates::add($end, (int) $s['cutoff_offset_days']), 'pay_date' => Dates::add($end, (int) $s['pay_offset_days'])];
    }

    /** Next pay periods to run, skipping periods that already have a payroll. */
    public static function suggestions(array $s, int $count = 4): array
    {
        $today = Dates::today();
        $cur = self::periodContaining($s, $today);
        $begin = self::periodContaining($s, Dates::add($cur[0], -1));
        $last = Db::val("SELECT MAX(period_end) FROM payroll_runs WHERE schedule_id = ? AND status <> 'CANCELLED'", [(int) $s['id']]);
        if ($last && $last >= $begin[1]) {
            $begin = self::periodContaining($s, Dates::add((string) $last, 1));
        }
        $out = [];
        $p = $begin;
        for ($i = 0; $i < $count; $i++) {
            $out[] = ['period_start' => $p[0], 'period_end' => $p[1]] + self::dates($s, $p[1]);
            $p = self::periodContaining($s, Dates::add($p[1], 1));
        }
        return $out;
    }

    private static function data(array $in, ?array $cur): array
    {
        $v = new Validator($in);
        $name = $v->str('name', $cur === null, 100);
        $freq = $v->enum('frequency', Engine::FREQUENCIES, $cur === null);
        $entityId = $v->int('entity_id', $cur === null, 1);
        $entity = $entityId ? Db::one('SELECT * FROM legal_entities WHERE id = ?', [$entityId]) : null;
        if ($entityId && !$entity) {
            $v->fail('entity_id', 'Choose a valid legal entity.');
        }
        $country = $v->enum('country', array_keys(Meta::COUNTRIES), false, $entity['country'] ?? ($cur['country'] ?? null));
        $currency = $v->enum('currency', Meta::currencies(), false, $entity['currency'] ?? ($cur['currency'] ?? null));
        $anchor = $v->date('anchor_date', false);
        $cut = $v->int('cutoff_offset_days', false, -60, 60);
        $pay = $v->int('pay_offset_days', false, -60, 60);
        $status = $v->enum('status', ['active', 'inactive'], false, $cur['status'] ?? 'active');
        $v->done();
        $row = ['status' => $status];
        foreach (['name' => $name, 'frequency' => $freq, 'entity_id' => $entityId, 'country' => $country, 'currency' => $currency, 'anchor_date' => $anchor] as $k => $val) {
            if ($val !== null) {
                $row[$k] = $val;
            }
        }
        $row['cutoff_offset_days'] = $cut ?? ($cur['cutoff_offset_days'] ?? -10);
        $row['pay_offset_days'] = $pay ?? ($cur['pay_offset_days'] ?? 0);
        if ($cur === null && empty($row['anchor_date'])) {
            $row['anchor_date'] = '2026-01-05'; // a Monday; only used by weekly / biweekly schedules
        }
        return $row;
    }

    public static function create(array $in): array
    {
        $row = self::data($in, null);
        $row['created_at'] = $row['updated_at'] = Db::now();
        $id = Db::insert('payroll_schedules', $row);
        Audit::log('Payroll Schedule Created', 'schedule', $id, null, $row);
        return self::fmt(self::row($id));
    }

    public static function update(int $id, array $in): array
    {
        $cur = self::row($id);
        $row = self::data($in, $cur);
        $used = (int) Db::val("SELECT COUNT(*) FROM payroll_runs WHERE schedule_id = ? AND status <> 'CANCELLED'", [$id]);
        if ($used > 0 && ((isset($row['frequency']) && $row['frequency'] !== $cur['frequency']) || (isset($row['anchor_date']) && $row['anchor_date'] !== $cur['anchor_date']))) {
            throw new ApiError(409, 'in_use', 'Frequency and anchor date cannot change once payroll has been run on this schedule. Create a new schedule instead.');
        }
        $row['updated_at'] = Db::now();
        Db::update('payroll_schedules', $id, $row);
        Audit::log('Payroll Schedule Updated', 'schedule', $id, $cur, $row);
        return self::fmt(self::row($id));
    }
}

/** User accounts and roles (ADMIN only). */
final class UserAdmin
{
    public static function all(): array
    {
        return array_map(fn($r) => [
            'id' => (int) $r['id'], 'email' => $r['email'], 'name' => $r['name'], 'role' => $r['role'],
            'employee_id' => $r['employee_id'] !== null ? (int) $r['employee_id'] : null, 'employee_name' => $r['employee_name'],
            'active' => (int) $r['active'] === 1, 'last_login_at' => $r['last_login_at'],
        ], Db::all("SELECT u.*, TRIM(e.first_name || ' ' || e.last_name) AS employee_name FROM users u LEFT JOIN employees e ON e.id = u.employee_id ORDER BY u.name"));
    }

    private static function linkOk(?int $employeeId, Validator $v): void
    {
        if ($employeeId !== null && !Db::one('SELECT id FROM employees WHERE id = ?', [$employeeId])) {
            $v->fail('employee_id', 'Choose a valid employee.');
        }
    }

    public static function create(array $in): array
    {
        $v = new Validator($in);
        $email = $v->email('email');
        $name = $v->str('name', true, 100);
        $role = $v->enum('role', Perms::ROLES);
        $pw = $v->str('password', true, 200);
        $emp = $v->int('employee_id', false, 1);
        self::linkOk($emp, $v);
        if ($role === 'EMPLOYEE' && $emp === null) {
            $v->fail('employee_id', 'An employee login must be linked to an employee record.');
        }
        $v->done();
        $id = Auth::createUser($email, $name, $pw, $role, $emp);
        Audit::log('User Created', 'user', $id, null, ['email' => $email, 'role' => $role, 'employee_id' => $emp]);
        return ['id' => $id];
    }

    public static function update(int $id, array $in): array
    {
        $cur = Db::one('SELECT * FROM users WHERE id = ?', [$id]);
        if (!$cur) {
            throw new ApiError(404, 'not_found', 'User not found.');
        }
        $v = new Validator($in);
        $name = $v->str('name', false, 100);
        $role = $v->enum('role', Perms::ROLES, false);
        $active = array_key_exists('active', $in) ? $v->bool('active', true) : null;
        $emp = $v->int('employee_id', false, 1);
        self::linkOk($emp, $v);
        $pw = $v->str('password', false, 200);
        if ($pw !== null && !Auth::passwordOk($pw)) {
            $v->fail('password', 'Use at least 10 characters including a letter and a number.');
        }
        $v->done();

        $me = Auth::requireUser();
        if ($id === $me['id'] && (($role !== null && $role !== $cur['role']) || $active === false)) {
            throw new ApiError(409, 'self_change', 'You cannot change your own role or deactivate your own account.');
        }
        $newRole = $role ?? $cur['role'];
        $newActive = $active ?? ((int) $cur['active'] === 1);
        if ($cur['role'] === 'ADMIN' && (int) $cur['active'] === 1 && ($newRole !== 'ADMIN' || !$newActive)) {
            $admins = (int) Db::val("SELECT COUNT(*) FROM users WHERE role = 'ADMIN' AND active = 1");
            if ($admins <= 1) {
                throw new ApiError(409, 'last_admin', 'There must be at least one active administrator.');
            }
        }
        $row = [];
        if ($name !== null) {
            $row['name'] = $name;
        }
        $row['role'] = $newRole;
        $row['active'] = $newActive ? 1 : 0;
        if (array_key_exists('employee_id', $in)) {
            $row['employee_id'] = $emp;
        }
        if ($pw !== null) {
            $row['password_hash'] = password_hash($pw, PASSWORD_DEFAULT);
        }
        Db::update('users', $id, $row);
        if ($pw !== null || !$newActive || $newRole !== $cur['role']) {
            Db::run('DELETE FROM sessions WHERE user_id = ?', [$id]); // force re-login after credential / role changes
        }
        Audit::log('User Updated', 'user', $id, ['role' => $cur['role'], 'active' => (int) $cur['active']], ['role' => $newRole, 'active' => $newActive ? 1 : 0, 'password_reset' => $pw !== null]);
        return ['id' => $id];
    }

    /**
     * Removes a user account entirely (sessions and pending reset links go with it, automatically). Refused when
     * the user has created payroll records - deleting them would leave compensation history with no author - or
     * when it would leave the company with no administrator. Deactivating (the "Account is active" checkbox)
     * remains the right choice for anyone who has actually used the system.
     */
    public static function delete(int $id, int $actingUserId): void
    {
        $cur = Db::one('SELECT * FROM users WHERE id = ?', [$id]);
        if (!$cur) {
            throw new ApiError(404, 'not_found', 'User not found.');
        }
        if ($id === $actingUserId) {
            throw new ApiError(409, 'self_change', 'You cannot delete your own account.');
        }
        if ($cur['role'] === 'ADMIN' && (int) $cur['active'] === 1) {
            $admins = (int) Db::val("SELECT COUNT(*) FROM users WHERE role = 'ADMIN' AND active = 1");
            if ($admins <= 1) {
                throw new ApiError(409, 'last_admin', 'There must be at least one active administrator.');
            }
        }
        $used = (int) Db::val('SELECT COUNT(*) FROM compensation WHERE created_by = ?', [$id]);
        if ($used > 0) {
            throw new ApiError(409, 'in_use', 'This user has created payroll records and cannot be deleted. Deactivate the account instead, to keep that history intact.');
        }
        Db::run('DELETE FROM users WHERE id = ?', [$id]);
        Audit::log('User Deleted', 'user', $id, ['email' => $cur['email'], 'role' => $cur['role']], null);
    }
}

/** Default reference data. All tax figures are illustrative samples and are flagged as NOT verified. */
final class ReferenceData
{
    public static function seedComponents(): void
    {
        if ((int) Db::val('SELECT COUNT(*) FROM components') > 0) {
            return;
        }
        $now = Db::now();
        $rows = [
            // code, name, kind, category, calc, basis, taxable, pre_tax, rate_bp, employer_rate_bp, frequency, provider
            ['HOUSING', 'Housing allowance', 'earning', 'allowance', 'fixed', 'base', 1, 0, null, null, 'monthly', null],
            ['TRANSPORT', 'Transport allowance', 'earning', 'allowance', 'fixed', 'base', 1, 0, null, null, 'monthly', null],
            ['MEAL', 'Meal allowance', 'earning', 'allowance', 'fixed', 'base', 1, 0, null, null, 'monthly', null],
            ['INTERNET', 'Internet allowance', 'earning', 'allowance', 'fixed', 'base', 1, 0, null, null, 'monthly', null],
            ['TRAVEL', 'Travel allowance', 'earning', 'allowance', 'fixed', 'base', 1, 0, null, null, 'monthly', null],
            ['OTHER_ALLOW', 'Other allowance', 'earning', 'allowance', 'fixed', 'base', 1, 0, null, null, 'monthly', null],
            ['BONUS', 'Bonus', 'earning', 'bonus', 'fixed', 'base', 1, 0, null, null, 'one_time', null],
            ['COMMISSION', 'Commission', 'earning', 'commission', 'percent', 'base', 1, 0, 500, null, 'per_period', null],
            ['OTHER_EARN', 'Other earnings', 'earning', 'other', 'fixed', 'base', 1, 0, null, null, 'monthly', null],
            ['RETIREMENT', 'Retirement contribution', 'deduction', 'retirement', 'percent', 'gross', 0, 1, 500, null, 'per_period', null],
            ['INSURANCE', 'Insurance premium', 'deduction', 'insurance', 'fixed', 'base', 0, 0, null, null, 'monthly', null],
            ['LOAN', 'Loan repayment', 'deduction', 'loan', 'fixed', 'base', 0, 0, null, null, 'monthly', null],
            ['OTHER_DED', 'Other deduction', 'deduction', 'other', 'fixed', 'base', 0, 0, null, null, 'monthly', null],
            ['BEN_HEALTH', 'Health insurance', 'benefit', 'health', 'fixed', 'base', 0, 1, null, null, 'monthly', 'Health provider'],
            ['BEN_DENTAL', 'Dental', 'benefit', 'dental', 'fixed', 'base', 0, 1, null, null, 'monthly', 'Dental provider'],
            ['BEN_VISION', 'Vision', 'benefit', 'vision', 'fixed', 'base', 0, 1, null, null, 'monthly', 'Vision provider'],
            ['BEN_RETIRE', 'Retirement plan (employer match)', 'benefit', 'retirement', 'percent', 'gross', 0, 1, 300, 300, 'per_period', 'Retirement plan'],
            ['BEN_LIFE', 'Life insurance', 'benefit', 'life', 'fixed', 'base', 0, 0, null, null, 'monthly', 'Life insurer'],
            ['BEN_OTHER', 'Other benefit', 'benefit', 'other', 'fixed', 'base', 0, 0, null, null, 'monthly', null],
        ];
        foreach ($rows as [$code, $name, $kind, $cat, $calc, $basis, $taxable, $pre, $rate, $erate, $freq, $prov]) {
            Db::insert('components', [
                'code' => $code, 'name' => $name, 'kind' => $kind, 'category' => $cat, 'calc' => $calc, 'basis' => $basis,
                'taxable' => $taxable, 'pre_tax' => $pre, 'default_rate_bp' => $rate, 'default_employer_rate_bp' => $erate,
                'frequency' => $freq, 'provider' => $prov, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public static function seedTaxRules(): void
    {
        if ((int) Db::val('SELECT COUNT(*) FROM tax_rules') > 0) {
            return;
        }
        $now = Db::now();
        $note = 'Illustrative sample rule for testing. Not verified against current law - review before use.';
        $add = function (array $r) use ($now, $note): void {
            Db::insert('tax_rules', $r + [
                'region' => null, 'tax_year' => null, 'base_kind' => 'taxable_income', 'rate_bp' => null, 'threshold_minor' => 0, 'cap_minor' => null,
                'brackets_json' => null, 'fixed_minor' => null, 'employer_rate_bp' => 0, 'employer_threshold_minor' => 0, 'effective_from' => '2024-01-01',
                'effective_to' => null, 'verified' => 0, 'source_note' => $note, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
            ]);
        };
        $br = fn(array $bands) => json_encode(array_map(fn($b) => ['up_to_minor' => $b[0], 'rate_bp' => $b[1]], $bands));

        // United States
        $add(['code' => 'US_FIT', 'name' => 'Federal income tax (sample)', 'country' => 'US', 'tax_type' => 'income_tax', 'method' => 'bracket', 'currency' => 'USD',
            'threshold_minor' => 1460000, 'brackets_json' => $br([[1160000, 1000], [4715000, 1200], [10052500, 2200], [19195000, 2400], [24372500, 3200], [60935000, 3500], [null, 3700]])]);
        $add(['code' => 'US_SS', 'name' => 'Social Security (sample)', 'country' => 'US', 'tax_type' => 'social_security', 'method' => 'capped', 'currency' => 'USD',
            'base_kind' => 'gross_taxable', 'rate_bp' => 620, 'cap_minor' => 16860000, 'employer_rate_bp' => 620]);
        $add(['code' => 'US_MED', 'name' => 'Medicare (sample)', 'country' => 'US', 'tax_type' => 'medicare', 'method' => 'flat', 'currency' => 'USD',
            'base_kind' => 'gross_taxable', 'rate_bp' => 145, 'employer_rate_bp' => 145]);
        $add(['code' => 'US_CA_SIT', 'name' => 'California income tax (sample flat rate)', 'country' => 'US', 'region' => 'CA', 'tax_type' => 'local_tax', 'method' => 'flat',
            'currency' => 'USD', 'rate_bp' => 400]);

        // United Kingdom
        $add(['code' => 'GB_PAYE', 'name' => 'PAYE income tax (sample)', 'country' => 'GB', 'tax_type' => 'income_tax', 'method' => 'bracket', 'currency' => 'GBP',
            'threshold_minor' => 1257000, 'brackets_json' => $br([[3770000, 2000], [11257000, 4000], [null, 4500]])]);
        $add(['code' => 'GB_NI', 'name' => 'National Insurance (sample)', 'country' => 'GB', 'tax_type' => 'social_security', 'method' => 'bracket', 'currency' => 'GBP',
            'base_kind' => 'gross_taxable', 'brackets_json' => $br([[1257000, 0], [5027000, 800], [null, 200]]), 'employer_rate_bp' => 1500, 'employer_threshold_minor' => 500000]);

        // Germany
        $add(['code' => 'DE_LST', 'name' => 'Income tax (sample bands)', 'country' => 'DE', 'tax_type' => 'income_tax', 'method' => 'bracket', 'currency' => 'EUR',
            'threshold_minor' => 1200000, 'brackets_json' => $br([[1500000, 1400], [6000000, 2400], [null, 4200]])]);
        $add(['code' => 'DE_PEN', 'name' => 'Pension insurance (sample)', 'country' => 'DE', 'tax_type' => 'social_security', 'method' => 'capped', 'currency' => 'EUR',
            'base_kind' => 'gross_taxable', 'cap_scope' => 'period', 'rate_bp' => 930, 'cap_minor' => 9660000, 'employer_rate_bp' => 930]);
        $add(['code' => 'DE_HLT', 'name' => 'Health insurance (sample)', 'country' => 'DE', 'tax_type' => 'social_security', 'method' => 'capped', 'currency' => 'EUR',
            'base_kind' => 'gross_taxable', 'cap_scope' => 'period', 'rate_bp' => 730, 'cap_minor' => 6210000, 'employer_rate_bp' => 730]);

        // India
        $add(['code' => 'IN_TDS', 'name' => 'Income tax TDS (sample slabs)', 'country' => 'IN', 'tax_type' => 'income_tax', 'method' => 'bracket', 'currency' => 'INR',
            'threshold_minor' => 5000000, 'brackets_json' => $br([[30000000, 0], [70000000, 500], [100000000, 1000], [120000000, 1500], [150000000, 2000], [null, 3000]])]);
        $add(['code' => 'IN_PF', 'name' => 'Provident fund (sample)', 'country' => 'IN', 'tax_type' => 'provident_fund', 'method' => 'capped', 'currency' => 'INR',
            'base_kind' => 'gross_taxable', 'cap_scope' => 'period', 'rate_bp' => 1200, 'cap_minor' => 18000000, 'employer_rate_bp' => 1200]);
        $add(['code' => 'IN_PT', 'name' => 'Professional tax (sample)', 'country' => 'IN', 'tax_type' => 'local_tax', 'method' => 'fixed', 'currency' => 'INR',
            'fixed_minor' => 240000]);
    }
}
