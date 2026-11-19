import { useEffect, useState } from 'react';
import { Link, NavLink, useLocation } from 'react-router-dom';
import { api } from './api';
import { useAuth } from './AuthContext';
import { fmtDate } from './format';
import { MENUS } from './menu';

function BellIcon() {
    return (
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
            <path d="M6 9a6 6 0 1 1 12 0c0 5 2 6.5 2 6.5H4S6 14 6 9Z" />
            <path d="M10 19a2 2 0 0 0 4 0" />
        </svg>
    );
}

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
            <button type="button" className="icon-btn" onClick={toggle} aria-label={`Notifications, ${notes.unread} unread`} aria-expanded={open}>
                <BellIcon />
                {notes.unread > 0 && <span className="count">{notes.unread}</span>}
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

function MenuIcon() {
    return (
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" aria-hidden="true">
            <path d="M4 7h16M4 12h16M4 17h16" />
        </svg>
    );
}

const initials = (name) => name.split(' ').filter(Boolean).slice(0, 2).map(w => w[0]).join('').toUpperCase();
const isPhone = () => window.matchMedia('(max-width: 860px)').matches;
const KEY = 'cpims-sidebar';
const saved = () => { try { return localStorage.getItem(KEY) !== 'closed'; } catch { return true; } };

export default function Layout({ children }) {
    const { user, logout } = useAuth();
    const { pathname } = useLocation();
    const [open, setOpen] = useState(() => (isPhone() ? false : saved()));
    const sections = MENUS[user.role] ?? [{ label: 'Workspace', items: [{ label: 'Dashboard', to: '/' }] }];
    const today = new Date().toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });

    useEffect(() => { if (isPhone()) setOpen(false); }, [pathname]);

    function toggle() {
        const next = !open;
        setOpen(next);
        if (!isPhone()) {
            try { localStorage.setItem(KEY, next ? 'open' : 'closed'); } catch { /* storage unavailable */ }
        }
    }

    return (
        <div className={'app' + (open ? ' menu-open' : '')}>
            <aside id="main-menu" className="sidebar" aria-label="Main menu" aria-hidden={!open}>
                <div className="side-brand">
                    <div className="side-name">A-Solar Power Works</div>
                    <div className="side-sub">Procurement</div>
                </div>

                {sections.map(s => (
                    <div key={s.label} className="nav-group">
                        <div className="nav-label">{s.label}</div>
                        {s.items.map(({ label, to }) => (
                            <NavLink key={label} to={to} end tabIndex={open ? 0 : -1} className={({ isActive }) => 'item' + (isActive ? ' active' : '')}>
                                <span>{label}</span>
                            </NavLink>
                        ))}
                    </div>
                ))}

                <div className="side-foot">
                    <Link to="/account" className="user-block" tabIndex={open ? 0 : -1} aria-label="Your account">
                        <span className="avatar" aria-hidden="true">{initials(user.name)}</span>
                        <div className="user-text">
                            <div className="user-name">{user.name}</div>
                            <div className="user-role">{user.role}</div>
                        </div>
                    </Link>
                    <button type="button" className="item logout" tabIndex={open ? 0 : -1} onClick={logout}>Log out</button>
                </div>
            </aside>
            {open && <div className="scrim" onClick={() => setOpen(false)} />}

            <div className="main">
                <header className="topbar">
                    <div className="d-flex align-items-center gap-3">
                        <button type="button" className="icon-btn" onClick={toggle} aria-expanded={open} aria-controls="main-menu" aria-label={open ? 'Hide menu' : 'Show menu'}>
                            <MenuIcon />
                        </button>
                        <span className="today">{today}</span>
                    </div>
                    <Bell />
                </header>
                <main className="content">{children}</main>
            </div>
        </div>
    );
}