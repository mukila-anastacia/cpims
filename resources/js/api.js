const csrf = () => document.querySelector('meta[name="csrf-token"]').content;

/** Calls the Laravel API. `quiet` stops a 401 from being treated as an expired session. */
export async function api(path, { method = 'GET', body, quiet = false } = {}) {
    const res = await fetch('/api/' + path, {
        method,
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
        body: body ? JSON.stringify(body) : undefined,
    });
    const data = await res.json().catch(() => ({}));

    if (res.status === 419) { window.location.reload(); }
    if (res.status === 401 && !quiet) { window.dispatchEvent(new Event('session-expired')); }
    if (!res.ok) {
        const err = new Error(data.message || 'Something went wrong. Please try again.');
        err.status = res.status;
        err.data = data;
        err.errors = data.errors || {};
        throw err;
    }
    return data;
}