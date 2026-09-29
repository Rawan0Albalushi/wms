import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import { api } from './api';

const AuthContext = createContext(null);

export function AuthProvider({ children }) {
    const [user, setUser] = useState(null);
    const [ready, setReady] = useState(false);

    useEffect(() => {
        let live = true;

        api('/me')
            .then((next) => {
                if (live) {
                    setUser(next);
                }
            })
            .catch(() => {
                if (live) {
                    setUser(null);
                }
            })
            .finally(() => {
                if (live) {
                    setReady(true);
                }
            });

        const onUnauthorized = () => setUser(null);
        window.addEventListener('wms:unauthorized', onUnauthorized);

        return () => {
            live = false;
            window.removeEventListener('wms:unauthorized', onUnauthorized);
        };
    }, []);

    const login = useCallback(async (body) => {
        const next = await api('/login', { method: 'POST', body });
        setUser(next);

        return next;
    }, []);

    const logout = useCallback(async () => {
        await api('/logout', { method: 'POST', body: {} });
        setUser(null);
    }, []);

    const can = useCallback((permission) => {
        const names = Array.isArray(permission) ? permission : [permission];

        return names.some((name) => user?.permissions?.includes(name));
    }, [user]);

    const value = useMemo(() => ({ user, ready, login, logout, can }), [user, ready, login, logout, can]);

    return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth() {
    const context = useContext(AuthContext);

    if (!context) {
        throw new Error('AuthProvider is missing');
    }

    return context;
}
