import { createContext, useContext, useEffect, useState } from 'react';
import { api } from './api';

const Ctx = createContext(null);
export const useAuth = () => useContext(Ctx);

export function AuthProvider({ children }) {
    const [user, setUser] = useState(null);
    const [loading, setLoading] = useState(true);
    const [notice, setNotice] = useState('');

    useEffect(() => {
        api('me', { quiet: true }).then(d => setUser(d.user)).catch(() => setUser(null)).finally(() => setLoading(false));
        const expired = () => { setUser(null); setNotice('Your session ended. Please log in again.'); };
        window.addEventListener('session-expired', expired);
        return () => window.removeEventListener('session-expired', expired);
    }, []);

    const login = async (username, password) => {
        const d = await api('login', { method: 'POST', body: { username, password }, quiet: true });
        setNotice('');
        setUser(d.user);
    };

    const logout = async () => {
        try { await api('logout', { method: 'POST' }); }
        finally { window.location.assign('/login'); } // reload so the page gets a fresh CSRF token
    };

    return <Ctx.Provider value={{ user, loading, login, logout, notice }}>{children}</Ctx.Provider>;
}