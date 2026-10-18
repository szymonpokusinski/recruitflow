export class ApiError extends Error {
    readonly errors: Record<string, string[]>;
    constructor(message: string, errors: Record<string, string[]> = {}) {
        super(message);
        this.errors = errors;
    }
}
export async function api<T>(
    path: string,
    method = 'GET',
    data?: unknown,
): Promise<T> {
    const token = document.cookie
        .split('; ')
        .find((value) => value.startsWith('XSRF-TOKEN='))
        ?.slice(11);
    const headers: Record<string, string> = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    };
    if (token) headers['X-XSRF-TOKEN'] = decodeURIComponent(token);
    const multipart = data instanceof FormData;
    if (data !== undefined && !multipart)
        headers['Content-Type'] = 'application/json';
    let response: Response;
    try {
        response = await fetch(path, {
            method,
            credentials: 'same-origin',
            headers,
            body:
                data === undefined
                    ? undefined
                    : multipart
                      ? data
                      : JSON.stringify(data),
        });
    } catch {
        throw new Error(
            'Brak połączenia z lokalnym backendem. Spróbuj ponownie.',
        );
    }
    const result = (await response.json().catch(() => null)) as {
        message?: string;
        errors?: Record<string, string[]>;
    } | null;
    if (!response.ok)
        throw new ApiError(
            result?.errors
                ? Object.values(result.errors).flat().join(' ')
                : (result?.message ?? 'Nie udało się wykonać operacji.'),
            result?.errors,
        );
    return result as T;
}
