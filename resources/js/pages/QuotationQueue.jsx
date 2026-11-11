import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { api } from '../api';
import { fmtDate, kes } from '../format';

export default function QuotationQueue() {
    const navigate = useNavigate();
    const [rows, setRows] = useState(null);
    const [error, setError] = useState('');

    useEffect(() => { api('quotation-queue').then(d => setRows(d.data)).catch(e => setError(e.message)); }, []);

    const state = (r) => {
        if (r.selected) return ['Vendor selected', 'success'];
        if (r.quotations_count >= r.needed) return ['Ready to select', 'info'];
        return ['Needs quotations', 'warning'];
    };

    return (
        <>
            <h2>Quotations</h2>
            <p className="text-secondary mb-4">Approved requests waiting for quotations or for a vendor to be selected.</p>

            {error && <div className="alert alert-danger">{error}</div>}
            {!rows && !error && <div className="spinner-border text-success" role="status" />}
            {rows && rows.length === 0 && <div className="panel text-secondary">No approved requests are waiting. Approved requests appear here.</div>}
            {rows && rows.length > 0 && (
                <div className="panel p-0 overflow-auto">
                    <table className="table table-hover align-middle m-0">
                        <thead><tr><th>Request</th><th>Branch</th><th>Category</th><th className="text-end">Estimate</th><th>Quotations</th><th>Needed by</th><th>Status</th></tr></thead>
                        <tbody>
                            {rows.map(r => {
                                const [label, colour] = state(r);
                                return (
                                    <tr key={r.request_id} role="link" tabIndex={0} style={{ cursor: 'pointer' }}
                                        onClick={() => navigate(`/quotations/${r.request_id}`)}
                                        onKeyDown={e => { if (e.key === 'Enter') navigate(`/quotations/${r.request_id}`); }}>
                                        <td><Link to={`/quotations/${r.request_id}`} className="fw-semibold" onClick={e => e.stopPropagation()}>{r.request_no}</Link></td>
                                        <td>{r.branch}</td>
                                        <td>{r.category}</td>
                                        <td className="text-end">{kes(r.total_estimate)}</td>
                                        <td>{r.quotations_count} of {r.needed} needed</td>
                                        <td>{fmtDate(r.needed_by)}</td>
                                        <td><span className={`badge text-bg-${colour}`}>{label}</span></td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            )}
        </>
    );
}