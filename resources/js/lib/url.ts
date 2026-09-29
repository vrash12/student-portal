/** Appends a query string of the non-empty values. */
export function withQuery(path: string, query: Record<string, string> = {}): string {
    const search = new URLSearchParams(Object.entries(query).filter(([, value]) => value !== '')).toString();

    return search === '' ? path : `${path}?${search}`;
}
