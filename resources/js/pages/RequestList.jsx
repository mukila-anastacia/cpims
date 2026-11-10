import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { api } from '../api';
import { useAuth } from '../AuthContext';
import StatusBadge from '../components/StatusBadge';
import { STATUS, fmtDate, kes } from '../format';

export default function RequestList() {
    const { user } = useAuth();
    const navigate = useNavigate();
    const [status, setStatus] = useState('');
    const [rows, setRows] = useState(null);
    const [error, setError] = useState('');

    useEffect(() => {
        setRows(null);
        setError('');
        api('requests' + (status ? `?status=${status}` : '')).then(d => setRows(d.data)).catch(e => setError(e.message));
    }, [status]);

    const open = (id) => navigate(`/requests/${id}`);

    return (
        <>
            <div className="d-flex justify-content-between align-items-center mb-3">
                <h2 className="fw-semibold m-0" style={{ color: 'var(--brand)' }}>
                    {user.role === 'Requester' ? 'My requests' : 'Requests'}
                </h2>
                {user.role === 'Requester' && <Link to="/requests/new" className="btn btn-brand">New request</Link>}
            </div>

            <div className="mb-3" style={{ maxWidth: 240 }}>
                <select className="form-select" value={status} onChange={e => setStatus(e.target.value)} aria-label="Filter by status">
                    <option value="">All statuses</option>
                    {Object.entries(STATUS).map(([k, [label]]) => <option key={k} value={k}>{label}</option>)}
                </select>
            </div>

            {error && <div className="alert alert-danger">{error}</div>}
            {!rows && !error && <div className="spinner-border text-success" role="status" />}
            {rows && rows.length === 0 && (
                <div className="panel text-secondary">No requests to show yet.</div>
            )}
            {rows && rows.length > 0 && (
                <div className="panel p-0 overflow-auto">
                    <table className="table table-hover align-middle m-0">
                        <thead>
                            <tr><th>No.</th><th>Category</th><th>Branch</th><th>Requested by</th><th className="text-end">Estimate</th><th>Needed by</th><th>Status</th></tr>
                        </thead>
                        <tbody>
                            {rows.map(r => (
                                <tr key={r.id} role="link" tabIndex={0} style={{ cursor: 'pointer' }}
                                    onClick={() => open(r.id)}
                                    onKeyDown={e => { if (e.key === 'Enter') open(r.id); }}>
                                    <td><Link to={`/requests/${r.id}`} className="fw-semibold" onClick={e => e.stopPropagation()}>{r.request_no}</Link></td>
                                    <td>{r.category}</td>
                                    <td>{r.branch}</td>
                                    <td>{r.requester}</td>
                                    <td className="text-end">{kes(r.total_estimate)}</td>
                                    <td>{fmtDate(r.needed_by)}</td>
                                    <td><StatusBadge status={r.status} /></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </>
    );
}