import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { api } from '../api';
import { kes } from '../format';

const emptyLine = { item_id: '', quantity: 1, unit_cost_est: '', note: '' };

export default function RequestForm() {
    const { id } = useParams();
    const navigate = useNavigate();
    const [lookups, setLookups] = useState({ categories: [], items: [] });
    const [savedId, setSavedId] = useState(id ? Number(id) : null);
    const [requestNo, setRequestNo] = useState('');
    const [categoryId, setCategoryId] = useState('');
    const [neededBy, setNeededBy] = useState('');
    const [justification, setJustification] = useState('');
    const [lines, setLines] = useState([{ ...emptyLine }]);
    const [budget, setBudget] = useState(null);
    const [errors, setErrors] = useState({});
    const [message, setMessage] = useState('');
    const [duplicates, setDuplicates] = useState(null);
    const [dupText, setDupText] = useState('');
    const [busy, setBusy] = useState(false);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        (async () => {
            try {
                setLookups(await api('lookups'));
                if (id) {
                    const { data } = await api(`requests/${id}`);
                    if (!data.can_edit) { navigate(`/requests/${id}`, { replace: true }); return; }
                    setRequestNo(data.request_no);
                    setCategoryId(String(data.category_id));
                    setNeededBy(data.needed_by ?? '');
                    setJustification(data.justification ?? '');
                    setLines(data.lines.map(l => ({ item_id: String(l.item_id), quantity: l.quantity, unit_cost_est: l.unit_cost_est, note: l.note ?? '' })));
                }
            } catch (e) { setMessage(e.message); }
            finally { setLoading(false); }
        })();
    }, [id]);

    useEffect(() => {
        if (!categoryId) { setBudget(null); return; }
        api(`budget/remaining?category_id=${categoryId}`).then(setBudget).catch(() => setBudget(null));
    }, [categoryId]);

    const total = useMemo(() => lines.reduce((s, l) => s + (Number(l.quantity) || 0) * (Number(l.unit_cost_est) || 0), 0), [lines]);
    const itemsHere = lookups.items.filter(i => String(i.category_id) === String(categoryId));
    const unitOf = (itemId) => lookups.items.find(i => String(i.id) === String(itemId))?.unit ?? '';
    const fieldError = (key) => errors[key]?.[0];

    function changeCategory(v) {
        setCategoryId(v);
        setLines(ls => {
            const keep = ls.filter(l => !l.item_id || lookups.items.some(i => String(i.id) === String(l.item_id) && String(i.category_id) === String(v)));
            return keep.length ? keep : [{ ...emptyLine }];
        });
    }
    const setLine = (i, patch) => setLines(ls => ls.map((l, n) => (n === i ? { ...l, ...patch } : l)));
    const addLine = () => setLines(ls => [...ls, { ...emptyLine }]);
    const removeLine = (i) => setLines(ls => (ls.length > 1 ? ls.filter((_, n) => n !== i) : ls));

    async function save() {
        const body = {
            category_id: categoryId,
            needed_by: neededBy || null,
            justification,
            lines: lines.filter(l => l.item_id).map(l => ({ item_id: l.item_id, quantity: l.quantity, unit_cost_est: l.unit_cost_est || 0, note: l.note || null })),
        };
        const d = savedId
            ? await api(`requests/${savedId}`, { method: 'PUT', body })
            : await api('requests', { method: 'POST', body });
        setSavedId(d.data.id);
        return d.data.id;
    }

    function fail(err) {
        setErrors(err.errors || {});
        if (err.data?.code === 'DUPLICATE_JUSTIFICATION_REQUIRED') {
            setDuplicates(err.data.duplicates);
            setMessage('');
        } else {
            setMessage(err.errors && Object.keys(err.errors).length ? 'Please correct the fields marked below.' : err.message);
        }
    }

    async function saveDraft() {
        setBusy(true); setMessage(''); setErrors({});
        try {
            const rid = await save();
            navigate(`/requests/${rid}`, { state: { flash: 'Draft saved.' } });
        } catch (e) { fail(e); } finally { setBusy(false); }
    }

    async function submit() {
        setBusy(true); setMessage(''); setErrors({});
        try {
            const rid = await save();
            const d = await api(`requests/${rid}/submit`, { method: 'POST', body: { duplicate_justification: dupText } });
            navigate(`/requests/${rid}`, { state: { flash: `Request ${d.data.request_no} submitted for approval.` } });
        } catch (e) { fail(e); } finally { setBusy(false); }
    }

    if (loading) return <div className="spinner-border text-success" role="status" />;

    return (
        <>
            <nav className="mb-2 small"><Link to="/requests">Requests</Link> / {requestNo || 'New'}</nav>
            <h2 className="fw-semibold mb-4" style={{ color: 'var(--brand)' }}>{id ? `Edit ${requestNo}` : 'New purchase request'}</h2>

            {message && <div className="alert alert-danger" role="alert">{message}</div>}

            <div className="panel mb-3">
                <div className="row g-3">
                    <div className="col-md-4">
                        <label className="form-label" htmlFor="cat">Category</label>
                        <select id="cat" className={'form-select' + (fieldError('category_id') ? ' is-invalid' : '')} value={categoryId} onChange={e => changeCategory(e.target.value)}>
                            <option value="">Choose a category</option>
                            {lookups.categories.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
                        </select>
                        <div className="invalid-feedback">{fieldError('category_id')}</div>
                    </div>
                    <div className="col-md-4">
                        <label className="form-label" htmlFor="need">Needed by</label>
                        <input id="need" type="date" className={'form-control' + (fieldError('needed_by') ? ' is-invalid' : '')} min={new Date().toISOString().slice(0, 10)} value={neededBy} onChange={e => setNeededBy(e.target.value)} />
                        <div className="invalid-feedback">{fieldError('needed_by')}</div>
                    </div>
                    <div className="col-md-4">
                        <div className="label small text-secondary text-uppercase">Remaining budget</div>
                        <div className="fw-semibold" style={{ color: 'var(--brand)' }}>
                            {!categoryId ? 'Choose a category' : budget ? (budget.has_budget ? kes(budget.remaining) : 'No budget set') : '...'}
                        </div>
                        {budget && budget.has_budget && total > budget.remaining && (
                            <div className="text-danger small">This request is above the remaining budget. Finance will also need to approve it.</div>
                        )}
                    </div>
                </div>
            </div>

            <div className="panel mb-3">
                <h5 className="fw-semibold mb-3">Items</h5>
                {fieldError('lines') && <div className="alert alert-danger py-2">{fieldError('lines')}</div>}
                <div className="table-responsive">
                    <table className="table align-middle">
                        <thead><tr><th style={{ minWidth: 220 }}>Item</th><th style={{ width: 110 }}>Quantity</th><th style={{ width: 70 }}>Unit</th><th style={{ width: 160 }}>Est. unit cost</th><th className="text-end" style={{ width: 150 }}>Line total</th><th /></tr></thead>
                        <tbody>
                            {lines.map((l, i) => (
                                <tr key={i}>
                                    <td>
                                        <select className={'form-select' + (fieldError(`lines.${i}.item_id`) ? ' is-invalid' : '')} value={l.item_id} disabled={!categoryId} onChange={e => setLine(i, { item_id: e.target.value })} aria-label="Item">
                                            <option value="">{categoryId ? 'Choose an item' : 'Choose a category first'}</option>
                                            {itemsHere.map(it => <option key={it.id} value={it.id}>{it.name}</option>)}
                                        </select>
                                        <div className="invalid-feedback">{fieldError(`lines.${i}.item_id`)}</div>
                                    </td>
                                    <td>
                                        <input type="number" min="0" step="any" className={'form-control' + (fieldError(`lines.${i}.quantity`) ? ' is-invalid' : '')} value={l.quantity} onChange={e => setLine(i, { quantity: e.target.value })} aria-label="Quantity" />
                                        <div className="invalid-feedback">{fieldError(`lines.${i}.quantity`)}</div>
                                    </td>
                                    <td className="text-secondary">{unitOf(l.item_id)}</td>
                                    <td>
                                        <input type="number" min="0" step="any" className={'form-control' + (fieldError(`lines.${i}.unit_cost_est`) ? ' is-invalid' : '')} value={l.unit_cost_est} onChange={e => setLine(i, { unit_cost_est: e.target.value })} aria-label="Estimated unit cost" />
                                        <div className="invalid-feedback">{fieldError(`lines.${i}.unit_cost_est`)}</div>
                                    </td>
                                    <td className="text-end">{kes((Number(l.quantity) || 0) * (Number(l.unit_cost_est) || 0))}</td>
                                    <td className="text-end"><button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => removeLine(i)} aria-label="Remove item">Remove</button></td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot><tr><td colSpan="4" className="text-end fw-semibold">Estimated total</td><td className="text-end fw-semibold">{kes(total)}</td><td /></tr></tfoot>
                    </table>
                </div>
                <button type="button" className="btn btn-sm btn-outline-success" onClick={addLine}>+ Add item</button>
            </div>

            <div className="panel mb-3">
                <label className="form-label" htmlFor="just">Justification</label>
                <textarea id="just" rows="3" className={'form-control' + (fieldError('justification') ? ' is-invalid' : '')} value={justification} onChange={e => setJustification(e.target.value)} placeholder="Why is this purchase needed?" />
                <div className="invalid-feedback">{fieldError('justification')}</div>
            </div>

            {duplicates && (
                <div className="alert alert-warning">
                    <div className="fw-semibold mb-2">Similar items were requested recently:</div>
                    <ul className="mb-3">
                        {duplicates.map((d, i) => <li key={i}>{d.item}: {d.quantity} requested by {d.branch} on {d.date} ({d.request_no})</li>)}
                    </ul>
                    <label className="form-label" htmlFor="dup">Why is this request still needed?</label>
                    <textarea id="dup" rows="2" className="form-control" value={dupText} onChange={e => setDupText(e.target.value)} />
                    <div className="small mt-2">Your reason will be shown to the approver. Then press Submit again.</div>
                </div>
            )}

            <div className="d-flex gap-2 mb-5">
                <button className="btn btn-outline-secondary" onClick={saveDraft} disabled={busy}>Save draft</button>
                <button className="btn btn-brand" onClick={submit} disabled={busy}>
                    {busy ? <span className="spinner-border spinner-border-sm" /> : 'Submit for approval'}
                </button>
                <Link to="/requests" className="btn btn-link">Cancel</Link>
            </div>
        </>
    );
}