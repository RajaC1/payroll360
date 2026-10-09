<?php
declare(strict_types=1);

namespace P360;

final class Router
{
    /** @var array<int,array{0:string,1:string,2:callable}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $regex = preg_replace_callback('/\{(\w+)\}/', fn($m) => in_array($m[1], ['id', 'eid', 'adj'], true) ? '(?P<' . $m[1] . '>\d+)' : '(?P<' . $m[1] . '>[a-z_]+)', $pattern);
        $this->routes[] = [$method, '#^' . $regex . '$#', $handler];
    }

    public function dispatch(Req $req): never
    {
        $pathMatched = false;
        foreach ($this->routes as [$method, $regex, $handler]) {
            if (preg_match($regex, $req->path, $m)) {
                $pathMatched = true;
                if ($method !== $req->method) {
                    continue;
                }
                $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
                Http::json(200, $handler($req, $params));
            }
        }
        if ($pathMatched) {
            throw new ApiError(405, 'method_not_allowed', 'That method is not allowed here.');
        }
        throw new ApiError(404, 'not_found', 'Unknown endpoint.');
    }
}

final class Routes
{
    private static function ownEmployeeId(): int
    {
        $u = Auth::requireUser();
        if ($u['employee_id'] === null) {
            throw new ApiError(404, 'no_profile', 'No employee profile is linked to your login.');
        }
        return (int) $u['employee_id'];
    }

    private static function userPayload(array $u): array
    {
        return ['user' => $u, 'permissions' => Auth::permissions($u), 'mail' => null];
    }

    /** Employee-scoped read guard. Returns the employee row or throws 404 / 403. */
    private static function employeeFor(int $id, string $need): array
    {
        $e = Employees::authorized($id);
        $level = Employees::level($e);
        if ($need === 'compensation' && !Auth::can('compensation.read') && $level !== 'self') {
            throw new ApiError(403, 'forbidden', 'You do not have permission to do this.');
        }
        if ($need === 'assignments' && !in_array($level, ['full', 'limited', 'self'], true)) {
            throw new ApiError(403, 'forbidden', 'You do not have permission to do this.');
        }
        return $e;
    }

    private static function sendPayslip(array $ps, string $to, bool $copyEntity): array
    {
        $doc = json_decode((string) $ps['doc_json'], true);
        $run = Db::one('SELECT entity_id FROM payroll_runs WHERE id = ?', [(int) $ps['run_id']]);
        $entity = Entities::row((int) $run['entity_id']);
        $pdf = PayslipPdf::render($doc);
        $name = 'Payslip_' . ($doc['employee']['employee_no'] ?: $ps['id']) . '_' . $ps['period_end'] . '.pdf';
        $method = Mailer::send(
            $to,
            $copyEntity ? ($entity['cc'] ?: null) : null,
            'Your payslip for ' . $doc['period']['start'] . ' to ' . $doc['period']['end'] . ' - ' . $doc['employer']['name'],
            Mailer::payslipHtml($doc),
            $entity['sender_name'] ?: $doc['employer']['name'],
            $entity['reply_to'] ?: $entity['sender_email'],
            $name,
            $pdf
        );
        Payslips::recordSent((int) $ps['id'], $to);
        return ['sent' => true, 'to' => $to, 'method' => $method, 'message' => 'Payslip ' . $ps['number'] . ' was sent to ' . $to . '. ' . $method . '.'];
    }

    public static function register(Router $r): void
    {
        // ------------------------------------------------------------------ public
        // multitenant tells the login screen whether "create an administrator account" (self-service sign-up) is offered.
        $r->add('GET', '/health', fn() => ['ok' => true, 'setup_required' => Auth::userCount() === 0, 'multitenant' => ControlDb::enabled(), 'version' => '2.0.0']);

        $r->add('POST', '/setup', function (Req $req) {
            if (Auth::userCount() > 0) {
                throw new ApiError(403, 'already_setup', 'Setup has already been completed.');
            }
            $b = $req->json();
            $v = new Validator($b);
            $email = $v->email('email');
            $name = $v->str('name', true, 100);
            $pw = $v->str('password', true, 200);
            if ($pw !== null && !Auth::passwordOk($pw)) {
                $v->fail('password', 'Use at least 10 characters including a letter and a number.');
            }
            $company = $v->str('company_name', false, 120);
            $country = $v->enum('country', array_keys(Meta::COUNTRIES), false);
            $v->done();
            Db::tx(function () use ($email, $name, $pw, $company, $country, $b) {
                $id = Auth::createUser($email, $name, $pw, 'ADMIN');
                Auth::actAs(['id' => $id, 'email' => $email, 'name' => $name, 'role' => 'ADMIN', 'employee_id' => null, 'active' => true]);
                ReferenceData::seedComponents();
                if (!empty($b['load_sample_tax_rules'])) {
                    ReferenceData::seedTaxRules();
                }
                if ($company && $country) {
                    Entities::create(['name' => $company, 'legal_name' => $company, 'country' => $country, 'currency' => Meta::COUNTRIES[$country][1]]);
                }
                Audit::log('System Setup Completed', 'system', null, null, ['admin' => $email]);
            });
            [$token, $user] = Auth::login($email, $pw, $req->ip, (string) $req->header('user-agent'));
            return ['token' => $token] + self::userPayload($user);
        });

        // ------------------------------------------------------------------ self-service sign-up (multi-tenant mode)
        // Step 1: holds the sign-up and emails a confirmation link. Nothing is created until the link is opened.
        $r->add('POST', '/signup', fn(Req $req) => Tenants::request($req->json(), $req->ip));
        // Step 2: the emailed link creates the workspace. The admin then signs in with the password they chose.
        $r->add('GET', '/signup/verify', function (Req $req) {
            $res = Tenants::verify((string) ($req->q('token') ?? ''));
            return ['ok' => true, 'tenant' => $res['tenant'], 'message' => 'Your workspace is ready. Sign in with the email and password you chose.'];
        });

        // ------------------------------------------------------------------ lead capture and demo bookings (CRM)
        // The website demo form: saves the lead and returns the Microsoft Bookings address to send the visitor to.
        $r->add('POST', '/leads', fn(Req $req) => Crm::createLead($req->json(), $req->ip));
        // Called by Power Automate when a Bookings appointment is made. Requires the shared X-CRM-Key header.
        $r->add('POST', '/bookings', function (Req $req) {
            Crm::requireKey($req);
            return Crm::recordBooking($req->json());
        });
        // Internal: the leads list for administrators.
        $r->add('GET', '/crm/leads', function () {
            Auth::require('leads.read');
            return ['leads' => Crm::listLeads()];
        });
        $r->add('GET', '/signup/check-slug', function (Req $req) {
            $slug = (string) ($req->q('slug') ?? '');
            return ['slug' => $slug, 'available' => $slug !== '' && Tenants::isSlugAvailable($slug)];
        });

        $r->add('POST', '/auth/login', function (Req $req) {
            $v = new Validator($req->json());
            $email = $v->str('email', true, 200);
            $pw = $v->str('password', true, 200);
            $v->done();
            [$token, $user] = Auth::login((string) $email, (string) $pw, $req->ip, (string) $req->header('user-agent'));
            Audit::log('User Signed In', 'user', $user['id']);
            return ['token' => $token] + self::userPayload($user);
        });

        // Forgot password: always the same reply, so the form cannot reveal which emails have accounts.
        $r->add('POST', '/auth/forgot', function (Req $req) {
            $v = new Validator($req->json());
            $email = $v->str('email', true, 200);
            $v->done();
            $https = (string) $req->header('x-forwarded-proto') === 'https' || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
            Recovery::request((string) $email, (string) $req->header('host'), $https);
            return ['ok' => true, 'message' => 'If an account exists for that email, a reset link has been sent. The link expires in 60 minutes.'];
        });
        $r->add('POST', '/auth/reset', function (Req $req) {
            $v = new Validator($req->json());
            $token = $v->str('token', true, 200);
            $pw = $v->str('new_password', true, 200);
            $v->done();
            Recovery::reset((string) $token, (string) $pw);
            return ['ok' => true, 'message' => 'Your password has been reset. Sign in with the new password.'];
        });

        // ------------------------------------------------------------------ authenticated from here
        $r->add('POST', '/auth/logout', function (Req $req) {
            Auth::logout($req->bearer());
            return ['ok' => true];
        });
        $r->add('GET', '/auth/me', function () {
            $u = Auth::requireUser();
            return self::userPayload($u);
        });
        $r->add('POST', '/auth/password', function (Req $req) {
            $u = Auth::requireUser();
            $v = new Validator($req->json());
            $cur = $v->str('current_password', true, 200);
            $new = $v->str('new_password', true, 200);
            if ($new !== null && !Auth::passwordOk($new)) {
                $v->fail('new_password', 'Use at least 10 characters including a letter and a number.');
            }
            $v->done();
            $row = Db::one('SELECT * FROM users WHERE id = ?', [$u['id']]);
            if (!password_verify((string) $cur, $row['password_hash'])) {
                throw new ApiError(422, 'validation_failed', 'Your current password is incorrect.', ['current_password' => 'Incorrect password.']);
            }
            Db::update('users', (int) $u['id'], ['password_hash' => password_hash((string) $new, PASSWORD_DEFAULT)]);
            Db::run('DELETE FROM sessions WHERE user_id = ? AND token_hash <> ?', [$u['id'], hash('sha256', (string) Auth::currentToken())]);
            Audit::log('Password Changed', 'user', $u['id']);
            return ['ok' => true];
        });

        $r->add('GET', '/meta', function () {
            Auth::requireUser();
            $meta = Meta::payload();
            $meta['departments'] = Auth::can('employees.read') || Auth::can('team.read') || Auth::can('payroll.read') ? Departments::all() : [];
            $meta['entities'] = Auth::can('entities.read') ? Entities::all() : [];
            $meta['components'] = Auth::can('components.read') ? Components::all() : [];
            $meta['schedules'] = Auth::can('schedules.read') ? Schedules::all() : [];
            return $meta;
        });

        // ------------------------------------------------------------------ settings
        $r->add('GET', '/entities', function () {
            Auth::require('entities.read');
            return ['items' => Entities::all(true)];
        });
        $r->add('POST', '/entities', function (Req $req) {
            Auth::require('entities.manage');
            Http::json(201, Entities::create($req->json()));
        });
        $r->add('PUT', '/entities/{id}', function (Req $req, array $p) {
            Auth::require('entities.manage');
            return Entities::update((int) $p['id'], $req->json());
        });
        $r->add('GET', '/departments', function () {
            Auth::requireUser();
            return ['items' => Departments::all()];
        });
        $r->add('POST', '/departments', function (Req $req) {
            Auth::require('employees.write');
            Http::json(201, Departments::create($req->json()));
        });
        $r->add('GET', '/components', function (Req $req) {
            Auth::require('components.read');
            return ['items' => Components::all($req->q('kind'))];
        });
        $r->add('POST', '/components', function (Req $req) {
            Auth::require('components.manage');
            Http::json(201, Components::create($req->json()));
        });
        $r->add('PUT', '/components/{id}', function (Req $req, array $p) {
            Auth::require('components.manage');
            return Components::update((int) $p['id'], $req->json());
        });
        $r->add('GET', '/tax-rules', function (Req $req) {
            Auth::require('taxrules.read');
            return ['items' => TaxRules::all(['country' => $req->q('country')])];
        });
        $r->add('POST', '/tax-rules', function (Req $req) {
            Auth::require('taxrules.manage');
            Http::json(201, TaxRules::create($req->json()));
        });
        $r->add('PUT', '/tax-rules/{id}', function (Req $req, array $p) {
            Auth::require('taxrules.manage');
            return TaxRules::update((int) $p['id'], $req->json());
        });
        $r->add('GET', '/schedules', function () {
            Auth::require('schedules.read');
            return ['items' => Schedules::all()];
        });
        $r->add('POST', '/schedules', function (Req $req) {
            Auth::require('schedules.manage');
            Http::json(201, Schedules::create($req->json()));
        });
        $r->add('PUT', '/schedules/{id}', function (Req $req, array $p) {
            Auth::require('schedules.manage');
            return Schedules::update((int) $p['id'], $req->json());
        });
        $r->add('GET', '/schedules/{id}/periods', function (Req $req, array $p) {
            Auth::require('schedules.read');
            return ['items' => Schedules::suggestions(Schedules::row((int) $p['id']), 4)];
        });
        $r->add('GET', '/users', function () {
            Auth::require('users.manage');
            return ['items' => UserAdmin::all()];
        });
        $r->add('POST', '/users', function (Req $req) {
            Auth::require('users.manage');
            $b = $req->json();
            $res = UserAdmin::create($b);
            // Notify the new user: a secure link to set their own password, not the one the admin typed.
            $https = (string) $req->header('x-forwarded-proto') === 'https' || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
            Recovery::welcome((string) ($b['email'] ?? ''), (string) ($b['name'] ?? ''), (string) $req->header('host'), $https);
            Http::json(201, $res);
        });
        $r->add('PUT', '/users/{id}', function (Req $req, array $p) {
            Auth::require('users.manage');
            return UserAdmin::update((int) $p['id'], $req->json());
        });
        $r->add('DELETE', '/users/{id}', function (Req $req, array $p) {
            Auth::require('users.manage');
            UserAdmin::delete((int) $p['id'], (int) Auth::requireUser()['id']);
            return ['ok' => true];
        });
        $r->add('GET', '/integration/status', function () {
            Auth::require('entities.manage');
            $rules = Db::one('SELECT COUNT(*) AS n, SUM(verified) AS v FROM tax_rules WHERE status = \'active\'');
            return [
                'mail' => Mailer::status(),
                'database' => ['ok' => true],
                'tax_rules' => ['active' => (int) $rules['n'], 'verified' => (int) ($rules['v'] ?? 0)],
                'entities' => (int) Db::val('SELECT COUNT(*) FROM legal_entities'),
                'schedules' => (int) Db::val("SELECT COUNT(*) FROM payroll_schedules WHERE status = 'active'"),
            ];
        });

        // ------------------------------------------------------------------ employees
        $r->add('GET', '/employees', fn(Req $req) => Employees::list($req->query));
        $r->add('POST', '/employees', function (Req $req) {
            Auth::require('employees.write');
            Http::json(201, Employees::create($req->json()));
        });
        $r->add('POST', '/employees/import', function (Req $req) {
            Auth::require('employees.write');
            $b = $req->json();
            return Employees::import(is_array($b['rows'] ?? null) ? $b['rows'] : []);
        });
        $r->add('GET', '/employees/{id}', fn(Req $req, array $p) => Employees::get((int) $p['id']));
        $r->add('PUT', '/employees/{id}', function (Req $req, array $p) {
            Auth::require('employees.write');
            return Employees::update((int) $p['id'], $req->json());
        });
        $r->add('GET', '/employees/{id}/compensation', function (Req $req, array $p) {
            $e = self::employeeFor((int) $p['id'], 'compensation');
            $cur = Compensation::current((int) $e['id']);
            return ['current' => $cur ? Compensation::fmt($cur) : null, 'history' => Compensation::history((int) $e['id'])];
        });
        $r->add('POST', '/employees/{id}/compensation', function (Req $req, array $p) {
            Auth::require('compensation.write');
            Employees::authorized((int) $p['id']);
            return Compensation::change((int) $p['id'], $req->json());
        });
        $r->add('GET', '/employees/{id}/components', function (Req $req, array $p) {
            $e = self::employeeFor((int) $p['id'], 'assignments');
            return ['items' => Assignments::forEmployee((int) $e['id'])];
        });
        $r->add('POST', '/employees/{id}/components', function (Req $req, array $p) {
            Auth::require('assignments.write');
            Employees::authorized((int) $p['id']);
            Http::json(201, Assignments::add((int) $p['id'], $req->json()));
        });
        $r->add('PUT', '/employee-components/{id}', function (Req $req, array $p) {
            Auth::require('assignments.write');
            return Assignments::update((int) $p['id'], $req->json());
        });
        $r->add('DELETE', '/employee-components/{id}', function (Req $req, array $p) {
            Auth::require('assignments.write');
            return Assignments::end((int) $p['id'], $req->q('date'));
        });
        $r->add('GET', '/employees/{id}/payslips', function (Req $req, array $p) {
            $e = Employees::authorized((int) $p['id']);
            if (!Auth::can('payslips.read') && Employees::level($e) !== 'self') {
                throw new ApiError(403, 'forbidden', 'You do not have permission to do this.');
            }
            return Payslips::list(['employee_id' => (int) $p['id']] + $req->query);
        });

        // ------------------------------------------------------------------ payroll
        $r->add('GET', '/payrolls', function (Req $req) {
            Auth::require('payroll.read');
            return ['items' => Payroll::list($req->query)];
        });
        $r->add('POST', '/payrolls', function (Req $req) {
            Http::json(201, Payroll::create($req->json()));
        });
        $r->add('GET', '/payrolls/{id}', function (Req $req, array $p) {
            Auth::require('payroll.read');
            return Payroll::get((int) $p['id']);
        });
        $r->add('GET', '/payrolls/{id}/employees', function (Req $req, array $p) {
            Auth::require('payroll.read');
            return Payroll::employees((int) $p['id'], $req->query);
        });
        $r->add('GET', '/payrolls/{id}/available-employees', function (Req $req, array $p) {
            Auth::require('payroll.read');
            $run = Payroll::row((int) $p['id']);
            $rows = Db::all(
                "SELECT e.id, e.employee_no, e.first_name, e.last_name, e.middle_name, e.currency, e.payroll_frequency, e.employment_status FROM employees e
                  WHERE e.id NOT IN (SELECT employee_id FROM payroll_employees WHERE run_id = ? AND included = 1) AND e.employment_status IN ('ACTIVE','ON_LEAVE','TERMINATED') AND e.joining_date <= ?
                  ORDER BY e.last_name, e.first_name",
                [(int) $p['id'], $run['period_end']]
            );
            return ['items' => array_map(fn($e) => ['id' => (int) $e['id'], 'employee_no' => $e['employee_no'], 'name' => Employees::fullName($e), 'currency' => $e['currency'], 'frequency' => $e['payroll_frequency']], $rows)];
        });
        $r->add('POST', '/payrolls/{id}/employees', function (Req $req, array $p) {
            $b = $req->json();
            return Payroll::setEmployees((int) $p['id'], array_map('intval', (array) ($b['employee_ids'] ?? [])), (bool) ($b['include'] ?? true));
        });
        $r->add('GET', '/payrolls/{id}/employees/{eid}', function (Req $req, array $p) {
            Auth::require('payroll.read');
            return Payroll::employeeDetail((int) $p['id'], (int) $p['eid']);
        });
        $r->add('POST', '/payrolls/{id}/adjustments', function (Req $req, array $p) {
            $b = $req->json();
            $eid = (new Validator($b))->int('employee_id', true, 1);
            if ($eid === null) {
                throw new ApiError(422, 'validation_failed', 'Choose an employee.', ['employee_id' => 'Required.']);
            }
            return Payroll::addAdjustment((int) $p['id'], $eid, $b);
        });
        $r->add('DELETE', '/payrolls/{id}/adjustments/{adj}', fn(Req $req, array $p) => Payroll::removeAdjustment((int) $p['id'], (int) $p['adj']));
        $r->add('GET', '/payrolls/{id}/exceptions', function (Req $req, array $p) {
            Auth::require('payroll.read');
            return Payroll::exceptions((int) $p['id']);
        });
        $r->add('POST', '/payrolls/{id}/calculate', fn(Req $req, array $p) => Payroll::calculate((int) $p['id']));
        $r->add('POST', '/payrolls/{id}/submit', fn(Req $req, array $p) => Payroll::submit((int) $p['id']));
        $r->add('POST', '/payrolls/{id}/back', fn(Req $req, array $p) => Payroll::backToPreparation((int) $p['id']));
        $r->add('POST', '/payrolls/{id}/approve', fn(Req $req, array $p) => Payroll::approve((int) $p['id'], $req->json()['note'] ?? null));
        $r->add('POST', '/payrolls/{id}/reopen', fn(Req $req, array $p) => Payroll::reopen((int) $p['id'], $req->json()['note'] ?? null));
        $r->add('POST', '/payrolls/{id}/process', fn(Req $req, array $p) => Payroll::process((int) $p['id']));
        $r->add('POST', '/payrolls/{id}/pay', fn(Req $req, array $p) => Payroll::markPaid((int) $p['id']));
        $r->add('POST', '/payrolls/{id}/cancel', fn(Req $req, array $p) => Payroll::cancel((int) $p['id'], $req->json()['note'] ?? null));

        // ------------------------------------------------------------------ payslips
        $r->add('GET', '/payslips', function (Req $req) {
            Auth::requireUser();
            return Payslips::list($req->query);
        });
        $r->add('GET', '/payslips/{id}', fn(Req $req, array $p) => Payslips::get((int) $p['id']));
        $r->add('GET', '/payslips/{id}/pdf', function (Req $req, array $p) {
            $ps = Payslips::authorized((int) $p['id']);
            $doc = json_decode((string) $ps['doc_json'], true);
            $pdf = PayslipPdf::render($doc);
            Audit::log('Payslip Downloaded', 'payslip', (int) $ps['id']);
            $name = 'Payslip_' . ($doc['employee']['employee_no'] ?: $ps['id']) . '_' . $ps['period_end'] . '.pdf';
            Http::raw(200, $pdf, 'application/pdf', ['Content-Disposition' => 'inline; filename="' . $name . '"']);
        });
        $r->add('POST', '/payslips/{id}/send', function (Req $req, array $p) {
            Auth::require('payslips.send');
            $ps = Payslips::authorized((int) $p['id']);
            $emp = Employees::row((int) $ps['employee_id']);
            return self::sendPayslip($ps, $emp['work_email'], true);
        });
        $r->add('POST', '/payslips/{id}/email-me', function (Req $req, array $p) {
            $u = Auth::requireUser();
            $ps = Payslips::authorized((int) $p['id']);
            if ($u['employee_id'] === null || (int) $ps['employee_id'] !== $u['employee_id']) {
                throw new ApiError(403, 'forbidden', 'You can only email yourself your own payslips.');
            }
            $recent = (int) Db::val("SELECT COUNT(*) FROM audit_log WHERE action = 'Payslip Sent' AND user_id = ? AND at > ?", [$u['id'], gmdate('Y-m-d\TH:i:s\Z', time() - 3600)]);
            if ($recent >= 10) {
                throw new ApiError(429, 'rate_limited', 'You have requested too many emails in the last hour. Please try again later.');
            }
            $emp = Employees::row((int) $ps['employee_id']);
            return self::sendPayslip($ps, $emp['work_email'], false);
        });

        // ------------------------------------------------------------------ employee self-service
        $r->add('GET', '/me', function () {
            $id = self::ownEmployeeId();
            $emp = Employees::get($id);
            $comp = Compensation::current($id);
            $all = Assignments::forEmployee($id);
            $active = array_values(array_filter($all, fn($a) => $a['status'] === 'active' && ($a['effective_to'] === null || $a['effective_to'] >= Dates::today())));
            $by = fn(string $k) => array_values(array_filter($active, fn($a) => $a['component']['kind'] === $k));
            $rules = TaxRules::applicable($emp['country'], $emp['state_region'] ?? null, Dates::today(), Dates::today());
            return [
                'employee' => $emp,
                'compensation' => $comp ? Compensation::fmt($comp) : null,
                'earnings' => $by('earning'), 'deductions' => $by('deduction'), 'benefits' => $by('benefit'),
                'taxes' => array_map(fn($t) => ['name' => $t['name'], 'type' => $t['tax_type'], 'verified' => (int) $t['verified'] === 1], $rules),
                'payslip_count' => count(Payslips::list([], true)['items']),
            ];
        });
        $r->add('GET', '/me/payslips', function (Req $req) {
            self::ownEmployeeId();
            return Payslips::list($req->query, true);
        });

        // ------------------------------------------------------------------ insight
        $r->add('GET', '/dashboard', fn() => Reports::dashboard());
        $r->add('GET', '/reports/{type}', function (Req $req, array $p) {
            $rep = Reports::run($p['type'], $req->query);
            if ($req->q('format') === 'csv') {
                Audit::log('Report Exported', 'report', $p['type'], null, ['filters' => array_diff_key($req->query, ['format' => 1])]);
                Http::raw(200, Reports::csv($rep), 'text/csv; charset=UTF-8', ['Content-Disposition' => 'attachment; filename="payslip360_' . $p['type'] . '_' . gmdate('Ymd') . '.csv"']);
            }
            return $rep;
        });
        $r->add('GET', '/analytics', fn(Req $req) => Reports::analytics($req->query));

        $r->add('GET', '/audit', function (Req $req) {
            Auth::require('audit.read');
            $where = ['1=1'];
            $p = [];
            foreach (['action' => 'action', 'entity_type' => 'entity_type', 'entity_id' => 'entity_id'] as $k => $col) {
                if ($req->q($k)) {
                    $where[] = $col . ' = ?';
                    $p[] = $req->q($k);
                }
            }
            if ($req->q('user')) {
                $where[] = 'user_email LIKE ?';
                $p[] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $req->q('user')) . '%';
            }
            if ($req->q('from')) {
                $where[] = 'at >= ?';
                $p[] = $req->q('from');
            }
            if ($req->q('to')) {
                $where[] = 'at <= ?';
                $p[] = $req->q('to') . 'T23:59:59Z';
            }
            $limit = max(1, min(200, (int) ($req->q('limit') ?? 50)));
            $offset = max(0, (int) ($req->q('offset') ?? 0));
            $sql = ' WHERE ' . implode(' AND ', $where);
            $total = (int) Db::val('SELECT COUNT(*) FROM audit_log' . $sql, $p);
            $rows = Db::all('SELECT * FROM audit_log' . $sql . " ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}", $p);
            $items = array_map(fn($a) => [
                'id' => (int) $a['id'], 'at' => $a['at'], 'user_email' => $a['user_email'], 'action' => $a['action'], 'entity_type' => $a['entity_type'],
                'entity_id' => $a['entity_id'], 'before' => $a['before_json'] ? json_decode($a['before_json'], true) : null,
                'after' => $a['after_json'] ? json_decode($a['after_json'], true) : null, 'ip' => $a['ip'],
            ], $rows);
            return ['items' => $items, 'total' => $total, 'actions' => array_column(Db::all('SELECT DISTINCT action FROM audit_log ORDER BY action'), 'action')];
        });
    }
}
