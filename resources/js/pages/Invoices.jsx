import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { api } from '../api';
import { useAuth } from '../AuthContext';
import InvoiceBadge from '../components/InvoiceBadge';
import { fmtDate, kes } from '../format';

export default function Invoices() {
    const { user } = useAuth();
    const navigate = useNavigate();
    const canEnter = user.role === 'Finance Officer';
    const [status, setStatus] = useState(user.role === 'Procurement Administrator' ? 'exception' : '');
    const [rows, setRows] = useState(null);
    const [error, setError] = useState('');

    useEffect(() => {
        setRows(null); setError('');
        api('invoices' + (status ? `?status=${status}` : '')).then(d => setRows(d.data)).catch(e => setError(e.message));
    }, [status]);

    return (
        <>
            <div className="d-flex justify-content-between align-items-center mb-1">
                <h2 className="m-0">Invoices</h2>
                {canEnter && <Link to="/invoices/new" className="btn btn-brand">Enter invoice</Link>}
            </div>
            <p className="text-secondary mb-4">Supplier invoices checked against orders and deliveries.</p>

            <div className="mb-3" style={{ maxWidth: 260 }}>
                <select className="form-select" value={status} onChange={e => setStatus(e.target.value)} aria-label="Filter by status">
                    <option value="">All invoices</option>
                    <option value="exception">Exceptions (need review)</option>
                    <option value="matched">Matched</option>
                    <option value="resolved">Resolved</option>
                </select>
            </div>

            {error && <div className="alert alert-danger">{error}</div>}
            {!rows && !error && <div className="spinner-border text-success" role="status" />}
            {rows && rows.length === 0 && <div className="panel text-secondary">No invoices to show.</div>}
            {rows && rows.length > 0 && (
                <div className="panel p-0 overflow-auto">
                    <table className="table table-hover align-middle m-0">
                        <thead><tr><th>Invoice</th><th>Order</th><th>Vendor</th><th>Branch</th><th>Date</th><th className="text-end">Total</th><th>Status</th></tr></thead>
                        <tbody>
                            {rows.map(i => (
                                <tr key={i.id} role="link" tabIndex={0} style={{ cursor: 'pointer' }}
                                    onClick={() => navigate(`/invoices/${i.id}`)}
                                    onKeyDown={e => { if (e.key === 'Enter') navigate(`/invoices/${i.id}`); }}>
                                    <td><Link to={`/invoices/${i.id}`} className="fw-semibold" onClick={e => e.stopPropagation()}>{i.invoice_no}</Link></td>
                                    <td>{i.po_no}</td>
                                    <td>{i.vendor}</td>
                                    <td>{i.branch}</td>
                                    <td>{fmtDate(i.invoice_date)}</td>
                                    <td className="text-end">{kes(i.total)}</td>
                                    <td><InvoiceBadge status={i.match_status} /></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </>
    );
}