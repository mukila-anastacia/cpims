import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { api } from '../api';
import { kes } from '../format';

const emptyLine = { item_id: '', quantity: 1, unit_cost_est: '', note: '' };
const today = () => new Date().toLocaleDateString('en-CA');

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

    function check(forSubmit) {
        const e = {};
        if (!categoryId) e.category_id = ['Choose a category.'];
        if (neededBy && neededBy < today()) e.needed_by = ['Choose today or a later date.'];
        const chosen = lines.map(l => l.item_id).filter(Boolean);
        if (new Set(chosen).size !== chosen.length) e.lines = ['The same item is on two rows. Combine them into one.'];
        lines.forEach((l, i) => {
            if (!l.item_id) e[`lines.${i}.item_id`] = ['Choose an item, or remove this row.'];
            if (!(Number(l.quantity) > 0)) e[`lines.${i}.quantity`] = ['Enter a quantity above zero.'];
            if (!(Number(l.unit_cost_est) > 0)) e[`lines.${i}.unit_cost_est`] = ['Enter a cost above zero.'];
        });
        if (forSubmit && !justification.trim()) e.justification = ['Enter a justification.'];
        return e;
    }

    function reject(e) {
        setErrors(e);
        setMessage('Please correct the fields marked below.');
        window.scrollTo(0, 0);
    }

    async function save() {
        const body = {
            category_id: categoryId,
            needed_by: neededBy || null,
            justification,
            lines: lines.map(l => ({ item_id: l.item_id, quantity: l.quantity, unit_cost_est: l.unit_cost_est, note: l.note || null })),
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
        setMessage(''); setErrors({});
        const e = check(false);
        if (Object.keys(e).length) { reject(e); return; }
        setBusy(true);
        try {
            const rid = await save();
            navigate(`/requests/${rid}`, { state: { flash: 'Draft saved.' } });
        } catch (e2) { fail(e2); } finally { setBusy(false); }
    }

    async function submit() {
        setMessage(''); setErrors({});
        const e = check(true);
        if (Object.keys(e).length) { reject(e); return; }
        setBusy(true);
        try {
            const rid = await save();
            const d = await api(`requests/${rid}/submit`, { method: 'POST', body: { duplicate_justification: dupText } });
            navigate(`/requests/${rid}`, { state: { flash: `Request ${d.data.request_no} submitted for approval.` } });
        } catch (e2) { fail(e2); } finally { setBusy(false); }
    }

    if (loading) return <div className="spinner-border text-success" role="status" />;

    return (
        <>
            <nav className="mb-2 small"><Link to="/requests">Requests</Link> / {requestNo || 'New'}</nav>
            <h2 className="mb-3">{id ? `Edit ${requestNo}` : 'New purchase request'}</h2>

            <div className="panel form-card mb-4">
                {message && <div className="alert alert-danger py-2" role="alert">{message}</div>}

                <div className="section-title">Request details</div>
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
                        <input id="need" type="date" className={'form-control' + (fieldError('needed_by') ? ' is-invalid' : '')} min={today()} value={neededBy} onChange={e => setNeededBy(e.target.value)} />
                        <div className="invalid-feedback">{fieldError('needed_by')}</div>
                    </div>
                    <div className="col-md-4">
                        <div className="form-label">Remaining budget</div>
                        <div className="fw-semibold pt-1">
                            {!categoryId ? 'Choose a category' : budget ? (budget.has_budget ? kes(budget.remaining) : 'No budget set') : '...'}
                        </div>
                        {budget && budget.has_budget && total > budget.remaining && (
                            <div className="text-danger small">Above the remaining budget. Finance will also need to approve.</div>
                        )}
                    </div>
                </div>

                <hr />

                <div className="section-title">Items</div>
                {fieldError('lines') && <div className="alert alert-danger py-2">{fieldError('lines')}</div>}
                <div className="table-responsive">
                    <table className="table table-sm align-middle mb-2">
                        <thead><tr><th style={{ minWidth: 220 }}>Item</th><th style={{ width: 110 }}>Quantity</th><th style={{ width: 60 }}>Unit</th><th style={{ width: 160 }}>Est. unit cost</th><th className="text-end" style={{ width: 150 }}>Line total</th><th /></tr></thead>
                        <tbody>
                            {lines.map((l, i) => (
                                <tr key={i}>
                                    <td>
                                        <select className={'form-select form-select-sm' + (fieldError(`lines.${i}.item_id`) ? ' is-invalid' : '')} value={l.item_id} disabled={!categoryId} onChange={e => setLine(i, { item_id: e.target.value })} aria-label="Item">
                                            <option value="">{categoryId ? 'Choose an item' : 'Choose a category first'}</option>
                                            {itemsHere.map(it => <option key={it.id} value={it.id}>{it.name}</option>)}
                                        </select>
                                        <div className="invalid-feedback">{fieldError(`lines.${i}.item_id`)}</div>
                                    </td>
                                    <td>
                                        <input type="number" min="0" step="any" className={'form-control form-control-sm' + (fieldError(`lines.${i}.quantity`) ? ' is-invalid' : '')} value={l.quantity} onChange={e => setLine(i, { quantity: e.target.value })} aria-label="Quantity" />
                                        <div className="invalid-feedback">{fieldError(`lines.${i}.quantity`)}</div>
                                    </td>
                                    <td className="text-secondary">{unitOf(l.item_id)}</td>
                                    <td>
                                        <input type="number" min="0" step="any" className={'form-control form-control-sm' + (fieldError(`lines.${i}.unit_cost_est`) ? ' is-invalid' : '')} value={l.unit_cost_est} onChange={e => setLine(i, { unit_cost_est: e.target.value })} aria-label="Estimated unit cost" />
                                        <div className="invalid-feedback">{fieldError(`lines.${i}.unit_cost_est`)}</div>
                                    </td>
                                    <td className="text-end">{kes((Number(l.quantity) || 0) * (Number(l.unit_cost_est) || 0))}</td>
                                    <td className="text-end"><button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => removeLine(i)} aria-label="Remove item">Remove</button></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <button type="button" className="btn btn-sm btn-outline-success" onClick={addLine}>+ Add item</button>

                <hr />

                <div className="section-title">Justification</div>
                <textarea id="just" rows="2" className={'form-control' + (fieldError('justification') ? ' is-invalid' : '')} value={justification} onChange={e => setJustification(e.target.value)} placeholder="Why is this purchase needed?" aria-label="Justification" />
                <div className="invalid-feedback">{fieldError('justification')}</div>

                {duplicates && (
                    <div className="alert alert-warning mt-3 mb-0">
                        <div className="fw-semibold mb-2">Similar items were requested recently:</div>
                        <ul className="mb-3">
                            {duplicates.map((d, i) => <li key={i}>{d.item}: {d.quantity} requested by {d.branch} on {d.date} ({d.request_no})</li>)}
                        </ul>
                        <label className="form-label" htmlFor="dup">Why is this request still needed?</label>
                        <textarea id="dup" rows="2" className="form-control" value={dupText} onChange={e => setDupText(e.target.value)} />
                        <div className="small mt-2">Your reason will be shown to the approver. Then press Submit again.</div>
                    </div>
                )}

                <hr />

                <div className="d-flex flex-wrap justify-content-between align-items-center gap-3">
                    <div>
                        <span className="text-secondary small text-uppercase me-2">Estimated total</span>
                        <span className="fs-5 fw-semibold">{kes(total)}</span>
                    </div>
                    <div className="d-flex gap-2">
                        <Link to="/requests" className="btn btn-link">Cancel</Link>
                        <button className="btn btn-outline-secondary" onClick={saveDraft} disabled={busy}>Save draft</button>
                        <button className="btn btn-accent" onClick={submit} disabled={busy}>
                            {busy ? <span className="spinner-border spinner-border-sm" /> : 'Submit for approval'}
                        </button>
                    </div>
                </div>
            </div>
        </>
    );
}