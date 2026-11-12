import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { api } from '../api';
import { useAuth } from '../AuthContext';
import OrderBadge from '../components/OrderBadge';
import { fmtDate, kes } from '../format';

export default function Orders() {
    const { user } = useAuth();
    const navigate = useNavigate();
    const receiver = user.role === 'Requester';
    const [status, setStatus] = useState(receiver ? 'awaiting' : '');
    const [rows, setRows] = useState(null);
    const [error, setError] = useState('');

    useEffect(() => {
        setRows(null); setError('');
        api('orders' + (status ? `?status=${status}` : '')).then(d => setRows(d.data)).catch(e => setError(e.message));
    }, [status]);

    return (
        <>
            <h2>{receiver ? 'Receipts' : 'Orders'}</h2>
            <p className="text-secondary mb-4">
                {receiver ? 'Orders for your branch. Open one to record a delivery.' : 'Purchase orders and their delivery progress.'}
            </p>

            <div className="mb-3" style={{ maxWidth: 260 }}>
                <select className="form-select" value={status} onChange={e => setStatus(e.target.value)} aria-label="Filter by status">
                    <option value="">All orders</option>
                    <option value="awaiting">Waiting for delivery</option>
                    <option value="issued">Issued</option>
                    <option value="partly_received">Partly received</option>
                    <option value="received">Received</option>
                </select>
            </div>

            {error && <div className="alert alert-danger">{error}</div>}
            {!rows && !error && <div className="spinner-border text-success" role="status" />}
            {rows && rows.length === 0 && <div className="panel text-secondary">No orders to show.</div>}
            {rows && rows.length > 0 && (
                <div className="panel p-0 overflow-auto">
                    <table className="table table-hover align-middle m-0">
                        <thead><tr><th>Order</th><th>Request</th><th>Branch</th><th>Vendor</th><th className="text-end">Total</th><th>Expected</th><th>Status</th></tr></thead>
                        <tbody>
                            {rows.map(o => (
                                <tr key={o.id} role="link" tabIndex={0} style={{ cursor: 'pointer' }}
                                    onClick={() => navigate(`/orders/${o.id}`)}
                                    onKeyDown={e => { if (e.key === 'Enter') navigate(`/orders/${o.id}`); }}>
                                    <td><Link to={`/orders/${o.id}`} className="fw-semibold" onClick={e => e.stopPropagation()}>{o.po_no}</Link></td>
                                    <td>{o.request_no}</td>
                                    <td>{o.branch}</td>
                                    <td>{o.vendor}</td>
                                    <td className="text-end">{kes(o.total)}</td>
                                    <td>{fmtDate(o.expected_delivery)}</td>
                                    <td><OrderBadge status={o.status} /></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </>
    );
}