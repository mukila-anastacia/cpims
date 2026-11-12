import { useEffect, useState } from 'react';
import { Link, useLocation, useParams } from 'react-router-dom';
import { api } from '../api';
import OrderBadge from '../components/OrderBadge';
import { fmtDate, kes } from '../format';

const COND = { good: 'Good', damaged: 'Damaged', short: 'Short' };

export default function OrderDetail() {
    const { id } = useParams();
    const navFlash = useLocation().state?.flash;
    const [o, setO] = useState(null);
    const [error, setError] = useState('');
    const [flash, setFlash] = useState(navFlash || '');
    const [rows, setRows] = useState({});
    const [note, setNote] = useState('');
    const [why, setWhy] = useState('');
    const [errs, setErrs] = useState({});
    const [msg, setMsg] = useState('');
    const [busy, setBusy] = useState(false);

    function apply(d) {
        setO(d);
        const r = {};
        d.lines.forEach(l => { r[l.id] = { qty: l.remaining, condition: 'good' }; });
        setRows(r);
    }

    useEffect(() => {
        api(`orders/${id}`).then(d => apply(d.data))
            .catch(e => setError(e.status === 404 ? 'This order was not found, or you do not have access to it.' : e.message));
    }, [id]);

    const setRow = (lineId, patch) => setRows(r => ({ ...r, [lineId]: { ...r[lineId], ...patch } }));

    async function receive(e) {
        e.preventDefault();
        setBusy(true); setErrs({}); setMsg('');
        const body = {
            delivery_note_no: note || null,
            override_reason: why || null,
            lines: o.lines.map(l => ({ po_line_id: l.id, qty_received: rows[l.id]?.qty || 0, item_condition: rows[l.id]?.condition || 'good' })),
        };
        try {
            const d = await api(`orders/${id}/receipts`, { method: 'POST', body });
            apply(d.data); setNote(''); setWhy('');
            setFlash('Delivery recorded.');
            window.scrollTo(0, 0);
        } catch (e2) {
            setErrs(e2.errors || {});
            setMsg(e2.errors?.lines?.[0] || e2.message);
        } finally { setBusy(false); }
    }

    if (error) return <div className="alert alert-danger">{error} <Link to="/orders">Back to orders</Link></div>;
    if (!o) return <div className="spinner-border text-success" role="status" />;

    const awaiting = ['issued', 'partly_received'].includes(o.status);

    return (
        <>
            <nav className="mb-2 small no-print"><Link to="/orders">Orders</Link> / {o.po_no}</nav>
            {flash && <div className="alert alert-success py-2 no-print">{flash}</div>}

            <div className="d-flex justify-content-between align-items-start mb-3 no-print">
                <div>
                    <h2 className="mb-1">{o.po_no} <OrderBadge status={o.status} /></h2>
                    <div className="text-secondary">Request <Link to={`/requests/${o.request_id}`}>{o.request_no}</Link> | {o.branch} | {o.vendor.name}</div>
                </div>
                <button className="btn btn-accent" onClick={() => window.print()}>Print / Save as PDF</button>
            </div>

            {/* Card 1: the purchase order document */}
            <div className="panel po-doc mb-4">
                <div className="d-flex justify-content-between align-items-start mb-4">
                    <div>
                        <div className="fw-bold fs-4" style={{ fontFamily: 'var(--font-head)', textTransform: 'uppercase', letterSpacing: '.08em' }}>A-Solar Power Works</div>
                        <div className="text-secondary small">Procurement Department</div>
                    </div>
                    <div className="text-end">
                        <div className="fw-bold fs-4" style={{ fontFamily: 'var(--font-head)', textTransform: 'uppercase', letterSpacing: '.08em' }}>Purchase order</div>
                        <div className="fw-semibold">{o.po_no}</div>
                        <div className="small text-secondary">Date: {fmtDate(o.issued_at)}</div>
                    </div>
                </div>

                <div className="row g-4 mb-4">
                    <div className="col-md-6">
                        <div className="label small text-secondary text-uppercase">Vendor</div>
                        <div className="fw-semibold">{o.vendor.name}</div>
                        <div className="small">KRA PIN: {o.vendor.tax_pin}</div>
                        {o.vendor.contact_person && <div className="small">Attention: {o.vendor.contact_person}</div>}
                        {o.vendor.phone && <div className="small">{o.vendor.phone}</div>}
                        {o.vendor.email && <div className="small">{o.vendor.email}</div>}
                    </div>
                    <div className="col-md-6">
                        <div className="label small text-secondary text-uppercase">Deliver to</div>
                        <div className="fw-semibold">A-Solar Power Works, {o.branch} branch</div>
                        <div className="small">Request: {o.request_no}</div>
                        <div className="small">Expected delivery: {fmtDate(o.expected_delivery)}</div>
                    </div>
                </div>

                <table className="table align-middle">
                    <thead><tr><th>Item</th><th className="text-end">Quantity</th><th className="text-end">Unit price</th><th className="text-end">Amount</th></tr></thead>
                    <tbody>
                        {o.lines.map(l => (
                            <tr key={l.id}>
                                <td>{l.item}</td>
                                <td className="text-end">{l.ordered} {l.unit}</td>
                                <td className="text-end">{kes(l.unit_price)}</td>
                                <td className="text-end">{kes(l.line_total)}</td>
                            </tr>
                        ))}
                    </tbody>
                    <tfoot><tr><td colSpan="3" className="text-end fw-bold">Total</td><td className="text-end fw-bold">{kes(o.total)}</td></tr></tfoot>
                </table>
                <div className="small text-secondary">Issued by {o.issued_by}. Please quote {o.po_no} on your delivery note and invoice.</div>
            </div>

            {/* Card 2: delivery progress, recording and history */}
            <div className="panel form-card mb-4 no-print">
                <div className="section-title">Delivery progress</div>
                <div className="table-responsive">
                    <table className="table align-middle mb-0">
                        <thead><tr><th>Item</th><th className="text-end">Ordered</th><th className="text-end">Received</th><th className="text-end">Still expected</th></tr></thead>
                        <tbody>
                            {o.lines.map(l => (
                                <tr key={l.id}>
                                    <td>{l.item}</td>
                                    <td className="text-end">{l.ordered} {l.unit}</td>
                                    <td className="text-end">{l.received} {l.unit}</td>
                                    <td className="text-end">{l.remaining} {l.unit}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {o.rights.can_receive && (
                    <>
                        <hr />
                        <form onSubmit={receive} noValidate>
                            <div className="section-title">Record a delivery</div>
                            {o.rights.override && (
                                <div className="alert alert-warning py-2">
                                    You are recording this delivery on behalf of the branch. It is logged as an override, and a reason is required.
                                </div>
                            )}
                            {msg && <div className="alert alert-danger py-2">{msg}</div>}
                            <table className="table table-sm align-middle">
                                <thead><tr><th>Item</th><th className="text-end">Still expected</th><th style={{ width: 150 }}>Received now</th><th style={{ width: 160 }}>Condition</th></tr></thead>
                                <tbody>
                                    {o.lines.map(l => (
                                        <tr key={l.id}>
                                            <td>{l.item}</td>
                                            <td className="text-end">{l.remaining} {l.unit}</td>
                                            <td>
                                                {l.remaining > 0
                                                    ? <input type="number" min="0" max={l.remaining} step="any" className="form-control form-control-sm" value={rows[l.id]?.qty ?? 0} onChange={e => setRow(l.id, { qty: e.target.value })} aria-label={`Quantity received for ${l.item}`} />
                                                    : <span className="text-secondary">Complete</span>}
                                            </td>
                                            <td>
                                                {l.remaining > 0 && (
                                                    <select className="form-select form-select-sm" value={rows[l.id]?.condition ?? 'good'} onChange={e => setRow(l.id, { condition: e.target.value })} aria-label={`Condition of ${l.item}`}>
                                                        <option value="good">Good</option>
                                                        <option value="damaged">Damaged</option>
                                                        <option value="short">Short</option>
                                                    </select>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                            <div className="row g-3">
                                <div className="col-md-5">
                                    <label className="form-label" htmlFor="dn">Delivery note number</label>
                                    <input id="dn" className="form-control" value={note} onChange={e => setNote(e.target.value)} />
                                </div>
                                {o.rights.override && (
                                    <div className="col-md-7">
                                        <label className="form-label" htmlFor="why">Reason for recording on behalf of the branch</label>
                                        <input id="why" className={'form-control' + (errs.override_reason ? ' is-invalid' : '')} value={why} onChange={e => setWhy(e.target.value)} />
                                        <div className="invalid-feedback">{errs.override_reason?.[0]}</div>
                                    </div>
                                )}
                            </div>
                            <button className="btn btn-accent mt-3" disabled={busy}>{busy ? <span className="spinner-border spinner-border-sm" /> : 'Record delivery'}</button>
                        </form>
                    </>
                )}

                {!o.rights.can_receive && awaiting && o.rights.note && (
                    <>
                        <hr />
                        <div className="alert alert-info mb-0">{o.rights.note}</div>
                    </>
                )}

                <hr />
                <div className="section-title">Deliveries recorded</div>
                {o.receipts.length === 0 && <div className="text-secondary">Nothing has been received yet.</div>}
                {o.receipts.map(g => (
                    <div key={g.receipt_no} className="border-bottom py-2">
                        <div className="d-flex justify-content-between">
                            <div><strong>{g.receipt_no}</strong> by {g.received_by}{g.delivery_note_no && <span className="text-secondary"> (delivery note {g.delivery_note_no})</span>}</div>
                            <span className="text-secondary small">{fmtDate(g.received_at)}</span>
                        </div>
                        {g.lines.map((l, i) => <div key={i} className="small">{l.item}: {l.qty} ({COND[l.condition] ?? l.condition})</div>)}
                        {g.override && <div className="small text-warning-emphasis">Recorded on behalf of the branch. Reason: {g.override_reason}</div>}
                    </div>
                ))}
            </div>
        </>
    );
}