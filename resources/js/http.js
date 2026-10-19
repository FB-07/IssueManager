import { ApiError } from './api-error';

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

/** The YouTrack token of this user expired or was rejected: end the app session and go to the login page. */
export async function signOutAndReturnToLogin() {
    try {
        await fetch('/logout', { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf() }, credentials: 'same-origin' });
    } catch (e) {}
    window.location.href = '/login';
}

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
    if (!res.ok && data?.reauth) {
        await signOutAndReturnToLogin();
        throw new ApiError(401, { message: data.message });
    }
    if (!res.ok) {
        const firstError = data?.errors ? Object.values(data.errors)[0]?.[0] : null;
        throw new ApiError(res.status, { ...(data ?? {}), message: firstError ?? data?.message ?? `HTTP ${res.status}` });
    }

    return data;
}
