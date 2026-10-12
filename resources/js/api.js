function xsrfToken() {
    const row = document.cookie.split('; ').find((part) => part.startsWith('XSRF-TOKEN='));

    return row ? decodeURIComponent(row.slice('XSRF-TOKEN='.length)) : '';
}

export async function api(path, { method = 'GET', body } = {}) {
    const headers = {
        Accept: 'application/json',
    };

    if (body !== undefined) {
        headers['Content-Type'] = 'application/json';
    }

    if (method !== 'GET' && method !== 'HEAD') {
        const token = xsrfToken();

        if (token) {
            headers['X-XSRF-TOKEN'] = token;
        }
    }

    const response = await fetch(`/api${path}`, {
        method,
        headers,
        credentials: 'same-origin',
        body: body === undefined ? undefined : JSON.stringify(body),
    });

    const data = await response.json().catch(() => ({}));

    if (!response.ok) {
        const error = new Error(data.message || 'failed');
        error.status = response.status;
        error.errors = data.errors || {};

        if (response.status === 401 && path !== '/login') {
            window.dispatchEvent(new Event('wms:unauthorized'));
        }

        throw error;
    }

    return data;
}

export function readForm(form) {
    const data = {};

    new FormData(form).forEach((value, key) => {
        if (value instanceof File || key === 'remove_image') {
            return;
        }

        if (typeof value === 'string' && value.trim() === '') {
            return;
        }

        data[key] = value;
    });

    return data;
}

export function errorText(error, t) {
    if (error?.status === 403) {
        return t('errors.forbidden');
    }

    if (error?.status === 401 || error?.status === 419) {
        return t('errors.session');
    }

    if (error?.status === 429) {
        return t('errors.throttle');
    }

    const first = Object.values(error?.errors || {}).flat()[0];
    const raw = typeof first === 'string' ? first : '';
    const key = `errors.${raw}`;
    const translated = t(key);

    if (translated !== key) {
        return translated;
    }

    if (/taken|already|duplicate/i.test(raw)) {
        return t('errors.duplicate');
    }

    if (/required/i.test(raw)) {
        return t('errors.required');
    }

    return t('errors.generic');
}

export async function uploadImage(path, file) {
    const body = new FormData();
    body.append('image', file);
    const headers = { Accept: 'application/json' };
    const token = xsrfToken();

    if (token) {
        headers['X-XSRF-TOKEN'] = token;
    }

    const response = await fetch(`/api${path}`, {
        method: 'POST',
        headers,
        credentials: 'same-origin',
        body,
    });
    const data = await response.json().catch(() => ({}));

    if (!response.ok) {
        const error = new Error(data.message || 'failed');
        error.status = response.status;
        error.errors = data.errors || {};

        if (response.status === 401) {
            window.dispatchEvent(new Event('wms:unauthorized'));
        }

        throw error;
    }

    return data;
}

export async function syncImage(form, path) {
    const file = form.elements.namedItem('image');
    const chosen = file instanceof HTMLInputElement ? file.files?.[0] : null;

    if (chosen) {
        await uploadImage(path, chosen);

        return;
    }

    const remove = form.elements.namedItem('remove_image');

    if (remove instanceof HTMLInputElement && remove.checked) {
        await api(path, { method: 'DELETE' });
    }
}

export function ping() {
    window.dispatchEvent(new Event('wms:refresh'));
}

export function formatQty(value) {
    return new Intl.NumberFormat('en-US').format(value ?? 0);
}

export function formatDate(value) {
    if (!value) {
        return '—';
    }

    const [year, month, day] = String(value).slice(0, 10).split('-');

    if (!year || !month || !day) {
        return '—';
    }

    return `${day}/${month}/${year}`;
}

export function jobAction(job) {
    if (!job || job.status === 'closed') {
        return null;
    }

    const reserved = job.lines.reduce((sum, line) => sum + Number(line.reserved_qty), 0);
    const gap = job.lines.reduce((sum, line) => sum + Math.max(0, line.required_qty - line.reserved_qty - line.issued_qty), 0);

    if (reserved > 0) {
        return 'issue';
    }

    if (gap > 0) {
        return 'reserve';
    }

    return null;
}

export function label(row, locale, ar = 'name_ar', en = 'name_en') {
    if (!row) {
        return '';
    }

    return locale === 'ar' ? row[ar] : row[en];
}

export function matchesText(query, values) {
    const needle = query.trim().toLowerCase();

    if (!needle) {
        return true;
    }

    return values.some((value) => String(value ?? '').toLowerCase().includes(needle));
}
