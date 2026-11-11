import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { api } from '../api';
import { useAuth } from '../AuthContext';
import StatusBadge from '../components/StatusBadge';
import { fmtDate, kes } from '../format';

export default function QuotationCompare() {
    const { id } = useParams();
    const { user } = useAuth();
    const canEdit = user.role === 'Procurement Administrator';
    const [data, setData] = useState(null);
    const [vendors, setVendors] = useState([]);
    const [error, setError] = useState('');
    const [flash, setFlash] = useState('');
    const [form, setForm] = useState(null);
    const [errors, setErrors] = useState({});
    const [formMsg, setFormMsg] = useState('');
    const [choose, setChoose] = useState(null);
    const [reason, setReason] = useState('');
    const [chooseMsg, setChooseMsg] = useState('');
    const [busy, setBusy] = useState(false);

    const load = () => api(`requests/${id}/quotations`).then(setData).catch(e => setError(e.message));
    useEffect(() => { load(); }, [id]);
    useEffect(() => { if (canEdit) api('vendors?status=active').then(d => setVendors(d.data)).catch(() => {}); }, []);

    if (error) return <div className="alert alert-danger">{error} <Link to="/quotations">Back to quotations</Link></div>;
    if (!data) return <div className="spinner-border text-success" role="status" />;

    const { request: r, lines, quotations, lowest_total: lowest, rules, locked } = data;
    const hasSelected = quotations.some(q => q.is_selected);
    const usedVendorIds = quotations.filter(q => q.id !== form?.id).map(q => String(q.vendor_id));
    const err = (k) => errors[k]?.[0];

    function openForm(q = null) {
        setErrors({}); setFormMsg(''); setFlash(''); setChoose(null);
        const prices = {};
        lines.forEach(l => { prices[l.id] = q ? (q.prices[l.id] ?? '') : ''; });
        setForm({ id: q?.id ?? null, vendor_id: q ? String(q.vendor_id) : '', delivery_days: q?.delivery_days ?? '', valid_until: q?.valid_until ?? '', prices });
    }

    const formTotal = form ? lines.reduce((s, l) => s + l.quantity * (Number(form.prices[l.id]) || 0), 0) : 0;

    async function saveQuote(e) {
        e.preventDefault();
        setBusy(true); setErrors({}); setFormMsg('');
        const body = {
            vendor_id: form.vendor_id,
            delivery_days: form.delivery_days === '' ? null : form.delivery_days,
            valid_until: form.valid_until || null,
            lines: lines.map(l => ({ request_line_id: l.id, unit_price: form.prices[l.id] })),
        };
        try {
            const d = form.id
                ? await api(`requests/${id}/quotations/${form.id}`, { method: 'PUT', body })
                : await api(`requests/${id}/quotations`, { method: 'POST', body });
            setData(d); setForm(null); setFlash(form.id ? 'Quotation updated.' : 'Quotation added.');
        } catch (e2) {
            setErrors(e2.errors || {});
            setFormMsg(Object.keys(e2.errors || {}).length ? 'Please correct the fields marked below.' : e2.message);
        } finally { setBusy(false); }
    }

    async function remove(q) {
        if (!window.confirm(`Remove the quotation from ${q.vendor}?`)) return;
        try { setData(await api(`requests/${id}/quotations/${q.id}`, { method: 'DELETE' })); setFlash('Quotation removed.'); }
        catch (e2) { setFlash(''); setError(e2.message); }
    }

    function startChoose(q) { setForm(null); setFlash(''); setChooseMsg(''); setReason(''); setChoose(q); }

    async function confirmChoose() {
        setBusy(true); setChooseMsg('');
        try {
            setData(await api(`requests/${id}/quotations/${choose.id}/select`, { method: 'POST', body: { reason } }));
            setFlash(`${choose.vendor} selected.`); setChoose(null);
        } catch (e2) { setChooseMsg(e2.errors?.reason?.[0] || e2.errors?.quotation?.[0] || e2.message); }
        finally { setBusy(false); }
    }

    return (
        <>
            <nav className="mb-2 small"><Link to="/quotations">Quotations</Link> / {r.request_no}</nav>
            <div className="d-flex justify-content-between align-items-start mb-3">
                <div>
                    <h2 className="mb-1">{r.request_no} <StatusBadge status={r.status} /></h2>
                    <div className="text-secondary">{r.category} | {r.branch} | estimate {kes(r.total_estimate)} | <Link to={`/requests/${r.id}`}>View request</Link></div>
                </div>
                {canEdit && !locked && !form && <button className="btn btn-accent" onClick={() => openForm()}>Add quotation</button>}
            </div>

            {flash && <div className="alert alert-success py-2">{flash}</div>}
            {locked && <div className="alert alert-info py-2">This request already has an order, so its quotations can no longer be changed.</div>}
            <div className={'alert py-2 ' + (rules.applies && rules.count < rules.min_quotations ? 'alert-warning' : 'alert-secondary')}>
                {rules.applies
                    ? `This request is above KES ${rules.threshold.toLocaleString('en-KE')}, so at least ${rules.min_quotations} quotations are required (${rules.count} recorded).`
                    : 'At least one quotation is needed before a vendor can be selected.'}
            </div>

            {form && (
                <form className="panel form-card mb-4" onSubmit={saveQuote} noValidate>
                    <div className="section-title">{form.id ? 'Edit quotation' : 'New quotation'}</div>
                    {formMsg && <div className="alert alert-danger py-2">{formMsg}</div>}
                    <div className="row g-3 mb-3">
                        <div className="col-md-5">
                            <label className="form-label" htmlFor="qv">Vendor</label>
                            <select id="qv" className={'form-select' + (err('vendor_id') ? ' is-invalid' : '')} value={form.vendor_id} onChange={e => setForm({ ...form, vendor_id: e.target.value })}>
                                <option value="">Choose a vendor</option>
                                {vendors.filter(v => !usedVendorIds.includes(String(v.id))).map(v => <option key={v.id} value={v.id}>{v.name}</option>)}
                            </select>
                            <div className="invalid-feedback">{err('vendor_id')}</div>
                        </div>
                        <div className="col-md-3">
                            <label className="form-label" htmlFor="qd">Delivery (days)</label>
                            <input id="qd" type="number" min="0" className={'form-control' + (err('delivery_days') ? ' is-invalid' : '')} value={form.delivery_days} onChange={e => setForm({ ...form, delivery_days: e.target.value })} />
                            <div className="invalid-feedback">{err('delivery_days')}</div>
                        </div>
                        <div className="col-md-4">
                            <label className="form-label" htmlFor="qu">Valid until</label>
                            <input id="qu" type="date" className={'form-control' + (err('valid_until') ? ' is-invalid' : '')} min={new Date().toISOString().slice(0, 10)} value={form.valid_until} onChange={e => setForm({ ...form, valid_until: e.target.value })} />
                            <div className="invalid-feedback">{err('valid_until')}</div>
                        </div>
                    </div>
                    {err('lines') && <div className="alert alert-danger py-2">{err('lines')}</div>}
                    <table className="table table-sm align-middle">
                        <thead><tr><th>Item</th><th className="text-end">Quantity</th><th style={{ width: 180 }}>Unit price (KES)</th><th className="text-end">Line total</th></tr></thead>
                        <tbody>
                            {lines.map((l, i) => (
                                <tr key={l.id}>
                                    <td>{l.item}</td>
                                    <td className="text-end">{l.quantity} {l.unit}</td>
                                    <td>
                                        <input type="number" min="0" step="any" className={'form-control form-control-sm' + (err(`lines.${i}.unit_price`) ? ' is-invalid' : '')}
                                               value={form.prices[l.id]} onChange={e => setForm({ ...form, prices: { ...form.prices, [l.id]: e.target.value } })} aria-label={`Unit price for ${l.item}`} />
                                        <div className="invalid-feedback">{err(`lines.${i}.unit_price`)}</div>
                                    </td>
                                    <td className="text-end">{kes(l.quantity * (Number(form.prices[l.id]) || 0))}</td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot><tr><td colSpan="3" className="text-end fw-semibold">Quotation total</td><td className="text-end fw-semibold">{kes(formTotal)}</td></tr></tfoot>
                    </table>
                    <div className="d-flex gap-2">
                        <button className="btn btn-accent" disabled={busy}>{busy ? <span className="spinner-border spinner-border-sm" /> : (form.id ? 'Save changes' : 'Add quotation')}</button>
                        <button type="button" className="btn btn-link" onClick={() => setForm(null)}>Cancel</button>
                    </div>
                </form>
            )}

            {choose && (
                <div className="panel mb-4" style={{ borderLeft: '4px solid var(--accent)' }}>
                    <h5 className="mb-2">Select {choose.vendor}</h5>
                    <p className="mb-2">Total {kes(choose.total)}{lowest !== null && choose.total > lowest + 0.004 ? `, which is ${kes(choose.total - lowest)} above the lowest quotation.` : ', the lowest quotation.'}</p>
                    {lowest !== null && choose.total > lowest + 0.004 && (
                        <>
                            <label className="form-label" htmlFor="why">Why was the lowest quotation not chosen?</label>
                            <textarea id="why" rows="2" className="form-control mb-2" value={reason} onChange={e => setReason(e.target.value)} />
                        </>
                    )}
                    {chooseMsg && <div className="alert alert-danger py-2">{chooseMsg}</div>}
                    <div className="d-flex gap-2">
                        <button className="btn btn-brand" disabled={busy} onClick={confirmChoose}>Confirm selection</button>
                        <button className="btn btn-link" onClick={() => setChoose(null)}>Cancel</button>
                    </div>
                </div>
            )}

            {quotations.length === 0 ? (
                <div className="panel text-secondary mb-4">No quotations yet.{canEdit && !locked ? ' Press "Add quotation" to record the first one.' : ''}</div>
            ) : (
                <div className="panel p-0 overflow-auto mb-4">
                    <table className="table align-middle m-0 compare">
                        <thead>
                            <tr>
                                <th>Item</th>
                                {quotations.map(q => (
                                    <th key={q.id} className={q.is_selected ? 'sel' : ''}>
                                        {q.vendor}{q.is_selected && <span className="badge text-bg-success ms-2">Selected</span>}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {lines.map(l => (
                                <tr key={l.id}>
                                    <td><div className="fw-semibold">{l.item}</div><div className="small text-secondary">{l.quantity} {l.unit}</div></td>
                                    {quotations.map(q => (
                                        <td key={q.id} className={(q.prices[l.id] === l.lowest_price ? 'lowest ' : '') + (q.is_selected ? 'sel' : '')}>{kes(q.prices[l.id])}</td>
                                    ))}
                                </tr>
                            ))}
                            <tr className="fw-bold">
                                <td>Total</td>
                                {quotations.map(q => <td key={q.id} className={(q.is_lowest ? 'lowest ' : '') + (q.is_selected ? 'sel' : '')}>{kes(q.total)}{q.is_lowest && <div className="small fw-normal">Lowest</div>}</td>)}
                            </tr>
                            <tr><td className="text-secondary">Delivery</td>{quotations.map(q => <td key={q.id} className={q.is_selected ? 'sel' : ''}>{q.delivery_days !== null ? `${q.delivery_days} days` : '-'}</td>)}</tr>
                            <tr><td className="text-secondary">Valid until</td>{quotations.map(q => <td key={q.id} className={q.is_selected ? 'sel' : ''}>{fmtDate(q.valid_until)}</td>)}</tr>
                            {quotations.some(q => q.reason_not_lowest) && (
                                <tr><td className="text-secondary">Reason chosen</td>{quotations.map(q => <td key={q.id} className="small">{q.reason_not_lowest || ''}</td>)}</tr>
                            )}
                            {canEdit && !locked && (
                                <tr>
                                    <td />
                                    {quotations.map(q => (
                                        <td key={q.id} className="text-nowrap">
                                            {!q.is_selected && <button className="btn btn-sm btn-brand me-1" onClick={() => startChoose(q)}>Select</button>}
                                            {!q.is_selected && <button className="btn btn-sm btn-outline-secondary me-1" onClick={() => openForm(q)}>Edit</button>}
                                            {!q.is_selected && <button className="btn btn-sm btn-outline-danger" onClick={() => remove(q)}>Remove</button>}
                                        </td>
                                    ))}
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            )}

            <div className="panel mb-4">
                <h5 className="mb-3">Recent prices for the same items</h5>
                {lines.map(l => (
                    <div key={l.id} className="mb-3">
                        <div className="fw-semibold">{l.item}</div>
                        {l.history.length === 0 && <div className="small text-secondary">No earlier prices on record.</div>}
                        {l.history.map((h, i) => (
                            <div key={i} className="small d-flex justify-content-between border-bottom py-1">
                                <span>{kes(h.unit_price)} per {l.unit} from {h.vendor} ({h.branch}, {h.kind === 'ordered' ? 'ordered' : 'quoted'} {h.date})</span>
                            </div>
                        ))}
                    </div>
                ))}
            </div>

            {hasSelected && !locked && <div className="alert alert-success">A vendor is selected. The next step, issuing the purchase order, is built next.</div>}
        </>
    );
}