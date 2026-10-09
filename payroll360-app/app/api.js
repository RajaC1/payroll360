// Payroll360 API client. The session token lives in sessionStorage and is sent as a bearer token
// (cookies are not used, so the app works inside SharePoint / Teams frames).

const TOKEN_KEY = 'p360.token';

export class ApiError extends Error {
  constructor(status, code, message, fields = {}) {
    super(message);
    this.status = status;
    this.code = code;
    this.fields = fields;
  }
}

export const session = {
  get token() { try { return sessionStorage.getItem(TOKEN_KEY); } catch { return null; } },
  set(t) { try { sessionStorage.setItem(TOKEN_KEY, t); } catch { /* storage unavailable */ } },
  clear() { try { sessionStorage.removeItem(TOKEN_KEY); } catch { /* ignore */ } },
};

const listeners = { unauthorized: [] };
export const onUnauthorized = (fn) => listeners.unauthorized.push(fn);

function url(path, query) {
  const u = new URL('api' + path, document.baseURI);
  if (query) {
    for (const [k, v] of Object.entries(query)) {
      if (v !== undefined && v !== null && v !== '') u.searchParams.set(k, v);
    }
  }
  return u;
}

// Multi-tenant mode without real subdomains yet (e.g. testing locally before DNS exists): a one-time
// ?tenant=slug on the URL is remembered for the rest of this tab and sent on every call, exactly like the
// server's X-Tenant-Slug testing header. Real deployments use subdomains and never need this.
const TENANT_KEY = 'p360.tenant';
try {
  const t = new URLSearchParams(location.search).get('tenant');
  if (t) sessionStorage.setItem(TENANT_KEY, t);
} catch { /* storage or URL unavailable */ }
const tenantSlug = () => { try { return sessionStorage.getItem(TENANT_KEY); } catch { return null; } };

async function request(method, path, { body, query, auth = true, raw = false } = {}) {
  const headers = {};
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  if (auth && session.token) {
    headers.Authorization = `Bearer ${session.token}`;
    headers['X-Auth-Token'] = session.token;
  }
  const tenant = tenantSlug();
  if (tenant) headers['X-Tenant-Slug'] = tenant;
  let res;
  try {
    res = await fetch(url(path, query), { method, headers, body: body !== undefined ? JSON.stringify(body) : undefined });
  } catch {
    throw new ApiError(0, 'network', 'Cannot reach the Payroll360 server. Check your connection and try again.');
  }
  if (raw) {
    if (!res.ok) {
      let msg = 'The file could not be downloaded.';
      try { msg = (await res.json()).error.message; } catch { /* keep default */ }
      if (res.status === 401 && auth) listeners.unauthorized.forEach((f) => f());
      throw new ApiError(res.status, 'download_failed', msg);
    }
    const cd = res.headers.get('content-disposition') || '';
    const m = /filename="?([^";]+)"?/i.exec(cd);
    return { blob: await res.blob(), filename: m ? m[1] : 'download' };
  }
  let data = null;
  try { data = await res.json(); } catch { /* empty or non-JSON body */ }
  if (!res.ok) {
    const e = data?.error || {};
    if (res.status === 401 && auth && e.code !== 'invalid_credentials') listeners.unauthorized.forEach((f) => f());
    throw new ApiError(res.status, e.code || 'error', e.message || `The server returned an unexpected error (${res.status}).`, e.fields || {});
  }
  return data;
}

export const api = {
  get: (path, query, opts) => request('GET', path, { query, ...opts }),
  post: (path, body = {}, opts) => request('POST', path, { body, ...opts }),
  put: (path, body = {}, opts) => request('PUT', path, { body, ...opts }),
  del: (path, query) => request('DELETE', path, { query }),
  file: (path, query) => request('GET', path, { query, raw: true }),
};
