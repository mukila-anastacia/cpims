const KEY = 'cpims-theme';

export const getTheme = () => {
    try { return localStorage.getItem(KEY) || 'light'; } catch { return 'light'; }
};

export function applyTheme(theme) {
    document.documentElement.setAttribute('data-bs-theme', theme);
    try { localStorage.setItem(KEY, theme); } catch { /* ignore */ }
}