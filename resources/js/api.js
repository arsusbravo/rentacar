const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content;

export class ApiError extends Error {
    constructor(status, body) {
        super(body?.message ?? `HTTP ${status}`);
        this.status = status;
        this.errors = body?.errors ?? {};
    }
}

async function request(method, url, data) {
    // FormData (file uploads) is sent as multipart; the browser sets the Content-Type with its boundary.
    const isForm = data instanceof FormData;
    const res = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrf(),
            ...(data && !isForm ? { 'Content-Type': 'application/json' } : {}),
        },
        body: data ? (isForm ? data : JSON.stringify(data)) : undefined,
    });

    if (res.status === 401 || res.status === 419) {
        window.location.href = '/login';
    }
    if (!res.ok) {
        throw new ApiError(res.status, await res.json().catch(() => null));
    }

    return res.status === 204 ? null : res.json();
}

export const api = {
    get: (url) => request('GET', url),
    post: (url, data) => request('POST', url, data),
    put: (url, data) => request('PUT', url, data),
    delete: (url) => request('DELETE', url),
};
