const csrf = () => document.querySelector('meta[name="csrf-token"]').content;

/** Calls the Laravel API. `quiet` stops a 401 from being treated as an expired session. */
export async function api(path, { method = 'GET', body, quiet = false } = {}) {
    let res;
    try {
        res = await fetch('/api/' + path, {
            method,
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
            body: body ? JSON.stringify(body) : undefined,
        });
    } catch {
        const offline = new Error('Cannot reach the server. Check your connection and try again.');
        offline.status = 0;
        offline.errors = {};
        throw offline;
    }

    const data = await res.json().catch(() => ({}));

    if (res.status === 419) { window.location.reload(); }
    if (res.status === 401 && !quiet) { window.dispatchEvent(new Event('session-expired')); }
    if (!res.ok) {
        const fallback = res.status === 429
            ? 'Too many attempts. Please wait a moment and try again.'
            : res.status >= 500
                ? 'The server had a problem. Try again, and tell the administrator if it keeps happening.'
                : 'Something went wrong. Please try again.';
        const err = new Error(data.message || fallback);
        err.status = res.status;
        err.data = data;
        err.errors = data.errors || {};
        throw err;
    }
    return data;
}