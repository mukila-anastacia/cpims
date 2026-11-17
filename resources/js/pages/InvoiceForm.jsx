import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { api } from '../api';
import { kes } from '../format';

export default function InvoiceForm() {
    const navigate = useNavigate();
    const [orders, setOrders] = useState(null);
    const [poId, setPoId] = useState('');
    const [no, setNo] = useState('');
    const [date, setDate] = useState(new Date().toISOString().slice(0, 10));
    const [rows, setRows] = useState({});
    const [errors, setErrors] = useState({});
    const [message, setMessage] = useState('');
    const [busy, setBusy] = useState(false);

    useEffect(() => { api('invoice-orders').then(d => setOrders(d.data)).catch(e => setMessage(e.message)); }, []);

    const order = orders?.find(o => String(o.id) === poId);
    const err = (k) => errors[k]?.[0];

    function choose(v) {
        setPoId(v); setErrors({}); setMessage('');
        const o = orders.find(x => String(x.id) === v);
        const r = {};
        o?.lines.forEach(l => { r[l.po_line_id] = { qty: l.to_invoice, price: l.unit_price }; });
        setRows(r);
    }
    const setRow = (id, patch) => setRows(r => ({ ...r, [id]: { ...r[id], ...patch } }));
    const total = order ? order.lines.reduce((s, l) => s + (Number(rows[l.po_line_id]?.qty) || 0) * (Number(rows[l.po_line_id]?.price) || 0), 0) : 0;

    async function submit(e) {
        e.preventDefault();
        setBusy(true); setErrors({}); setMessage('');
        const body = {
            purchase_order_id: poId || null,
            invoice_no: no,
            invoice_date: date,
            lines: (order?.lines ?? []).filter(l => Number(rows[l.po_line_id]?.qty) > 0)
                .map(l => ({ po_line_id: l.po_line_id, quantity: rows[l.po_line_id].qty, unit_price: rows[l.po_line_id].price })),
        };
        try {
            const d = await api('invoices', { method: 'POST', body });
            navigate(`/invoices/${d.data.id}`, { state: { flash: d.data.match_status === 'matched'
                ? `Invoice ${d.data.invoice_no} matched and approved for payment.`
                : `Invoice ${d.data.invoice_no} has an exception. Procurement has been notified.` } });
        } catch (e2) {
            setErrors(e2.errors || {});
            const first = Object.values(e2.errors || {})[0]?.[0];
            setMessage(first || e2.message);
        } finally { setBusy(false); }
    }

    return (
        <>
            <nav className="mb-2 small"><Link to="/invoices">Invoices</Link> / New</nav>
            <h2>Enter invoice</h2>

            {orders && orders.length === 0 && (
                <div className="panel text-secondary">No orders are waiting for invoices. Orders appear here once goods have been received.</div>
            )}

            {!orders && !message && <div className="spinner-border text-success" role="status" />}
            {!orders && message && <div className="alert alert-danger">{message}</div>}

            {orders && orders.length > 0 && (
                <form className="panel form-card mb-4" onSubmit={submit} noValidate>
                    {message && <div className="alert alert-danger py-2" role="alert">{message}</div>}
                    {err('lines') && <div className="alert alert-danger py-2">{err('lines')}</div>}

                    <div className="section-title">Invoice details</div>
                    <div className="row g-3">
                        <div className="col-md-5">
                            <label className="form-label" htmlFor="po">Order</label>
                            <select id="po" className={'form-select' + (err('purchase_order_id') ? ' is-invalid' : '')} value={poId} onChange={e => choose(e.target.value)}>
                                <option value="">Choose an order</option>
                                {orders.map(o => <option key={o.id} value={o.id}>{o.po_no} | {o.vendor} | {o.branch}</option>)}
                            </select>
                            <div className="invalid-feedback">{err('purchase_order_id')}</div>
                        </div>
                        <div className="col-md-4">
                            <label className="form-label" htmlFor="no">Vendor's invoice number</label>
                            <input id="no" className={'form-control' + (err('invoice_no') ? ' is-invalid' : '')} value={no} onChange={e => setNo(e.target.value)} />
                            <div className="invalid-feedback">{err('invoice_no')}</div>
                        </div>
                        <div className="col-md-3">
                            <label className="form-label" htmlFor="dt">Invoice date</label>
                            <input id="dt" type="date" max={new Date().toISOString().slice(0, 10)} className={'form-control' + (err('invoice_date') ? ' is-invalid' : '')} value={date} onChange={e => setDate(e.target.value)} />
                            <div className="invalid-feedback">{err('invoice_date')}</div>
                        </div>
                    </div>

                    {order && (
                        <>
                            <hr />
                            <div className="section-title">Items on the invoice</div>
                            <p className="text-secondary small">Copy the quantities and prices exactly as printed on the vendor's invoice. The system compares them with the order and with what was received.</p>
                            <div className="table-responsive">
                                <table className="table table-sm align-middle">
                                    <thead>
                                        <tr><th>Item</th><th className="text-end">Order price</th><th className="text-end">Received</th><th className="text-end">Already invoiced</th><th style={{ width: 130 }}>Invoice qty</th><th style={{ width: 150 }}>Invoice unit price</th><th className="text-end">Amount</th></tr>
                                    </thead>
                                    <tbody>
                                        {order.lines.map(l => (
                                            <tr key={l.po_line_id}>
                                                <td>{l.item}</td>
                                                <td className="text-end">{kes(l.unit_price)}</td>
                                                <td className="text-end">{l.received} {l.unit}</td>
                                                <td className="text-end">{l.invoiced} {l.unit}</td>
                                                <td>{l.to_invoice > 0
                                                    ? <input type="number" min="0" step="any" className="form-control form-control-sm" value={rows[l.po_line_id]?.qty ?? ''} onChange={e => setRow(l.po_line_id, { qty: e.target.value })} aria-label={`Invoice quantity for ${l.item}`} />
                                                    : <span className="text-secondary">Fully invoiced</span>}</td>
                                                <td>{l.to_invoice > 0 && <input type="number" min="0" step="any" className="form-control form-control-sm" value={rows[l.po_line_id]?.price ?? ''} onChange={e => setRow(l.po_line_id, { price: e.target.value })} aria-label={`Invoice unit price for ${l.item}`} />}</td>
                                                <td className="text-end">{kes((Number(rows[l.po_line_id]?.qty) || 0) * (Number(rows[l.po_line_id]?.price) || 0))}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                    <tfoot><tr><td colSpan="6" className="text-end fw-semibold">Invoice total</td><td className="text-end fw-semibold">{kes(total)}</td></tr></tfoot>
                                </table>
                            </div>
                        </>
                    )}

                    <hr />
                    <div className="d-flex justify-content-end gap-2">
                        <Link to="/invoices" className="btn btn-link">Cancel</Link>
                        <button className="btn btn-accent" disabled={busy || !order}>{busy ? <span className="spinner-border spinner-border-sm" /> : 'Record and match'}</button>
                    </div>
                </form>
            )}
        </>
    );
}