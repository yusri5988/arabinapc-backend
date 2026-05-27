const TOKEN_KEY = 'token';
const USER_KEY = 'user';

let memoryToken = null;

function normalizeToken(token) {
    if (!token || token === 'undefined' || token === 'null') return null;
    return String(token);
}

export function getToken() {
    if (memoryToken) return memoryToken;

    const token = normalizeToken(localStorage.getItem(TOKEN_KEY));

    if (!token) {
        localStorage.removeItem(TOKEN_KEY);
        return null;
    }

    memoryToken = token;
    return token;
}

export function getUser() {
    const savedUser = localStorage.getItem(USER_KEY);

    if (!savedUser) return null;

    try {
        return JSON.parse(savedUser);
    } catch {
        clearAuth();
        return null;
    }
}

export function setAuth(token, user) {
    if (!token || token === 'undefined' || token === 'null' || !user) {
        clearAuth();
        throw new Error('Invalid login response.');
    }

    memoryToken = normalizeToken(token);
    localStorage.setItem(TOKEN_KEY, memoryToken);
    localStorage.setItem(USER_KEY, JSON.stringify(user));
}

export function setStoredUser(user) {
    localStorage.setItem(USER_KEY, JSON.stringify(user));
}

export function clearAuth() {
    memoryToken = null;
    localStorage.removeItem(TOKEN_KEY);
    localStorage.removeItem(USER_KEY);
}

export function hasAuth() {
    return Boolean(getToken() && getUser());
}
