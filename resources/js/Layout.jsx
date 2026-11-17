import { useEffect, useState } from 'react';
import { Link, NavLink, useLocation } from 'react-router-dom';
import { api } from './api';
import { useAuth } from './AuthContext';
import { fmtDate } from './format';
import { MENUS } from './menu';

function Bell() {
    const [open, setOpen] = useState(false);
    const [notes, setNotes] = useState({ unread: 0, data: [] });

    const load = () => api('notifications', { quiet: true }).then(setNotes).catch(() => {});
    useEffect(() => {
        load();
        const t = setInterval(load, 30000);
        return () => clearInterval(t);
    }, []);

    function toggle() {
        const next = !open;
        setOpen(next);
        if (next && notes.unread > 0) {
            api('notifications/read', { method: 'POST' }).then(() => setNotes(n => ({ ...n, unread: 0 }))).catch(() => {});
        }
    }

    return (
        <div className="bell">
            <button className="btn btn-sm btn-outline-light" onClick={toggle} aria-label="Notifications">
                Notifications {notes.unread > 0 && <span className="badge text-bg-warning ms-1">{notes.unread}</span>}
            </button>
            {open && (
                <div className="notes-panel">
                    <div className="fw-semibold px-3 py-2 border-bottom">Notifications</div>
                    {notes.data.length === 0 && <div className="px-3 py-3 text-secondary small">Nothing yet.</div>}
                    {notes.data.map(n => (
                        <Link key={n.id} to={n.link || '/'} onClick={() => setOpen(false)} className={'note' + (n.is_read ? '' : ' unread')}>
                            <div>{n.message}</div>
                            <small className="text-secondary">{fmtDate(n.created_at)}</small>
                        </Link>
                    ))}
                </div>
            )}
        </div>
    );
}

export default function Layout({ children }) {
    const { user, logout } = useAuth();
    const [menuOpen, setMenuOpen] = useState(false);
    const { pathname } = useLocation();
    const items = MENUS[user.role] ?? [{ label: 'Dashboard', to: '/' }];

    useEffect(() => { setMenuOpen(false); }, [pathname]);

    return (
        <>
            <header className="topbar">
                <div className="brand">
                    <span className="brand-name">A-Solar Power Works</span>
                    <span className="brand-sub">Procurement System</span>
                </div>
                <div className="d-flex align-items-center gap-2">
                    <button type="button" className="btn btn-sm btn-outline-light menu-toggle" onClick={() => setMenuOpen(!menuOpen)} aria-expanded={menuOpen} aria-controls="main-menu">
                        {menuOpen ? 'Close menu' : 'Menu'}
                    </button>
                    <Bell />
                </div>
            </header>
            <div className="shell">
                <nav id="main-menu" className={'sidebar' + (menuOpen ? ' open' : '')} aria-label="Main menu">
                    {items.map(({ label, to }) => (
                        <NavLink key={label} to={to} end className={({ isActive }) => 'item' + (isActive ? ' active' : '')}>
                            <span>{label}</span>
                        </NavLink>
                    ))}
                    <div className="nav-divider" />
                    <button type="button" className="item logout" onClick={logout}>Log out</button>
                </nav>
                <main className="content">{children}</main>
            </div>
        </>
    );
}