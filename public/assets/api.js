export class ApiError extends Error {
  constructor(message, status, code, details = {}) {
    super(message);
    this.status = status;
    this.code = code;
    this.details = details;
  }
}

let csrfToken = '';

export function setCsrfToken(value) {
  csrfToken = value || '';
}

export async function api(path, options = {}) {
  const method = (options.method || 'GET').toUpperCase();
  const headers = { Accept: 'application/json', ...(options.headers || {}) };
  if (options.body && typeof options.body !== 'string') {
    headers['Content-Type'] = 'application/json';
    options.body = JSON.stringify(options.body);
  }
  if (!['GET', 'HEAD', 'OPTIONS'].includes(method) && csrfToken) headers['X-CSRF-Token'] = csrfToken;
  const response = await fetch(path, { credentials: 'same-origin', ...options, method, headers });
  const contentType = response.headers.get('content-type') || '';
  const payload = contentType.includes('json') ? await response.json() : null;
  if (response.status === 401) {
    const loginRequest = path === '/api/v1/auth/login';
    if (!loginRequest && window.location.pathname !== '/login') window.location.assign('/login');
    throw new ApiError(payload?.error?.message || 'Anmeldung erforderlich.', 401, payload?.error?.code || 'AUTHENTICATION_REQUIRED');
  }
  if (!response.ok) {
    throw new ApiError(payload?.error?.message || `HTTP ${response.status}`, response.status, payload?.error?.code || 'REQUEST_FAILED', payload?.error?.details || {});
  }
  return payload;
}
