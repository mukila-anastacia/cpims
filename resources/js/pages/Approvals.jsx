import { useEffect, useState } from 'react';
import { Link, useLocation } from 'react-router-dom';
import { api } from '../api';
import { fmtDate, kes } from '../format';

export default function Approvals() {
    const flash = useLocation().state?.flash;
    const [rows, setRows] = useState(null);
    const [error, setError] = useState('');

    useEffect(() => {
        api('approvals').then(d => setRows(d.data)).catch(e => setError(e.message));
    }, []);

    return (
        <>
            <h2 className="fw-semibold mb-1" style={{ color: 'var(--brand)' }}>Approvals</h2>
            <p className="text-secondary mb-4">Requests waiting for your decision.</p>

            {flash && <div className="alert alert-success py-2">{flash}</div>}
            {error && <div className="alert alert-danger">{error}</div>}
            {!rows && !error && <div className="spinner-border text-success" role="status" />}
            {rows && rows.length === 0 && <div className="panel text-secondary">Nothing is waiting for you. New requests will appear here.</div>}
            {rows && rows.length > 0 && (
                <div className="panel p-0 overflow-auto">
                    <table className="table table-hover align-middle m-0">
                        <thead><tr><th>Request</th><th>Branch</th><th>Requested by</th><th>Category</th><th className="text-end">Estimate</th><th>Submitted</th><th /></tr></thead>
                        <tbody>
                            {rows.map(r => (
                                <tr key={r.request_id}>
                                    <td>
                                        <Link to={`/requests/${r.request_id}`} className="fw-semibold">{r.request_no}</Link>
                                        {r.duplicate_flag && <span className="badge text-bg-warning ms-2">Duplicate flag</span>}
                                        {r.budget_exception && <span className="badge text-bg-info ms-2">Over budget</span>}
                                    </td>
                                    <td>{r.branch}</td>
                                    <td>{r.requester}</td>
                                    <td>{r.category}</td>
                                    <td className="text-end">{kes(r.total_estimate)}</td>
                                    <td>{fmtDate(r.submitted_at)}</td>
                                    <td className="text-end"><Link to={`/requests/${r.request_id}`} className="btn btn-sm btn-brand">Review</Link></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </>
    );
}