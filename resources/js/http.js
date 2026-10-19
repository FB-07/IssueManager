import { ApiError } from './api-error';

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

/** Thin fetch wrapper for the Laravel JSON API. Send a FormData as `body` for uploads. */
export async function http(method, url, body) {
    const headers = { Accept: 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' };
    let payload;
    if (body instanceof FormData) {
        payload = body;
    } else if (body !== undefined) {
        headers['Content-Type'] = 'application/json';
        payload = JSON.stringify(body);
    }

    const res = await fetch(url, { method, headers, body: payload, credentials: 'same-origin' });

    if (res.status === 401) {
        window.location.href = '/login';
        throw new ApiError(401, { message: 'Unauthenticated' });
    }
    if (res.status === 419) {
        throw new ApiError(419, { message: 'Session expired, reload the page.' });
    }

    const data = res.status === 204 ? null : await res.json().catch(() => null);
    if (!res.ok) {
        const firstError = data?.errors ? Object.values(data.errors)[0]?.[0] : null;
        throw new ApiError(res.status, { ...(data ?? {}), message: firstError ?? data?.message ?? `HTTP ${res.status}` });
    }

    return data;
}
