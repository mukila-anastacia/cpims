import { useEffect, useState } from 'react';
import { Link, useLocation, useParams } from 'react-router-dom';
import { api } from '../api';
import InvoiceBadge from '../components/InvoiceBadge';
import { fmtDate, kes } from '../format';

export default function InvoiceDetail() {
    const { id } = useParams();
    const navFlash = useLocation().state?.flash;
    const [inv, setInv] = useState(null);
    const [error, setError] = useState('');
    const [flash, setFlash] = useState(navFlash || '');
    const [comment, setComment] = useState('');
    const [formError, setFormError] = useState('');
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        api(`invoices/${id}`).then(d => setInv(d.data)).catch(e => setError(e.message));
    }, [id]);

    async function resolve(e) {
        e.preventDefault();
        setBusy(true); setFormError('');
        try {
            const d = await api(`invoices/${id}/resolve`, { method: 'POST', body: { comment } });
            setInv(d.data); setComment(''); setFlash('Exception resolved.');
        } catch (e2) { setFormError(e2.errors?.comment?.[0] || e2.message); }
        finally { setBusy(false); }
    }

    if (error) return <div className="alert alert-danger">{error} <Link to="/invoices">Back to invoices</Link></div>;
    if (!inv) return <div className="spinner-border text-success" role="status" />;

    return (
        <>
            <nav className="mb-2 small"><Link to="/invoices">Invoices</Link> / {inv.invoice_no}</nav>
            {flash && <div className="alert alert-success py-2">{flash}</div>}

            <h2 className="mb-1">{inv.invoice_no} <InvoiceBadge status={inv.match_status} /></h2>
            <div className="text-secondary mb-3">
                Order <Link to={`/orders/${inv.po_id}`}>{inv.po_no}</Link> | request <Link to={`/requests/${inv.request_no ? '' : ''}`} onClick={e => e.preventDefault()}>{inv.request_no}</Link> | {inv.branch}
            </div>

            <div className="panel form-card mb-4">
                <div className="row g-3">
                    <div className="col-md-3"><div className="label small text-secondary text-uppercase">Vendor</div><div className="fw-semibold">{inv.vendor.name}</div><div className="small">{inv.vendor.tax_pin}</div></div>
                    <div className="col-md-3"><div className="label small text-secondary text-uppercase">Invoice date</div><div className="fw-semibold">{fmtDate(inv.invoice_date)}</div></div>
                    <div className="col-md-3"><div className="label small text-secondary text-uppercase">Entered by</div><div className="fw-semibold">{inv.entered_by}</div></div>
                    <div className="col-md-3"><div className="label small text-secondary text-uppercase">Invoice total</div><div className="fw-semibold">{kes(inv.total)}</div></div>
                </div>

                <hr />
                <div className="section-title">Three-way match</div>
                <div className="table-responsive">
                    <table className="table align-middle mb-2">
                        <thead>
                            <tr><th>Item</th><th className="text-end">Order price</th><th className="text-end">Invoice price</th><th className="text-end">Received, not yet invoiced</th><th className="text-end">Invoice qty</th><th>Result</th></tr>
                        </thead>
                        <tbody>
                            {inv.lines.map((l, i) => (
                                <tr key={i}>
                                    <td>{l.item}</td>
                                    <td className="text-end">{kes(l.order_price)}</td>
                                    <td className="text-end">{kes(l.unit_price)}</td>
                                    <td className="text-end">{l.expected} {l.unit}</td>
                                    <td className="text-end">{l.quantity} {l.unit}</td>
                                    <td>
                                        {l.ok
                                            ? <span className="badge text-bg-success">Matches</span>
                                            : <div>{l.issues.map((t, k) => <div key={k} className="small text-danger">{t}</div>)}</div>}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <div className="small text-secondary">Prices must be within {inv.tolerance}% of the order price, and quantities must equal what was received and not yet invoiced.</div>

                <hr />
                <div className="section-title">Outcome</div>
                {inv.match_status === 'matched' && <div className="alert alert-success mb-0">Everything matches. This invoice is <strong>approved for payment</strong>.</div>}
                {inv.match_status === 'exception' && !inv.can_resolve && <div className="alert alert-warning mb-0">This invoice has differences. It is waiting for Procurement to review and resolve it.</div>}
                {inv.match_status === 'resolved' && (
                    <div className="alert alert-info mb-0">
                        Exception resolved by <strong>{inv.resolved_by}</strong>: {inv.resolution_comment}
                    </div>
                )}

                {inv.can_resolve && (
                    <form onSubmit={resolve} noValidate>
                        <div className="alert alert-warning">This invoice does not match the order or the delivery. Check with the vendor and the branch, then record how it was settled.</div>
                        {formError && <div className="alert alert-danger py-2">{formError}</div>}
                        <label className="form-label" htmlFor="rc">How was the exception resolved?</label>
                        <textarea id="rc" rows="3" className="form-control mb-3" value={comment} onChange={e => setComment(e.target.value)} placeholder="For example: vendor confirmed a price change approved by the manager" />
                        <button className="btn btn-accent" disabled={busy}>{busy ? <span className="spinner-border spinner-border-sm" /> : 'Mark as resolved'}</button>
                    </form>
                )}
            </div>
        </>
    );
}