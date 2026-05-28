const looksLikeRecord = (item) => (
    item &&
    typeof item === 'object' &&
    !Array.isArray(item) &&
    (
        'id' in item ||
        'name' in item ||
        'phone' in item ||
        'balance' in item ||
        'role' in item
    )
);

const looksLikeCollection = (items) => (
    Array.isArray(items) &&
    items.length > 0 &&
    items.every((item) => item && typeof item === 'object') &&
    items.some(looksLikeRecord)
);

export const normalizeCollection = (value, visited = new WeakSet()) => {
    if (Array.isArray(value)) return value;
    if (!value || typeof value !== 'object') return [];
    if (visited.has(value)) return [];
    visited.add(value);

    const directKeys = [
        'supervisors',
        'staff',
        'users',
        'data',
        'items',
        'results',
        'records',
        'list',
    ];

    for (const key of directKeys) {
        const candidate = value[key];
        if (looksLikeCollection(candidate)) return candidate;

        const nested = normalizeCollection(candidate, visited);
        if (nested.length > 0) return nested;
    }

    const values = Object.values(value);

    if (
        values.length > 0 &&
        values.every((item) => item && typeof item === 'object' && !Array.isArray(item)) &&
        values.some(looksLikeRecord)
    ) {
        return values;
    }

    for (const candidate of values) {
        const nested = normalizeCollection(candidate, visited);
        if (nested.length > 0) return nested;
    }

    return [];
};

export const normalizeSupervisors = (payload) => normalizeCollection(payload);
