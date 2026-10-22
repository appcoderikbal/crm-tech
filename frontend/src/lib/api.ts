/**
 * API client.
 *
 * Authentication is a HttpOnly session cookie, so there is no token in
 * JavaScript and nothing to steal from localStorage. The CSRF token returned by
 * the login/me endpoints is held in memory only and echoed on every
 * state-changing request.
 */

export interface ApiMeta {
  total?: number;
  page?: number;
  per_page?: number;
  pages?: number;
  [key: string]: unknown;
}

export interface ApiEnvelope<T> {
  data: T;
  meta?: ApiMeta;
}

export class ApiError extends Error {
  constructor(
    message: string,
    readonly status: number,
    readonly code: string = 'error',
    readonly details: Record<string, unknown> = {},
  ) {
    super(message);
    this.name = 'ApiError';
  }

  /** Field-level validation messages, when the server sent them. */
  get fieldErrors(): Record<string, string> {
    const fields = this.details?.fields;
    return fields && typeof fields === 'object' ? (fields as Record<string, string>) : {};
  }

  get isUnauthenticated(): boolean {
    return this.status === 401;
  }

  get requiresConfirmation(): boolean {
    return this.details?.requires_confirmation === true;
  }
}

const BASE = '/api/v1';

let csrfToken = '';
let onUnauthenticated: (() => void) | null = null;

export function setCsrfToken(token: string): void {
  csrfToken = token;
}

export function getCsrfToken(): string {
  return csrfToken;
}

/** Registered by the auth provider so a dead session redirects to login once. */
export function setUnauthenticatedHandler(handler: () => void): void {
  onUnauthenticated = handler;
}

type Query = Record<string, string | number | boolean | null | undefined>;

export function buildQuery(params: Query = {}): string {
  const search = new URLSearchParams();
  for (const [key, value] of Object.entries(params)) {
    if (value === null || value === undefined || value === '') continue;
    search.set(key, String(value));
  }
  const qs = search.toString();
  return qs ? `?${qs}` : '';
}

interface RequestOptions {
  method?: string;
  body?: unknown;
  signal?: AbortSignal;
  /** Multipart uploads pass FormData and must not set Content-Type. */
  formData?: FormData;
}

async function request<T>(path: string, options: RequestOptions = {}): Promise<ApiEnvelope<T>> {
  const method = options.method ?? 'GET';
  const headers: Record<string, string> = { Accept: 'application/json' };

  if (method !== 'GET' && method !== 'HEAD') {
    headers['X-CSRF-Token'] = csrfToken;
  }

  let body: BodyInit | undefined;
  if (options.formData) {
    body = options.formData;
  } else if (options.body !== undefined) {
    headers['Content-Type'] = 'application/json';
    body = JSON.stringify(options.body);
  }

  let response: Response;
  try {
    response = await fetch(`${BASE}${path}`, {
      method,
      headers,
      body,
      credentials: 'same-origin',
      signal: options.signal,
    });
  } catch (error) {
    if ((error as Error).name === 'AbortError') throw error;
    throw new ApiError('Could not reach the server. Check your connection.', 0, 'network_error');
  }

  if (response.status === 204) {
    return { data: undefined as T };
  }

  const text = await response.text();
  let payload: unknown = null;
  if (text) {
    try {
      payload = JSON.parse(text);
    } catch {
      throw new ApiError('The server returned an unexpected response.', response.status, 'invalid_response');
    }
  }

  if (!response.ok) {
    const error = (payload as { error?: { message?: string; code?: string; details?: Record<string, unknown> } })?.error;
    const apiError = new ApiError(
      error?.message ?? `Request failed (${response.status}).`,
      response.status,
      error?.code ?? 'error',
      error?.details ?? {},
    );
    if (apiError.isUnauthenticated && onUnauthenticated) {
      onUnauthenticated();
    }
    throw apiError;
  }

  return payload as ApiEnvelope<T>;
}

export const api = {
  get: <T>(path: string, query?: Query, signal?: AbortSignal) =>
    request<T>(`${path}${buildQuery(query)}`, { signal }),
  post: <T>(path: string, body?: unknown) => request<T>(path, { method: 'POST', body }),
  patch: <T>(path: string, body?: unknown) => request<T>(path, { method: 'PATCH', body }),
  delete: <T>(path: string, body?: unknown) => request<T>(path, { method: 'DELETE', body }),
  upload: <T>(path: string, formData: FormData) => request<T>(path, { method: 'POST', formData }),

  /**
   * Triggers a file download for an endpoint that streams CSV.
   * Uses a form POST so the browser handles the save dialog natively and the
   * session cookie is sent without any token ending up in a URL.
   */
  download(path: string, fields: Record<string, string | number | boolean | undefined> = {}): void {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = `${BASE}${path}`;
    form.style.display = 'none';

    const addField = (name: string, value: string) => {
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = name;
      input.value = value;
      form.appendChild(input);
    };

    for (const [key, value] of Object.entries(fields)) {
      if (value !== undefined && value !== '') addField(key, String(value));
    }
    // A form POST cannot set a custom header, so the CSRF token travels as a
    // field; the server accepts it from either place.
    addField('_csrf', csrfToken);

    document.body.appendChild(form);
    form.submit();
    document.body.removeChild(form);
  },

  /** GET-based download (used for the import error report). */
  downloadUrl(path: string, query?: Query): string {
    return `${BASE}${path}${buildQuery(query)}`;
  },
};
