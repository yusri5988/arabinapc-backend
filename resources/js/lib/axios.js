import axios from 'axios';
import { clearAuth, getToken } from './authStorage';

const api = axios.create({
    baseURL: '/api',
    headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
    }
});

const applyAuthHeader = (config, token = getToken()) => {
    config.headers = config.headers || {};

    if (token) {
        config.headers.Authorization = `Bearer ${token}`;
        api.defaults.headers.common.Authorization = `Bearer ${token}`;
    } else {
        delete config.headers.Authorization;
        delete api.defaults.headers.common.Authorization;
    }

    return config;
};

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

api.interceptors.request.use((config) => applyAuthHeader(config));

api.interceptors.response.use(
    (response) => response,
    async (error) => {
        const originalRequest = error.config;
        const status = error.response?.status;
        const token = getToken();
        const isLoginRequest = originalRequest?.url?.includes('/login');

        if (status === 401 && !isLoginRequest) {
            if (token && originalRequest && !originalRequest._authRetry) {
                originalRequest._authRetry = true;
                applyAuthHeader(originalRequest, token);
                await wait(400);
                return api(originalRequest);
            }

            clearAuth();
            delete api.defaults.headers.common.Authorization;
            window.dispatchEvent(new Event('auth:unauthorized'));
        }

        return Promise.reject(error);
    }
);

export default api;
