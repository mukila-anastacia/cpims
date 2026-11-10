import { useEffect, useState } from 'react';
import { Link, NavLink } from 'react-router-dom';
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
        <div className="position-relative">
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
    const items = MENUS[user.role] ?? [{ label: 'Dashboard', to: '/' }];

    return (
        <>
            <header className="topbar">
                <div className="brand">A-Solar Power Works <small>Procurement System</small></div>
                <div className="d-flex align-items-center gap-3">
                    <Bell />
                    <div className="text-end lh-sm">
                        <div className="fw-semibold">{user.name}</div>
                        <small className="text-white-50">{user.role} | {user.branch}</small>
                    </div>
                    <button className="btn btn-sm btn-outline-light" onClick={logout}>Log out</button>
                </div>
            </header>
            <div className="shell">
                <nav className="sidebar" aria-label="Main menu">
                    {items.map(({ label, to }) => to ? (
                        <NavLink key={label} to={to} end className={({ isActive }) => 'item' + (isActive ? ' active' : '')}>
                            <span>{label}</span>
                        </NavLink>
                    ) : (
                        <div key={label} className="item disabled">
                            <span>{label}</span><span className="soon">Soon</span>
                        </div>
                    ))}
                </nav>
                <main className="content">{children}</main>
            </div>
        </>
    );
}