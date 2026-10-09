<?php
declare(strict_types=1);

namespace P360;

/**
 * Payroll calculation engine.
 *
 * Pure and deterministic: the same inputs always give the same outputs, nothing here touches the database or the clock.
 * All money is integer minor units. Every line is rounded half-away-from-zero, and totals are sums of lines,
 * so a payslip always reconciles exactly.
 *
 * Pipeline: eligibility -> earnings -> pre-tax deductions/benefits -> taxes (jurisdiction rules)
 *           -> employer contributions -> gross-to-net -> exceptions.
 */
final class Engine
{
    public const FREQUENCIES = ['weekly', 'biweekly', 'semimonthly', 'monthly'];
    public const HOURS_PER_YEAR = 2080;

    public static function periodsPerYear(string $frequency): int
    {
        return match ($frequency) {
            'weekly' => 52,
            'biweekly' => 26,
            'semimonthly' => 24,
            'monthly' => 12,
            default => throw new \InvalidArgumentException("Unknown payroll frequency: {$frequency}"),
        };
    }

    /** Annual equivalent of a compensation amount. */
    public static function annualize(int $minor, string $basis): int
    {
        return match ($basis) {
            'annual' => $minor,
            'monthly' => $minor * 12,
            'semimonthly' => $minor * 24,
            'biweekly' => $minor * 26,
            'weekly' => $minor * 52,
            'hourly' => $minor * self::HOURS_PER_YEAR,
            default => throw new \InvalidArgumentException("Unknown pay basis: {$basis}"),
        };
    }

    /** How many times per year a component amount recurs (null = one-off). */
    public static function annualFactor(string $frequency, int $periodsPerYear): ?int
    {
        return match ($frequency) {
            'annual' => 1,
            'monthly' => 12,
            'semimonthly' => 24,
            'biweekly' => 26,
            'weekly' => 52,
            'per_period' => $periodsPerYear,
            'one_time' => null,
            default => throw new \InvalidArgumentException("Unknown component frequency: {$frequency}"),
        };
    }

    /** Fraction of the period the employee is on payroll, as [days, totalDays]. */
    public static function proration(array $emp, string $ps, string $pe): array
    {
        $total = Dates::diff($ps, $pe) + 1;
        $start = max($ps, (string) $emp['joining_date']);
        $end = $pe;
        if (!empty($emp['termination_date']) && $emp['termination_date'] < $pe) {
            $end = (string) $emp['termination_date'];
        }
        if ($start > $end) {
            return [0, $total];
        }
        return [Dates::diff($start, $end) + 1, $total];
    }

    private static function effective(array $a, string $ps, string $pe): bool
    {
        if (isset($a['status']) && $a['status'] !== 'active') {
            return false;
        }
        if ($a['effective_from'] > $pe) {
            return false;
        }
        return empty($a['effective_to']) || $a['effective_to'] >= $ps;
    }

    private static function money(int $minor, string $cur): string
    {
        return $cur . ' ' . number_format((float) Money::fmt($minor, $cur), Money::exp($cur), '.', ',');
    }

    private static function bpText(int $bp): string
    {
        $s = rtrim(rtrim(number_format($bp / 100, 2, '.', ''), '0'), '.');
        return $s . '%';
    }

    /**
     * @param array $in employee, run, compensation, components, adjustments, tax_rules, ytd, duplicate_run
     * @return array{lines: array, totals: array, exceptions: array, meta: array}
     */
    public static function calculate(array $in): array
    {
        $emp = $in['employee'];
        $run = $in['run'];
        $cur = (string) $run['currency'];
        $ps = (string) $run['period_start'];
        $pe = (string) $run['period_end'];
        $n = self::periodsPerYear((string) $run['frequency']);
        $comp = $in['compensation'] ?? null;
        $ex = [];
        $lines = [];
        $err = function (string $code, string $msg, ?string $field = null) use (&$ex): void {
            $ex[] = ['code' => $code, 'severity' => 'error', 'message' => $msg, 'field' => $field];
        };
        $warn = function (string $code, string $msg, ?string $field = null) use (&$ex): void {
            $ex[] = ['code' => $code, 'severity' => 'warning', 'message' => $msg, 'field' => $field];
        };

        // ---------------------------------------------------------------- eligibility
        $status = (string) $emp['employment_status'];
        if (!in_array($status, ['ACTIVE', 'ON_LEAVE', 'TERMINATED'], true)) {
            $err('not_eligible', 'Employment status is ' . $status . '. Only active, on-leave or recently terminated employees can be paid.', 'employment_status');
        }
        if ((string) $emp['joining_date'] > $pe) {
            $err('not_eligible', 'Employee joins on ' . $emp['joining_date'] . ', after this pay period ends.', 'joining_date');
        }
        if (!empty($emp['termination_date']) && $emp['termination_date'] < $ps) {
            $err('terminated', 'Employee was terminated on ' . $emp['termination_date'] . ', before this pay period started.', 'termination_date');
        } elseif (!empty($emp['termination_date']) && $emp['termination_date'] <= $pe) {
            $warn('final_pay', 'Final pay: prorated to the termination date (' . $emp['termination_date'] . ').', 'termination_date');
        } elseif ($status === 'TERMINATED') {
            $err('terminated', 'Employee is terminated but has no termination date.', 'termination_date');
        }

        // ---------------------------------------------------------------- required payroll data
        if (empty($emp['payroll_frequency'])) {
            $err('missing_frequency', 'Payroll frequency is not set.', 'payroll_frequency');
        } elseif ($emp['payroll_frequency'] !== $run['frequency']) {
            $err('frequency_mismatch', 'Employee is paid ' . $emp['payroll_frequency'] . ' but this payroll is ' . $run['frequency'] . '.', 'payroll_frequency');
        }
        if (empty($emp['currency'])) {
            $err('missing_currency', 'Currency is not set.', 'currency');
        } elseif ($emp['currency'] !== $cur) {
            $err('currency_mismatch', 'Employee is paid in ' . $emp['currency'] . ' but this payroll is in ' . $cur . '.', 'currency');
        }
        if (empty($emp['tax_id_present'])) {
            $err('missing_tax_id', 'Tax ID is missing.', 'tax_id');
        }
        if (empty($emp['bank_present'])) {
            $err('missing_bank', 'Bank / payment details are missing.', 'bank_account');
        }
        if (!empty($in['duplicate_run'])) {
            $err('duplicate_payroll', 'Employee is already in another payroll for this period: ' . $in['duplicate_run'] . '.', null);
        }
        $rules = $in['tax_rules'] ?? [];
        if (!$rules) {
            $where = (string) $emp['country'] . (!empty($emp['state_region']) ? ' / ' . $emp['state_region'] : '');
            $err('missing_tax_rules', 'No tax rules are configured for ' . $where . ' for this period.', 'country');
        }

        $zero = static fn() => [
            'lines' => [], 'exceptions' => $ex,
            'totals' => ['base_minor' => 0, 'earnings_minor' => 0, 'allowances_minor' => 0, 'gross_minor' => 0, 'taxable_gross_minor' => 0,
                'taxes_minor' => 0, 'benefits_minor' => 0, 'deductions_minor' => 0, 'net_minor' => 0, 'employer_contrib_minor' => 0, 'employer_cost_minor' => 0],
            'meta' => ['annual_base_minor' => 0, 'proration' => [0, 1]],
        ];

        if (!$comp) {
            $err('missing_salary', 'No compensation is set for this employee.', 'compensation');
            return $zero();
        }
        if ((int) $comp['base_minor'] <= 0) {
            $err('invalid_compensation', 'Compensation must be greater than zero.', 'compensation');
            return $zero();
        }
        if ($comp['currency'] !== $cur) {
            $err('currency_mismatch', 'Compensation is in ' . $comp['currency'] . ' but this payroll is in ' . $cur . '.', 'compensation');
        }

        [$num, $den] = self::proration($emp, $ps, $pe);
        if ($num === 0) {
            return array_replace_recursive($zero(), ['meta' => ['proration' => [0, $den]]]);
        }

        $sort = 0;
        $addLine = function (string $kind, ?string $code, string $name, ?string $category, string $method, int $amount, int $employer = 0, ?int $taxable = null, ?string $note = null) use (&$lines, &$sort): void {
            $lines[] = ['kind' => $kind, 'code' => $code, 'name' => $name, 'category' => $category, 'method' => $method,
                'amount_minor' => $amount, 'employer_minor' => $employer, 'taxable' => $taxable, 'note' => $note, 'sort' => ++$sort];
        };

        // ---------------------------------------------------------------- earnings
        $annual = self::annualize((int) $comp['base_minor'], (string) $comp['pay_basis']);
        $basePeriod = Money::divRound($annual * $num, $n * $den);
        $baseMethod = $comp['pay_basis'] === 'hourly'
            ? self::money((int) $comp['base_minor'], $cur) . '/hr x ' . self::HOURS_PER_YEAR . ' hrs / ' . $n . ' periods'
            : self::money($annual, $cur) . ' per year / ' . $n . ' periods';
        if ($num !== $den) {
            $baseMethod .= ' x ' . $num . '/' . $den . ' days';
        }
        $addLine('earning', 'BASE', 'Base salary', 'base', $baseMethod, $basePeriod, 0, 1);

        foreach ($in['adjustments'] ?? [] as $a) {
            if ($a['kind'] === 'overtime_hours') {
                $hours = (int) $a['hours_hundredths'];
                $mult = (int) ($comp['overtime_multiplier_bp'] ?? 15000);
                $amt = Money::divRound($annual * $hours * $mult, self::HOURS_PER_YEAR * 100 * 10000);
                $addLine('earning', 'OT', 'Overtime', 'overtime', number_format($hours / 100, 2) . ' hrs at ' . rtrim(rtrim(number_format($mult / 10000, 2), '0'), '.') . 'x hourly rate', $amt, 0, 1, (string) $a['reason']);
            }
        }

        foreach ($in['components'] ?? [] as $c) {
            if ($c['kind'] !== 'earning' || !self::effective($c, $ps, $pe)) {
                continue;
            }
            $taxable = (int) ($c['taxable'] ?? 1);
            $factor = self::annualFactor((string) $c['frequency'], $n);
            if ($factor === null) {
                if ($c['effective_from'] < $ps) {
                    continue; // one-time amounts are paid in the period that contains their effective date
                }
                $amt = (int) ($c['amount_minor'] ?? 0);
                $method = 'One-time ' . self::money($amt, $cur);
            } elseif (($c['calc'] ?? 'fixed') === 'percent') {
                $amt = Money::pct($basePeriod, (int) ($c['rate_bp'] ?? 0));
                $method = self::bpText((int) ($c['rate_bp'] ?? 0)) . ' of base pay';
            } else {
                $amt = Money::divRound((int) ($c['amount_minor'] ?? 0) * $factor * $num, $n * $den);
                $method = self::money((int) ($c['amount_minor'] ?? 0), $cur) . ' ' . $c['frequency'];
                if ($num !== $den) {
                    $method .= ' x ' . $num . '/' . $den . ' days';
                }
            }
            $addLine('earning', (string) $c['code'], (string) $c['name'], (string) ($c['category'] ?: 'other'), $method, $amt, 0, $taxable, $c['description'] ?? null);
        }

        foreach ($in['adjustments'] ?? [] as $a) {
            if ($a['kind'] === 'earning') {
                $addLine('earning', 'ADJ', (string) $a['name'], 'adjustment', 'One-off adjustment', (int) $a['amount_minor'], 0, (int) ($a['taxable'] ?? 1), (string) $a['reason']);
            }
        }

        $gross = 0;
        $taxableGross = 0;
        $base = 0;
        $allowances = 0;
        foreach ($lines as $l) {
            if ($l['kind'] !== 'earning') {
                continue;
            }
            $gross += $l['amount_minor'];
            if ((int) $l['taxable'] === 1) {
                $taxableGross += $l['amount_minor'];
            }
            if ($l['category'] === 'base') {
                $base += $l['amount_minor'];
            } elseif ($l['category'] === 'allowance') {
                $allowances += $l['amount_minor'];
            }
        }

        // ---------------------------------------------------------------- deductions and benefits
        $basisAmount = fn(string $basis): int => match ($basis) {
            'gross' => $gross,
            'taxable_gross' => $taxableGross,
            default => $basePeriod,
        };
        $preTax = 0;
        $benefitsTotal = 0;
        $deductionsTotal = 0;
        $employerBenefits = 0;

        foreach ($in['components'] ?? [] as $c) {
            if (!in_array($c['kind'], ['deduction', 'benefit'], true) || !self::effective($c, $ps, $pe)) {
                continue;
            }
            $factor = self::annualFactor((string) $c['frequency'], $n);
            if ($factor === null && $c['effective_from'] < $ps) {
                continue;
            }
            $percent = ($c['calc'] ?? 'fixed') === 'percent';
            $basis = $basisAmount((string) ($c['basis'] ?? 'base'));

            $employee = 0;
            $employer = 0;
            $hasConfig = false;
            if ($percent) {
                if ($c['rate_bp'] !== null) {
                    $employee = Money::pct($basis, (int) $c['rate_bp']);
                    $hasConfig = true;
                }
                if ($c['employer_rate_bp'] !== null) {
                    $employer = Money::pct($basis, (int) $c['employer_rate_bp']);
                    $hasConfig = true;
                }
                $method = ($c['rate_bp'] !== null ? 'Employee ' . self::bpText((int) $c['rate_bp']) : '') .
                    ($c['employer_rate_bp'] !== null ? ' Employer ' . self::bpText((int) $c['employer_rate_bp']) : '') . ' of ' . str_replace('_', ' ', (string) $c['basis']);
            } else {
                $per = fn(int $amt): int => $factor === null ? $amt : Money::divRound($amt * $factor, $n);
                if ($c['amount_minor'] !== null) {
                    $employee = $per((int) $c['amount_minor']);
                    $hasConfig = true;
                }
                if ($c['employer_amount_minor'] !== null) {
                    $employer = $per((int) $c['employer_amount_minor']);
                    $hasConfig = true;
                }
                $method = ($c['amount_minor'] !== null ? 'Employee ' . self::money((int) $c['amount_minor'], $cur) : '') .
                    ($c['employer_amount_minor'] !== null ? ' Employer ' . self::money((int) $c['employer_amount_minor'], $cur) : '') . ' ' . $c['frequency'];
            }
            if ($c['kind'] === 'benefit' && !$hasConfig) {
                $err('missing_benefit_config', 'Benefit "' . $c['name'] . '" has no contribution amount configured.', 'benefits');
                continue;
            }
            $method = trim($method);
            if ((int) ($c['pre_tax'] ?? 0) === 1) {
                $preTax += $employee;
                $method .= ' (pre-tax)';
            }
            if ($c['kind'] === 'benefit') {
                $benefitsTotal += $employee;
                $employerBenefits += $employer;
                $addLine('benefit', (string) $c['code'], (string) $c['name'], (string) ($c['category'] ?: 'benefit'), $method, $employee, $employer, null, $c['provider'] ?? null);
            } else {
                $deductionsTotal += $employee;
                $addLine('deduction', (string) $c['code'], (string) $c['name'], (string) ($c['category'] ?: 'other'), $method, $employee, 0, null, $c['description'] ?? null);
            }
        }
        foreach ($in['adjustments'] ?? [] as $a) {
            if ($a['kind'] === 'deduction') {
                $deductionsTotal += (int) $a['amount_minor'];
                $addLine('deduction', 'ADJ', (string) $a['name'], 'adjustment', 'One-off adjustment', (int) $a['amount_minor'], 0, null, (string) $a['reason']);
            }
        }

        // ---------------------------------------------------------------- taxes (jurisdiction rules)
        $taxesTotal = 0;
        $employerTaxes = 0;
        $incomeWage = max(0, $taxableGross - $preTax);
        $ytdWages = (int) ($in['ytd']['taxable_gross_minor'] ?? 0);
        $perAnnual = fn(int $annualAmt): int => Money::divRound($annualAmt * $num, $n * $den);
        // Wages still under a contribution ceiling: 'ytd' = annual ceiling used up by year-to-date wages, 'period' = ceiling applies to each pay period.
        $capRoom = function (array $r) use ($perAnnual, $ytdWages): ?int {
            if ($r['cap_minor'] === null) {
                return null;
            }
            return ($r['cap_scope'] ?? 'ytd') === 'period' ? $perAnnual((int) $r['cap_minor']) : max(0, (int) $r['cap_minor'] - $ytdWages);
        };

        foreach ($rules as $r) {
            $wage = ($r['base_kind'] ?? 'taxable_income') === 'gross_taxable' ? $taxableGross : $incomeWage;
            $tax = 0;
            $employerTax = 0;
            $method = '';
            switch ($r['method']) {
                case 'fixed':
                    $tax = $perAnnual((int) ($r['fixed_minor'] ?? 0));
                    $method = 'Fixed ' . self::money((int) ($r['fixed_minor'] ?? 0), $cur) . ' per year';
                    break;
                case 'flat':
                    $tax = Money::pct(max(0, $wage - $perAnnual((int) $r['threshold_minor'])), (int) $r['rate_bp']);
                    $method = self::bpText((int) $r['rate_bp']) . ' flat';
                    break;
                case 'capped':
                    $room = $capRoom($r);
                    $allowed = $room === null ? $wage : min($wage, $room);
                    $tax = Money::pct(max(0, $allowed - $perAnnual((int) $r['threshold_minor'])), (int) $r['rate_bp']);
                    $method = self::bpText((int) $r['rate_bp']) . ' up to ' . ($r['cap_minor'] !== null
                        ? self::money((int) $r['cap_minor'], $cur) . ' a year' . (($r['cap_scope'] ?? 'ytd') === 'period' ? ' (ceiling applied each period)' : ' (year-to-date ceiling)')
                        : 'no ceiling');
                    break;
                case 'bracket':
                    $annualized = Money::divRound($wage * $n * $den, $num);
                    $taxable = max(0, $annualized - (int) $r['threshold_minor']);
                    $annualTax = 0;
                    $prev = 0;
                    $brackets = json_decode((string) $r['brackets_json'], true) ?: [];
                    foreach ($brackets as $b) {
                        $limit = $b['up_to_minor'] ?? null;
                        $top = $limit === null ? $taxable : min($taxable, (int) $limit);
                        if ($top > $prev) {
                            $annualTax += Money::pct($top - $prev, (int) $b['rate_bp']);
                        }
                        if ($limit === null || $taxable <= (int) $limit) {
                            break;
                        }
                        $prev = (int) $limit;
                    }
                    $tax = $perAnnual($annualTax);
                    $method = 'Progressive bands on annualised taxable income';
                    break;
                default:
                    throw new \InvalidArgumentException('Unknown tax method: ' . $r['method']);
            }
            if ((int) $r['employer_rate_bp'] > 0) {
                $eWage = max(0, $wage - $perAnnual((int) $r['employer_threshold_minor']));
                if ($r['method'] === 'capped' && $r['cap_minor'] !== null) {
                    $eWage = min($eWage, (int) $capRoom($r));
                }
                $employerTax = Money::pct($eWage, (int) $r['employer_rate_bp']);
            }
            $taxesTotal += $tax;
            $employerTaxes += $employerTax;
            $note = ((int) ($r['verified'] ?? 0) === 1) ? null : 'Sample rule - not verified';
            $addLine('tax', (string) $r['code'], (string) $r['name'], (string) $r['tax_type'], $method, $tax, $employerTax, null, $note);
        }

        // ---------------------------------------------------------------- gross to net
        $net = $gross - $taxesTotal - $benefitsTotal - $deductionsTotal;
        $employerContrib = $employerTaxes + $employerBenefits;
        if ($net < 0) {
            $err('negative_net', 'Deductions and taxes exceed gross pay, so net pay would be negative (' . self::money($net, $cur) . ').', null);
        }

        return [
            'lines' => $lines,
            'exceptions' => $ex,
            'totals' => [
                'base_minor' => $base,
                'earnings_minor' => $gross - $base - $allowances,
                'allowances_minor' => $allowances,
                'gross_minor' => $gross,
                'taxable_gross_minor' => $taxableGross,
                'taxes_minor' => $taxesTotal,
                'benefits_minor' => $benefitsTotal,
                'deductions_minor' => $deductionsTotal,
                'net_minor' => $net,
                'employer_contrib_minor' => $employerContrib,
                'employer_cost_minor' => $gross + $employerContrib,
            ],
            'meta' => ['annual_base_minor' => $annual, 'proration' => [$num, $den], 'periods_per_year' => $n],
        ];
    }
}
