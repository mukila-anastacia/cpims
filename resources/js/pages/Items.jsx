import { useEffect, useState } from 'react';
import { api } from '../api';
import { useAuth } from '../AuthContext';

const blank = { name: '', category_id: '', unit: '', description: '' };

export default function Items() {
    const { user } = useAuth();
    const canManage = user.role === 'Procurement Administrator';
    const [categories, setCategories] = useState([]);
    const [rows, setRows] = useState(null);
    const [search, setSearch] = useState('');
    const [category, setCategory] = useState('');
    const [active, setActive] = useState('');
    const [form, setForm] = useState(null);
    const [editId, setEditId] = useState(null);
    const [errors, setErrors] = useState({});
    const [message, setMessage] = useState('');
    const [flash, setFlash] = useState('');
    const [busy, setBusy] = useState(false);

    useEffect(() => { api('lookups').then(d => setCategories(d.categories)).catch(() => {}); }, []);

    const load = () => api(`items?search=${encodeURIComponent(search)}&category_id=${category}&active=${active}`).then(d => setRows(d.data)).catch(e => setMessage(e.message));
    useEffect(() => { const t = setTimeout(load, 250); return () => clearTimeout(t); }, [search, category, active]);

    const err = (k) => errors[k]?.[0];
    const set = (k, v) => setForm(f => ({ ...f, [k]: v }));

    function open(i = null) {
        setErrors({}); setMessage(''); setFlash('');
        setEditId(i ? i.id : null);
        setForm(i ? { name: i.name, category_id: String(i.category_id), unit: i.unit, description: i.description ?? '', is_active: i.is_active } : { ...blank });
    }

    async function save(e) {
        e.preventDefault();
        setBusy(true); setErrors({}); setMessage('');
        try {
            if (editId) await api(`items/${editId}`, { method: 'PUT', body: form });
            else await api('items', { method: 'POST', body: form });
            setFlash(editId ? 'Item updated.' : 'Item added.');
            setForm(null);
            load();
        } catch (e2) {
            setErrors(e2.errors || {});
            setMessage(Object.keys(e2.errors || {}).length ? 'Please correct the fields marked below.' : e2.message);
        } finally { setBusy(false); }
    }

    async function toggleActive(i) {
        const deactivating = i.is_active;
        if (!window.confirm(deactivating ? `Deactivate ${i.name}? It will no longer appear on new requests.` : `Activate ${i.name}?`)) return;
        try {
            await api(`items/${i.id}`, { method: 'PUT', body: { name: i.name, category_id: i.category_id, unit: i.unit, description: i.description, is_active: !deactivating } });
            setFlash(deactivating ? `${i.name} deactivated.` : `${i.name} activated.`);
            load();
        } catch (e2) { setMessage(e2.message); }
    }

    return (
        <>
            <div className="d-flex justify-content-between align-items-center mb-3">
                <h2 className="fw-semibold m-0" style={{ color: 'var(--brand)' }}>Items</h2>
                {canManage && !form && <button className="btn btn-brand" onClick={() => open()}>Add item</button>}
            </div>

            {flash && <div className="alert alert-success py-2">{flash}</div>}
            {message && !form && <div className="alert alert-danger py-2">{message}</div>}

            {form && (
                <form className="panel form-card mb-4" onSubmit={save} noValidate>
                    <div className="section-title">{editId ? 'Edit item' : 'New item'}</div>
                    {message && <div className="alert alert-danger py-2">{message}</div>}
                    <div className="row g-3">
                        <div className="col-md-6">
                            <label className="form-label" htmlFor="in">Item name</label>
                            <input id="in" className={'form-control' + (err('name') ? ' is-invalid' : '')} value={form.name} onChange={e => set('name', e.target.value)} />
                            <div className="invalid-feedback">{err('name')}</div>
                        </div>
                        <div className="col-md-4">
                            <label className="form-label" htmlFor="ic">Category</label>
                            <select id="ic" className={'form-select' + (err('category_id') ? ' is-invalid' : '')} value={form.category_id} onChange={e => set('category_id', e.target.value)}>
                                <option value="">Choose a category</option>
                                {categories.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
                            </select>
                            <div className="invalid-feedback">{err('category_id')}</div>
                        </div>
                        <div className="col-md-2">
                            <label className="form-label" htmlFor="iu">Unit</label>
                            <input id="iu" className={'form-control' + (err('unit') ? ' is-invalid' : '')} placeholder="pcs, m, kg" value={form.unit} onChange={e => set('unit', e.target.value)} />
                            <div className="invalid-feedback">{err('unit')}</div>
                        </div>
                        <div className="col-12">
                            <label className="form-label" htmlFor="id">Description (optional)</label>
                            <input id="id" className="form-control" value={form.description} onChange={e => set('description', e.target.value)} />
                        </div>
                    </div>
                    <div className="d-flex gap-2 mt-4">
                        <button className="btn btn-accent" disabled={busy}>{busy ? <span className="spinner-border spinner-border-sm" /> : (editId ? 'Save changes' : 'Add item')}</button>
                        <button type="button" className="btn btn-link" onClick={() => setForm(null)}>Cancel</button>
                    </div>
                </form>
            )}

            <div className="d-flex flex-wrap gap-2 mb-3">
                <input className="form-control" style={{ maxWidth: 260 }} placeholder="Search items" value={search} onChange={e => setSearch(e.target.value)} aria-label="Search items" />
                <select className="form-select" style={{ maxWidth: 230 }} value={category} onChange={e => setCategory(e.target.value)} aria-label="Filter by category">
                    <option value="">All categories</option>
                    {categories.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
                </select>
                <select className="form-select" style={{ maxWidth: 160 }} value={active} onChange={e => setActive(e.target.value)} aria-label="Filter by status">
                    <option value="">All items</option>
                    <option value="1">Active</option>
                    <option value="0">Inactive</option>
                </select>
            </div>

            {!rows && !message && <div className="spinner-border text-success" role="status" />}
            {rows && rows.length === 0 && <div className="panel text-secondary">No items match.</div>}
            {rows && rows.length > 0 && (
                <div className="panel p-0 overflow-auto">
                    <table className="table table-hover align-middle m-0">
                        <thead><tr><th>Item</th><th>Category</th><th>Unit</th><th>Status</th>{canManage && <th />}</tr></thead>
                        <tbody>
                            {rows.map(i => (
                                <tr key={i.id}>
                                    <td className="fw-semibold">{i.name}{i.description && <div className="small text-secondary fw-normal">{i.description}</div>}</td>
                                    <td>{i.category}</td>
                                    <td>{i.unit}</td>
                                    <td><span className={'badge text-bg-' + (i.is_active ? 'success' : 'secondary')}>{i.is_active ? 'Active' : 'Inactive'}</span></td>
                                    {canManage && (
                                        <td className="text-end text-nowrap">
                                            <button className="btn btn-sm btn-outline-secondary me-2" onClick={() => open(i)}>Edit</button>
                                            <button className={'btn btn-sm ' + (i.is_active ? 'btn-outline-danger' : 'btn-outline-success')} onClick={() => toggleActive(i)}>
                                                {i.is_active ? 'Deactivate' : 'Activate'}
                                            </button>
                                        </td>
                                    )}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </>
    );
}