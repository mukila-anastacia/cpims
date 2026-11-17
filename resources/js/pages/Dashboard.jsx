import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { api } from '../api';
import { useAuth } from '../AuthContext';
import StatusBadge from '../components/StatusBadge';
import { kes } from '../format';
import { TASKS } from '../menu';

const greeting = () => {
    const h = new Date().getHours();
    return h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : 'Good evening';
};

export default function Dashboard() {
    const { user } = useAuth();
    const navigate = useNavigate();
    const showRecent = user.role !== 'System Administrator';
    const [cards, setCards] = useState(null);
    const [recent, setRecent] = useState(null);
    const [error, setError] = useState('');

    const load = () => {
        setError('');
        setCards(null);
        setRecent(null);
        api('dashboard').then(d => setCards(d.cards)).catch(e => setError(e.message));
        if (showRecent) api('requests').then(d => setRecent(d.data.slice(0, 6))).catch(() => setRecent([]));
    };
    useEffect(() => { load(); }, []);

    return (
        <>
            <div className="page-head">
                <div>
                    <h2>{greeting()}, {user.name}</h2>
                    <p className="text-secondary m-0">{user.role} at {user.branch}</p>
                </div>
            </div>

            {error && (
                <div className="alert alert-danger d-flex justify-content-between align-items-center mb-0">
                    <span>{error}</span>
                    <button className="btn btn-sm btn-outline-secondary" onClick={load}>Try again</button>
                </div>
            )}
            {!cards && !error && <div className="spinner-border text-success" role="status" />}

            {cards && (
                <div className="stat-row">
                    {cards.map(c => (
                        <Link key={c.key} to={c.link} className="panel stat-card">
                            <div className="label">{c.label}</div>
                            <div className={'stat-value' + (c.value > 0 && /exception|waiting|returned|over/i.test(c.label) ? ' attention' : '')}>{c.value}</div>
                        </Link>
                    ))}
                </div>
            )}

            <div className="row g-3">
                {showRecent && (
                    <div className="col-lg-8">
                        <div className="panel p-0">
                            <div className="panel-head">
                                <h5 className="m-0">Recent purchase requests</h5>
                                <Link to="/requests">View all</Link>
                            </div>
                            {!recent && <div className="p-4"><div className="spinner-border spinner-border-sm text-success" role="status" /></div>}
                            {recent && recent.length === 0 && <div className="p-4 text-secondary">No requests yet.</div>}
                            {recent && recent.length > 0 && (
                                <div className="table-responsive">
                                    <table className="table align-middle m-0">
                                        <thead><tr><th>Request</th><th>Category</th><th>Requested by</th><th className="text-end">Estimate</th><th>Status</th></tr></thead>
                                        <tbody>
                                            {recent.map(r => (
                                                <tr key={r.id} role="link" tabIndex={0} style={{ cursor: 'pointer' }}
                                                    onClick={() => navigate(`/requests/${r.id}`)}
                                                    onKeyDown={e => { if (e.key === 'Enter') navigate(`/requests/${r.id}`); }}>
                                                    <td className="fw-semibold">{r.request_no}</td>
                                                    <td>{r.category}</td>
                                                    <td>{r.requester}</td>
                                                    <td className="text-end">{kes(r.total_estimate)}</td>
                                                    <td><StatusBadge status={r.status} /></td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </div>
                    </div>
                )}
                <div className={showRecent ? 'col-lg-4' : 'col-12'}>
                    <div className="panel">
                        <h5 className="mb-3">What you will do here</h5>
                        <div className="tasks">
                            {(TASKS[user.role] ?? []).map(t => <div key={t} className="task">{t}</div>)}
                        </div>
                    </div>
                </div>
            </div>
        </>
    );
}