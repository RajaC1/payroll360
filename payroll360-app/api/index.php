<?php
declare(strict_types=1);

/**
 * Payroll360 API front controller. Every /api/... request lands here.
 * Authentication is a bearer token in the Authorization header (works inside SharePoint / Teams frames, no cookies).
 */

use P360\ApiError;
use P360\Audit;
use P360\Auth;
use P360\Config;
use P360\Http;
use P360\Req;
use P360\Router;
use P360\Routes;
use P360\Schema;
use P360\ControlDb;
use P360\Tenancy;

ini_set('display_errors', '0');
ini_set('log_errors', '1');
mb_internal_encoding('UTF-8');

foreach (['Core', 'Schema', 'Auth', 'Engine', 'Reference', 'People', 'Payroll', 'Reports', 'Pdf', 'Mailer', 'Tenancy', 'Recovery', 'Crm', 'Routes'] as $file) {
    require_once __DIR__ . '/src/' . $file . '.php';
}

function api_error(int $status, string $code, string $message, array $fields = []): never
{
    Http::json($status, ['error' => ['code' => $code, 'message' => $message, 'fields' => (object) $fields]]);
}

try {
    $req = Req::capture();
    Audit::setIp($req->ip);

    if ($req->method === 'OPTIONS') {
        // No CORS headers are sent: the API is same-origin only.
        Http::raw(204, '', 'text/plain');
    }

    // Multi-tenant mode (self-service sign-up): resolve which company this request belongs to before touching
    // any data. /signup and /signup/check-slug are the only routes reachable with no tenant resolved - they
    // work against the separate control database instead. Every other route needs a real, active tenant.
    // Single-tenant deployments (the default) never set PAYSLIP360_MULTITENANT, so none of this runs for them.
    $isSignupRoute = false;
    if (ControlDb::enabled()) {
        ControlDb::migrate();
        $isSignupRoute = in_array($req->path, ['/signup', '/signup/check-slug', '/signup/verify'], true);
        if (!$isSignupRoute) {
            $tenant = Tenancy::resolve($req);
            if ($tenant === null) {
                // No tenant for this host (the base/sign-up domain, or an unknown address). /health still
                // answers, since load balancers and uptime checks should not need to know about any one
                // tenant; every other route is meaningless without a workspace to act on.
                if ($req->path === '/health') {
                    Http::json(200, ['ok' => true, 'multitenant' => true]);
                }
                throw new ApiError(404, 'not_found', 'This address is not a Payroll360 workspace.');
            }
            if ($tenant['status'] !== 'active') {
                throw new ApiError(403, 'forbidden', 'This workspace is not currently active.');
            }
            Tenancy::apply($tenant);
        }
    }

    // Signup runs entirely against the control database (via Tenants::provision); there is no tenant schema
    // to migrate and no tenant-scoped session to resolve until a workspace exists.
    if (!$isSignupRoute) {
        Schema::migrate();
        Auth::resolve($req->bearer());
    }

    $router = new Router();
    Routes::register($router);
    $router->dispatch($req);
} catch (ApiError $e) {
    api_error($e->status, $e->errCode, $e->getMessage(), $e->fields);
} catch (PDOException $e) {
    $msg = $e->getMessage();
    // Protective database triggers (approved payroll, payslips, audit log) speak in plain language.
    if (preg_match('/(cannot be modified|cannot be changed|cannot be deleted|append-only|cannot be deleted)/i', $msg) && preg_match('/([A-Z][^:]*\.)\s*$/', $msg, $m)) {
        api_error(409, 'locked', trim($m[1]));
    }
    if (stripos($msg, 'UNIQUE constraint failed') !== false) {
        api_error(409, 'duplicate', 'That record already exists.');
    }
    error_log('Payroll360 database error: ' . $msg);
    api_error(500, 'server_error', 'Something went wrong on our side. The problem has been logged.', Config::get('debug') ? ['detail' => $msg] : []);
} catch (Throwable $e) {
    error_log('Payroll360 error: ' . $e::class . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
    api_error(500, 'server_error', 'Something went wrong on our side. The problem has been logged.', Config::get('debug') ? ['detail' => $e->getMessage()] : []);
}
