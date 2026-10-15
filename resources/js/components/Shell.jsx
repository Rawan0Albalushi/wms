import { useEffect, useState } from 'react';
import { NavLink, useLocation } from 'react-router-dom';
import { navigation } from '../access';
import { api } from '../api';
import { useAuth } from '../auth';
import { useLocale } from '../i18n';
import Icon from './Icons';

export default function Shell({ children }) {
    const { t, toggle, locale } = useLocale();
    const { user, can, logout } = useAuth();
    const location = useLocation();
    const [alerts, setAlerts] = useState(0);
    const [menu, setMenu] = useState(false);
    const seeAlerts = can('alerts.view');

    useEffect(() => {
        if (!seeAlerts) {
            setAlerts(0);

            return undefined;
        }

        let live = true;
        const load = () => {
            api('/alerts/count')
                .then((row) => {
                    if (live) {
                        setAlerts(Number(row.count) || 0);
                    }
                })
                .catch(() => {});
        };

        load();
        window.addEventListener('wms:refresh', load);

        return () => {
            live = false;
            window.removeEventListener('wms:refresh', load);
        };
    }, [seeAlerts]);

    useEffect(() => {
        setMenu(false);
    }, [location.pathname]);

    useEffect(() => {
        if (!menu) {
            return undefined;
        }

        const onKey = (event) => {
            if (event.key === 'Escape') {
                setMenu(false);
            }
        };
        const previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        window.addEventListener('keydown', onKey);

        return () => {
            document.body.style.overflow = previousOverflow;
            window.removeEventListener('keydown', onKey);
        };
    }, [menu]);

    const today = new Intl.DateTimeFormat(locale === 'ar' ? 'ar' : 'en-GB', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
    }).format(new Date());

    const visible = navigation
        .map(([group, items]) => [group, items.filter((item) => can(item[3]))])
        .filter(([, items]) => items.length > 0);

    const current = visible
        .flatMap(([, items]) => items)
        .find(([to]) => (to === '/' ? location.pathname === '/' : location.pathname === to || location.pathname.startsWith(`${to}/`)));

    return (
        <>
            <a className="skip" href="#content">{t('app.skip')}</a>
            <div id="shell" className="app">
                {menu ? <button type="button" className="scrim" aria-label={t('close')} onClick={() => setMenu(false)} /> : null}
                <aside id="sidenav" className={menu ? 'side open' : 'side'}>
                    <NavLink to={visible[0]?.[1]?.[0]?.[0] || '/'} className="brand" end>
                        <span className="brand-mark" aria-hidden="true">W</span>
                        <span className="brand-copy">
                            <strong>WMS</strong>
                            <span>{t('app.system')}</span>
                        </span>
                    </NavLink>
                    <nav className="modules" aria-label={t('app.system')}>
                        {visible.map(([group, items]) => (
                            <div className="nav-group" key={group}>
                                <div className="nav-label" id={group}>{t(group)}</div>
                                <div role="group" aria-labelledby={group}>
                                    {items.map(([to, key, icon]) => (
                                        <NavLink
                                            key={to}
                                            to={to}
                                            end={to === '/'}
                                            className={({ isActive }) => (isActive ? 'module active' : 'module')}
                                        >
                                            <Icon name={icon} />
                                            <span>{t(key)}</span>
                                            {to === '/alerts' && alerts > 0 ? <span className="nav-count">{alerts}</span> : null}
                                        </NavLink>
                                    ))}
                                </div>
                            </div>
                        ))}
                    </nav>
                </aside>
                <div className="workspace">
                    <header className="topbar">
                        <button
                            type="button"
                            className="menu-btn"
                            aria-expanded={menu}
                            aria-controls="sidenav"
                            onClick={() => setMenu((open) => !open)}
                        >
                            <Icon name="menu" />
                            <span className="menu-label">{t('app.menu')}</span>
                        </button>
                        <div className="where">
                            <strong>{current ? t(current[1]) : t('app.warehouse')}</strong>
                            <span className="mono">{today}</span>
                        </div>
                        <div className="tools">
                            <span className="actor-name">{user?.name}</span>
                            {seeAlerts ? (
                                <NavLink to="/alerts" className="bell" aria-label={t('nav.alerts')}>
                                    <Icon name="bell" />
                                    {alerts > 0 ? <span className="badge">{alerts}</span> : null}
                                </NavLink>
                            ) : null}
                            <button type="button" className="lang" onClick={toggle}>{t('app.lang')}</button>
                            <button type="button" className="lang" onClick={logout}>{t('logout')}</button>
                        </div>
                    </header>
                    <main id="content" className="page">{children}</main>
                </div>
            </div>
        </>
    );
}
