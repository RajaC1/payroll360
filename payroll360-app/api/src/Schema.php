<?php
declare(strict_types=1);

namespace P360;

/**
 * Database schema. Money columns are INTEGER minor units; dates are YYYY-MM-DD; timestamps are UTC ISO-8601.
 * Triggers make approved payroll, payslips and the audit log tamper-resistant at the database level.
 */
final class Schema
{
    public const VERSION = 1;

    public static function migrate(): void
    {
        $pdo = Db::pdo();
        $pdo->exec('CREATE TABLE IF NOT EXISTS meta (k TEXT PRIMARY KEY, v TEXT)');
        $current = (int) Db::val("SELECT v FROM meta WHERE k = 'schema_version'");
        if ($current >= self::VERSION) {
            return;
        }
        Db::tx(function () use ($pdo) {
            foreach (self::statements() as $sql) {
                $pdo->exec($sql);
            }
            Db::run("INSERT INTO meta (k, v) VALUES ('schema_version', ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v", [(string) self::VERSION]);
        });
    }

    /** @return string[] */
    private static function statements(): array
    {
        $locked = "('APPROVED','PROCESSING','PROCESSED','PAID')";
        return [
            "CREATE TABLE legal_entities (
                id INTEGER PRIMARY KEY, name TEXT NOT NULL, legal_name TEXT, country TEXT NOT NULL, currency TEXT NOT NULL,
                address TEXT, logo TEXT, payslip_title TEXT, sender_name TEXT, sender_email TEXT, reply_to TEXT, cc TEXT,
                active INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)",
            "CREATE TABLE departments (id INTEGER PRIMARY KEY, name TEXT NOT NULL UNIQUE, active INTEGER NOT NULL DEFAULT 1)",
            "CREATE TABLE payroll_schedules (
                id INTEGER PRIMARY KEY, name TEXT NOT NULL, frequency TEXT NOT NULL, entity_id INTEGER NOT NULL REFERENCES legal_entities(id),
                country TEXT NOT NULL, currency TEXT NOT NULL, anchor_date TEXT NOT NULL,
                cutoff_offset_days INTEGER NOT NULL DEFAULT 0, pay_offset_days INTEGER NOT NULL DEFAULT 0,
                status TEXT NOT NULL DEFAULT 'active', created_at TEXT NOT NULL, updated_at TEXT NOT NULL)",
            "CREATE TABLE employees (
                id INTEGER PRIMARY KEY, employee_no TEXT NOT NULL UNIQUE, first_name TEXT NOT NULL, middle_name TEXT, last_name TEXT NOT NULL,
                work_email TEXT NOT NULL, personal_email TEXT, phone TEXT,
                entity_id INTEGER NOT NULL REFERENCES legal_entities(id), department_id INTEGER REFERENCES departments(id),
                manager_id INTEGER REFERENCES employees(id), job_title TEXT, employment_type TEXT NOT NULL, employment_status TEXT NOT NULL,
                joining_date TEXT NOT NULL, probation_end TEXT, termination_date TEXT, work_location TEXT,
                country TEXT NOT NULL, state_region TEXT, currency TEXT NOT NULL, tax_jurisdiction TEXT,
                payroll_frequency TEXT NOT NULL, schedule_id INTEGER REFERENCES payroll_schedules(id),
                tax_id_enc TEXT, bank_name TEXT, bank_account_enc TEXT, photo TEXT,
                created_at TEXT NOT NULL, updated_at TEXT NOT NULL)",
            "CREATE INDEX idx_emp_entity ON employees(entity_id)",
            "CREATE INDEX idx_emp_status ON employees(employment_status)",
            "CREATE INDEX idx_emp_manager ON employees(manager_id)",
            "CREATE TABLE users (
                id INTEGER PRIMARY KEY, email TEXT NOT NULL UNIQUE COLLATE NOCASE, name TEXT NOT NULL, password_hash TEXT NOT NULL,
                role TEXT NOT NULL, employee_id INTEGER REFERENCES employees(id), active INTEGER NOT NULL DEFAULT 1,
                last_login_at TEXT, created_at TEXT NOT NULL)",
            "CREATE TABLE sessions (
                token_hash TEXT PRIMARY KEY, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                created_at TEXT NOT NULL, expires_at TEXT NOT NULL, ip TEXT, user_agent TEXT)",
            "CREATE TABLE login_attempts (id INTEGER PRIMARY KEY, email TEXT, ip TEXT, at INTEGER NOT NULL, ok INTEGER NOT NULL)",
            "CREATE INDEX idx_login_attempts ON login_attempts(at)",
            "CREATE TABLE compensation (
                id INTEGER PRIMARY KEY, employee_id INTEGER NOT NULL REFERENCES employees(id), pay_basis TEXT NOT NULL,
                base_minor INTEGER NOT NULL, currency TEXT NOT NULL, overtime_multiplier_bp INTEGER NOT NULL DEFAULT 15000,
                effective_from TEXT NOT NULL, effective_to TEXT, reason TEXT, previous_id INTEGER REFERENCES compensation(id),
                created_by INTEGER REFERENCES users(id), created_at TEXT NOT NULL)",
            "CREATE INDEX idx_comp_emp ON compensation(employee_id, effective_from)",
            "CREATE TABLE components (
                id INTEGER PRIMARY KEY, code TEXT NOT NULL UNIQUE, name TEXT NOT NULL, kind TEXT NOT NULL, category TEXT,
                calc TEXT NOT NULL DEFAULT 'fixed', basis TEXT NOT NULL DEFAULT 'base', taxable INTEGER NOT NULL DEFAULT 1,
                pre_tax INTEGER NOT NULL DEFAULT 0, default_employee_minor INTEGER, default_employer_minor INTEGER,
                default_rate_bp INTEGER, default_employer_rate_bp INTEGER, frequency TEXT NOT NULL DEFAULT 'monthly',
                provider TEXT, effective_from TEXT, status TEXT NOT NULL DEFAULT 'active', created_at TEXT NOT NULL, updated_at TEXT NOT NULL)",
            "CREATE TABLE employee_components (
                id INTEGER PRIMARY KEY, employee_id INTEGER NOT NULL REFERENCES employees(id), component_id INTEGER NOT NULL REFERENCES components(id),
                amount_minor INTEGER, rate_bp INTEGER, employer_amount_minor INTEGER, employer_rate_bp INTEGER,
                frequency TEXT NOT NULL, effective_from TEXT NOT NULL, effective_to TEXT, taxable INTEGER, pre_tax INTEGER,
                provider TEXT, description TEXT, status TEXT NOT NULL DEFAULT 'active', created_by INTEGER,
                created_at TEXT NOT NULL, updated_at TEXT NOT NULL)",
            "CREATE INDEX idx_empcomp_emp ON employee_components(employee_id)",
            "CREATE TABLE tax_rules (
                id INTEGER PRIMARY KEY, code TEXT NOT NULL, name TEXT NOT NULL, country TEXT NOT NULL, region TEXT, tax_year INTEGER,
                tax_type TEXT NOT NULL, method TEXT NOT NULL, base_kind TEXT NOT NULL DEFAULT 'taxable_income', cap_scope TEXT NOT NULL DEFAULT 'ytd',
                rate_bp INTEGER, threshold_minor INTEGER NOT NULL DEFAULT 0, cap_minor INTEGER, brackets_json TEXT, fixed_minor INTEGER,
                employer_rate_bp INTEGER NOT NULL DEFAULT 0, employer_threshold_minor INTEGER NOT NULL DEFAULT 0,
                currency TEXT NOT NULL, effective_from TEXT NOT NULL, effective_to TEXT, verified INTEGER NOT NULL DEFAULT 0,
                source_note TEXT, status TEXT NOT NULL DEFAULT 'active', created_at TEXT NOT NULL, updated_at TEXT NOT NULL)",
            "CREATE INDEX idx_tax_country ON tax_rules(country, region)",
            "CREATE TABLE payroll_runs (
                id INTEGER PRIMARY KEY, name TEXT NOT NULL, schedule_id INTEGER NOT NULL REFERENCES payroll_schedules(id),
                entity_id INTEGER NOT NULL REFERENCES legal_entities(id), frequency TEXT NOT NULL, currency TEXT NOT NULL,
                period_start TEXT NOT NULL, period_end TEXT NOT NULL, cutoff_date TEXT NOT NULL, pay_date TEXT NOT NULL,
                status TEXT NOT NULL, calculated_at TEXT, inputs_hash TEXT,
                employee_count INTEGER NOT NULL DEFAULT 0, gross_minor INTEGER NOT NULL DEFAULT 0, taxes_minor INTEGER NOT NULL DEFAULT 0,
                benefits_minor INTEGER NOT NULL DEFAULT 0, deductions_minor INTEGER NOT NULL DEFAULT 0, net_minor INTEGER NOT NULL DEFAULT 0,
                employer_contrib_minor INTEGER NOT NULL DEFAULT 0, employer_cost_minor INTEGER NOT NULL DEFAULT 0,
                exception_count INTEGER NOT NULL DEFAULT 0, created_by INTEGER, created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
                approved_by INTEGER, approved_at TEXT, processed_by INTEGER, processed_at TEXT, paid_at TEXT, cancelled_reason TEXT)",
            "CREATE INDEX idx_run_status ON payroll_runs(status)",
            "CREATE INDEX idx_run_sched ON payroll_runs(schedule_id, period_start)",
            "CREATE TABLE payroll_status_history (
                id INTEGER PRIMARY KEY, run_id INTEGER NOT NULL REFERENCES payroll_runs(id), from_status TEXT, to_status TEXT NOT NULL,
                user_id INTEGER, note TEXT, at TEXT NOT NULL)",
            "CREATE TABLE payroll_employees (
                id INTEGER PRIMARY KEY, run_id INTEGER NOT NULL REFERENCES payroll_runs(id) ON DELETE CASCADE,
                employee_id INTEGER NOT NULL REFERENCES employees(id), included INTEGER NOT NULL DEFAULT 1,
                status TEXT NOT NULL DEFAULT 'pending', snapshot_json TEXT, department_name TEXT, country TEXT,
                base_minor INTEGER NOT NULL DEFAULT 0, earnings_minor INTEGER NOT NULL DEFAULT 0, allowances_minor INTEGER NOT NULL DEFAULT 0,
                gross_minor INTEGER NOT NULL DEFAULT 0, taxable_gross_minor INTEGER NOT NULL DEFAULT 0, taxes_minor INTEGER NOT NULL DEFAULT 0,
                benefits_minor INTEGER NOT NULL DEFAULT 0, deductions_minor INTEGER NOT NULL DEFAULT 0, net_minor INTEGER NOT NULL DEFAULT 0,
                employer_contrib_minor INTEGER NOT NULL DEFAULT 0, employer_cost_minor INTEGER NOT NULL DEFAULT 0,
                exceptions_json TEXT, UNIQUE (run_id, employee_id))",
            "CREATE INDEX idx_pe_run ON payroll_employees(run_id)",
            "CREATE INDEX idx_pe_emp ON payroll_employees(employee_id)",
            "CREATE TABLE payroll_lines (
                id INTEGER PRIMARY KEY, payroll_employee_id INTEGER NOT NULL REFERENCES payroll_employees(id) ON DELETE CASCADE,
                kind TEXT NOT NULL, code TEXT, name TEXT NOT NULL, category TEXT, method TEXT,
                amount_minor INTEGER NOT NULL, employer_minor INTEGER NOT NULL DEFAULT 0, taxable INTEGER, note TEXT, sort INTEGER NOT NULL DEFAULT 0)",
            "CREATE INDEX idx_pl_pe ON payroll_lines(payroll_employee_id)",
            "CREATE TABLE payroll_adjustments (
                id INTEGER PRIMARY KEY, run_id INTEGER NOT NULL REFERENCES payroll_runs(id) ON DELETE CASCADE,
                employee_id INTEGER NOT NULL REFERENCES employees(id), kind TEXT NOT NULL, name TEXT NOT NULL,
                amount_minor INTEGER, hours_hundredths INTEGER, taxable INTEGER NOT NULL DEFAULT 1, reason TEXT NOT NULL,
                created_by INTEGER, created_at TEXT NOT NULL)",
            "CREATE INDEX idx_adj_run ON payroll_adjustments(run_id, employee_id)",
            "CREATE TABLE payroll_approvals (
                id INTEGER PRIMARY KEY, run_id INTEGER NOT NULL REFERENCES payroll_runs(id), action TEXT NOT NULL,
                user_id INTEGER, user_email TEXT, at TEXT NOT NULL, totals_json TEXT, note TEXT)",
            "CREATE TABLE payslips (
                id INTEGER PRIMARY KEY, number TEXT NOT NULL UNIQUE, run_id INTEGER NOT NULL REFERENCES payroll_runs(id),
                payroll_employee_id INTEGER NOT NULL UNIQUE REFERENCES payroll_employees(id), employee_id INTEGER NOT NULL REFERENCES employees(id),
                period_start TEXT NOT NULL, period_end TEXT NOT NULL, pay_date TEXT NOT NULL, currency TEXT NOT NULL,
                gross_minor INTEGER NOT NULL, deductions_minor INTEGER NOT NULL, net_minor INTEGER NOT NULL,
                status TEXT NOT NULL DEFAULT 'generated', doc_json TEXT NOT NULL, generated_at TEXT NOT NULL, generated_by INTEGER,
                sent_at TEXT, sent_to TEXT, sent_count INTEGER NOT NULL DEFAULT 0)",
            "CREATE INDEX idx_ps_emp ON payslips(employee_id)",
            "CREATE INDEX idx_ps_run ON payslips(run_id)",
            "CREATE TABLE audit_log (
                id INTEGER PRIMARY KEY, at TEXT NOT NULL, user_id INTEGER, user_email TEXT, action TEXT NOT NULL,
                entity_type TEXT, entity_id TEXT, before_json TEXT, after_json TEXT, ip TEXT)",
            "CREATE INDEX idx_audit_at ON audit_log(at)",

            // ---- Immutability guards -------------------------------------------------------------
            "CREATE TRIGGER trg_pe_locked_update BEFORE UPDATE ON payroll_employees
                WHEN (SELECT status FROM payroll_runs WHERE id = OLD.run_id) IN {$locked}
                BEGIN SELECT RAISE(ABORT, 'Payroll is approved or processed and cannot be modified.'); END",
            "CREATE TRIGGER trg_pe_locked_delete BEFORE DELETE ON payroll_employees
                WHEN (SELECT status FROM payroll_runs WHERE id = OLD.run_id) IN {$locked}
                BEGIN SELECT RAISE(ABORT, 'Payroll is approved or processed and cannot be modified.'); END",
            "CREATE TRIGGER trg_pe_locked_insert BEFORE INSERT ON payroll_employees
                WHEN (SELECT status FROM payroll_runs WHERE id = NEW.run_id) IN {$locked}
                BEGIN SELECT RAISE(ABORT, 'Payroll is approved or processed and cannot be modified.'); END",
            "CREATE TRIGGER trg_pl_locked_update BEFORE UPDATE ON payroll_lines
                WHEN (SELECT r.status FROM payroll_runs r JOIN payroll_employees e ON e.run_id = r.id WHERE e.id = OLD.payroll_employee_id) IN {$locked}
                BEGIN SELECT RAISE(ABORT, 'Payroll is approved or processed and cannot be modified.'); END",
            "CREATE TRIGGER trg_pl_locked_delete BEFORE DELETE ON payroll_lines
                WHEN (SELECT r.status FROM payroll_runs r JOIN payroll_employees e ON e.run_id = r.id WHERE e.id = OLD.payroll_employee_id) IN {$locked}
                BEGIN SELECT RAISE(ABORT, 'Payroll is approved or processed and cannot be modified.'); END",
            "CREATE TRIGGER trg_pl_locked_insert BEFORE INSERT ON payroll_lines
                WHEN (SELECT r.status FROM payroll_runs r JOIN payroll_employees e ON e.run_id = r.id WHERE e.id = NEW.payroll_employee_id) IN {$locked}
                BEGIN SELECT RAISE(ABORT, 'Payroll is approved or processed and cannot be modified.'); END",
            "CREATE TRIGGER trg_adj_locked_insert BEFORE INSERT ON payroll_adjustments
                WHEN (SELECT status FROM payroll_runs WHERE id = NEW.run_id) IN {$locked}
                BEGIN SELECT RAISE(ABORT, 'Payroll is approved or processed and cannot be modified.'); END",
            "CREATE TRIGGER trg_adj_locked_update BEFORE UPDATE ON payroll_adjustments
                WHEN (SELECT status FROM payroll_runs WHERE id = OLD.run_id) IN {$locked}
                BEGIN SELECT RAISE(ABORT, 'Payroll is approved or processed and cannot be modified.'); END",
            "CREATE TRIGGER trg_adj_locked_delete BEFORE DELETE ON payroll_adjustments
                WHEN (SELECT status FROM payroll_runs WHERE id = OLD.run_id) IN {$locked}
                BEGIN SELECT RAISE(ABORT, 'Payroll is approved or processed and cannot be modified.'); END",
            "CREATE TRIGGER trg_run_finalized BEFORE UPDATE ON payroll_runs
                WHEN OLD.status IN ('PROCESSED','PAID') AND (
                    NEW.period_start <> OLD.period_start OR NEW.period_end <> OLD.period_end OR NEW.pay_date <> OLD.pay_date OR
                    NEW.gross_minor <> OLD.gross_minor OR NEW.net_minor <> OLD.net_minor OR NEW.taxes_minor <> OLD.taxes_minor OR
                    NEW.deductions_minor <> OLD.deductions_minor OR NEW.employee_count <> OLD.employee_count OR
                    NEW.employer_cost_minor <> OLD.employer_cost_minor)
                BEGIN SELECT RAISE(ABORT, 'A processed payroll cannot be changed.'); END",
            "CREATE TRIGGER trg_run_no_delete BEFORE DELETE ON payroll_runs
                WHEN OLD.status <> 'DRAFT'
                BEGIN SELECT RAISE(ABORT, 'Payroll history cannot be deleted.'); END",
            "CREATE TRIGGER trg_payslip_immutable BEFORE UPDATE ON payslips
                WHEN NEW.doc_json <> OLD.doc_json OR NEW.gross_minor <> OLD.gross_minor OR NEW.net_minor <> OLD.net_minor OR
                     NEW.deductions_minor <> OLD.deductions_minor OR NEW.employee_id <> OLD.employee_id OR NEW.number <> OLD.number
                BEGIN SELECT RAISE(ABORT, 'A generated payslip cannot be changed.'); END",
            "CREATE TRIGGER trg_payslip_no_delete BEFORE DELETE ON payslips
                BEGIN SELECT RAISE(ABORT, 'Payslips cannot be deleted.'); END",
            "CREATE TRIGGER trg_audit_no_update BEFORE UPDATE ON audit_log
                BEGIN SELECT RAISE(ABORT, 'The audit log is append-only.'); END",
            "CREATE TRIGGER trg_audit_no_delete BEFORE DELETE ON audit_log
                BEGIN SELECT RAISE(ABORT, 'The audit log is append-only.'); END",
            "CREATE TRIGGER trg_history_no_update BEFORE UPDATE ON payroll_status_history
                BEGIN SELECT RAISE(ABORT, 'Status history is append-only.'); END",
            "CREATE TRIGGER trg_approvals_no_update BEFORE UPDATE ON payroll_approvals
                BEGIN SELECT RAISE(ABORT, 'Approval records are append-only.'); END",
        ];
    }
}
