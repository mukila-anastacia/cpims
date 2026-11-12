import { useEffect, useState } from 'react';
import { api } from '../api';
import { useAuth } from '../AuthContext';
import { fmtDate, kes } from '../format';

const blank = { branch_id: '', category_id: '', period_start: '', period_end: '', amount: '' };

export default function Budgets() {
    const { user } = useAuth();
    const canEdit = user.role === 'Finance Officer';
    const [resp, setResp] = useState(null);
    const [period, setPeriod] = useState('current');
    const [branch, setBranch] = useState('');
    const [category, setCategory] = useState('');
    const [form, setForm] = useState(null);
    const [editId, setEditId] = useState(null);
    const [errors, setErrors] = useState({});
    const [message, setMessage] = useState('');
    const [flash, setFlash] = useState('');
    const [busy, setBusy] = useState(false);

    const load = () => api(`budgets?period=${period}&branch_id=${branch}&category_id=${category}`).then(setResp).catch(e => setMessage(e.message));
    useEffect(() => { load(); }, [period, branch, category]);

    const err = (k) => errors[k]?.[0];
    const set = (k, v) => setForm(f => ({ ...f, [k]: v }));

    function open(b = null) {
        setErrors({}); setMessage(''); setFlash('');
        setEditId(b ? b.id : null);
        setForm(b ? { branch_id: String(b.branch_id), category_id: String(b.category_id), period_start: b.period_start, period_end: b.period_end, amount: b.amount } : { ...blank });
    }

    async function save(e) {
        e.preventDefault();
        setBusy(true); setErrors({}); setMessage('');
        try {
            if (editId) await api(`budgets/${editId}`, { method: 'PUT', body: form });
            else await api('budgets', { method: 'POST', body: form });
            setFlash(editId ? 'Budget updated.' : 'Budget added.');
            setForm(null);
            load();
        } catch (e2) {
            setErrors(e2.errors || {});
            setMessage(Object.keys(e2.errors || {}).length ? 'Please correct the fields marked below.' : e2.message);
        } finally { setBusy(false); }
    }

    const rows = resp?.data ?? [];
    const sum = (k) => rows.reduce((s, r) => s + r[k], 0);

    return (
        <>
            <div className="d-flex justify-content-between align-items-center mb-1">
                <h2 className="m-0">Budgets</h2>
                {canEdit && !form && <button className="btn btn-brand" onClick={() => open()}>Add budget</button>}
            </div>
            <p className="text-secondary mb-4">Budgets by branch and category. Approved requests count against the budget.</p>

            {flash && <div className="alert alert-success py-2">{flash}</div>}
            {message && !form && <div className="alert alert-danger py-2">{message}</div>}

            {form && resp && (
                <form className="panel form-card mb-4" onSubmit={save} noValidate>
                    <div className="section-title">{editId ? 'Edit budget' : 'New budget'}</div>
                    {message && <div className="alert alert-danger py-2">{message}</div>}
                    <div className="row g-3">
                        <div className="col-md-4">
                            <label className="form-label" htmlFor="bb">Branch</label>
                            <select id="bb" className={'form-select' + (err('branch_id') ? ' is-invalid' : '')} value={form.branch_id} onChange={e => set('branch_id', e.target.value)}>
                                <option value="">Choose a branch</option>
                                {resp.branches.map(b => <option key={b.id} value={b.id}>{b.name}</option>)}
                            </select>
                            <div className="invalid-feedback">{err('branch_id')}</div>
                        </div>
                        <div className="col-md-4">
                            <label className="form-label" htmlFor="bc">Category</label>
                            <select id="bc" className={'form-select' + (err('category_id') ? ' is-invalid' : '')} value={form.category_id} onChange={e => set('category_id', e.target.value)}>
                                <option value="">Choose a category</option>
                                {resp.categories.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
                            </select>
                            <div className="invalid-feedback">{err('category_id')}</div>
                        </div>
                        <div className="col-md-4">
                            <label className="form-label" htmlFor="ba">Amount (KES)</label>
                            <input id="ba" type="number" min="0" step="any" className={'form-control' + (err('amount') ? ' is-invalid' : '')} value={form.amount} onChange={e => set('amount', e.target.value)} />
                            <div className="invalid-feedback">{err('amount')}</div>
                        </div>
                        <div className="col-md-4">
                            <label className="form-label" htmlFor="bs">Period starts</label>
                            <input id="bs" type="date" className={'form-control' + (err('period_start') ? ' is-invalid' : '')} value={form.period_start} onChange={e => set('period_start', e.target.value)} />
                            <div className="invalid-feedback">{err('period_start')}</div>
                        </div>
                        <div className="col-md-4">
                            <label className="form-label" htmlFor="be">Period ends</label>
                            <input id="be" type="date" className={'form-control' + (err('period_end') ? ' is-invalid' : '')} value={form.period_end} onChange={e => set('period_end', e.target.value)} />
                            <div className="invalid-feedback">{err('period_end')}</div>
                        </div>
                    </div>
                    <div className="d-flex gap-2 mt-4">
                        <button className="btn btn-accent" disabled={busy}>{busy ? <span className="spinner-border spinner-border-sm" /> : (editId ? 'Save changes' : 'Add budget')}</button>
                        <button type="button" className="btn btn-link" onClick={() => setForm(null)}>Cancel</button>
                    </div>
                </form>
            )}

            <div className="row g-3 mb-4">
                <div className="col-md-4"><div className="panel"><div className="label">Budget</div><div className="value">{kes(sum('amount'))}</div></div></div>
                <div className="col-md-4"><div className="panel"><div className="label">Committed</div><div className="value">{kes(sum('committed'))}</div></div></div>
                <div className="col-md-4"><div className="panel"><div className="label">Remaining</div><div className="value">{kes(sum('remaining'))}</div></div></div>
            </div>

            <div className="d-flex flex-wrap gap-2 mb-3">
                <select className="form-select" style={{ maxWidth: 190 }} value={period} onChange={e => setPeriod(e.target.value)} aria-label="Period">
                    <option value="current">Current periods</option>
                    <option value="all">All periods</option>
                </select>
                <select className="form-select" style={{ maxWidth: 200 }} value={branch} onChange={e => setBranch(e.target.value)} aria-label="Branch">
                    <option value="">All branches</option>
                    {(resp?.branches ?? []).map(b => <option key={b.id} value={b.id}>{b.name}</option>)}
                </select>
                <select className="form-select" style={{ maxWidth: 240 }} value={category} onChange={e => setCategory(e.target.value)} aria-label="Category">
                    <option value="">All categories</option>
                    {(resp?.categories ?? []).map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
                </select>
            </div>

            {!resp && !message && <div className="spinner-border text-success" role="status" />}
            {resp && rows.length === 0 && <div className="panel text-secondary">No budgets match.</div>}
            {rows.length > 0 && (
                <div className="panel p-0 overflow-auto">
                    <table className="table table-hover align-middle m-0">
                        <thead><tr><th>Branch</th><th>Category</th><th>Period</th><th className="text-end">Budget</th><th className="text-end">Committed</th><th className="text-end">Remaining</th><th style={{ minWidth: 130 }}>Used</th>{canEdit && <th />}</tr></thead>
                        <tbody>
                            {rows.map(b => {
                                const tone = b.used_pct > 100 ? 'bg-danger' : b.used_pct > 80 ? 'bg-warning' : 'bg-success';
                                return (
                                    <tr key={b.id}>
                                        <td className="fw-semibold">{b.branch}</td>
                                        <td>{b.category}</td>
                                        <td className="small">{fmtDate(b.period_start)} to {fmtDate(b.period_end)}{b.current && <span className="badge text-bg-info ms-2">Current</span>}</td>
                                        <td className="text-end">{kes(b.amount)}</td>
                                        <td className="text-end">{kes(b.committed)}</td>
                                        <td className={'text-end' + (b.remaining < 0 ? ' text-danger fw-semibold' : '')}>{kes(b.remaining)}</td>
                                        <td>
                                            <div className="progress" style={{ height: 8 }}><div className={'progress-bar ' + tone} style={{ width: Math.min(100, b.used_pct) + '%' }} /></div>
                                            <div className="small text-secondary">{b.used_pct}%</div>
                                        </td>
                                        {canEdit && <td className="text-end"><button className="btn btn-sm btn-outline-secondary" onClick={() => open(b)}>Edit</button></td>}
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