# Payroll360

Free payroll management, built natively on Microsoft SharePoint. Part of the
[Appz360](https://www.appz360.com) family of Microsoft 365 apps.

## Repo layout

- **`payroll360-app/`** — the application itself: PHP 8 + SQLite backend (`api/`),
  the front-end (`app/`), sign-in/sign-up pages, and static assets. This is what's
  deployed to the live web server.
- **`payroll360-spfx/`** — a SharePoint Framework (SPFx) web part that runs Payroll360
  as a full-page app inside SharePoint (a Single Part App Page), instead of embedding
  it via the Embed web part. See `payroll360-spfx/README.md` for build/deploy steps.

## Running payroll360-app locally

Requires PHP 8.1+.

```
cd payroll360-app
php -S 127.0.0.1:8360 router.php
```

Seed a demo company (creates a local SQLite database, never touches production):

```
php api/cli.php seed-demo
```

Copy `payslip-config.sample.php` to `payslip-config.php` to configure mail, storage
paths, etc. for a real deployment — `payslip-config.php` is git-ignored and must never
be committed.

## Live deployment

The production app runs at `https://www.appz360.com/payroll360-app/`.
