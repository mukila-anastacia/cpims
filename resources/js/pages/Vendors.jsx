import { useEffect, useState } from 'react';
import { api } from '../api';
import { useAuth } from '../AuthContext';

const blank = { name: '', contact_person: '', phone: '', email: '', tax_pin: '' };

export default function Vendors() {
    const { user } = useAuth();
    const canManage = user.role === 'Procurement Administrator';
    const [rows, setRows] = useState(null);
    const [search, setSearch] = useState('');
    const [status, setStatus] = useState('');
    const [form, setForm] = useState(null);
    const [editId, setEditId] = useState(null);
    const [errors, setErrors] = useState({});
    const [message, setMessage] = useState('');
    const [flash, setFlash] = useState('');
    const [busy, setBusy] = useState(false);

    const load = () => api(`vendors?search=${encodeURIComponent(search)}&status=${status}`).then(d => setRows(d.data)).catch(e => setMessage(e.message));
    useEffect(() => { const t = setTimeout(load, 250); return () => clearTimeout(t); }, [search, status]);

    const err = (k) => errors[k]?.[0];
    const set = (k, v) => setForm(f => ({ ...f, [k]: v }));

    function open(v = null) {
        setErrors({}); setMessage(''); setFlash('');
        setEditId(v ? v.id : null);
        setForm(v ? { name: v.name, contact_person: v.contact_person ?? '', phone: v.phone ?? '', email: v.email ?? '', tax_pin: v.tax_pin, status: v.status } : { ...blank });
    }

    async function save(e) {
        e.preventDefault();
        setBusy(true); setErrors({}); setMessage('');
        try {
            if (editId) await api(`vendors/${editId}`, { method: 'PUT', body: form });
            else await api('vendors', { method: 'POST', body: form });
            setFlash(editId ? 'Vendor updated.' : 'Vendor added.');
            setForm(null);
            load();
        } catch (e2) {
            setErrors(e2.errors || {});
            setMessage(Object.keys(e2.errors || {}).length ? 'Please correct the fields marked below.' : e2.message);
        } finally { setBusy(false); }
    }

    async function toggleBlock(v) {
        const blocking = v.status === 'active';
        if (!window.confirm(blocking ? `Block ${v.name}? It cannot be chosen for new quotations or orders.` : `Unblock ${v.name}?`)) return;
        try {
            await api(`vendors/${v.id}`, { method: 'PUT', body: { name: v.name, contact_person: v.contact_person, phone: v.phone, email: v.email, tax_pin: v.tax_pin, status: blocking ? 'blocked' : 'active' } });
            setFlash(blocking ? `${v.name} blocked.` : `${v.name} unblocked.`);
            load();
        } catch (e2) { setMessage(e2.message); }
    }

    return (
        <>
            <div className="d-flex justify-content-between align-items-center mb-3">
                <h2 className="fw-semibold m-0" style={{ color: 'var(--brand)' }}>Vendors</h2>
                {canManage && !form && <button className="btn btn-brand" onClick={() => open()}>Add vendor</button>}
            </div>

            {flash && <div className="alert alert-success py-2">{flash}</div>}
            {message && !form && <div className="alert alert-danger py-2">{message}</div>}

            {form && (
                <form className="panel form-card mb-4" onSubmit={save} noValidate>
                    <div className="section-title">{editId ? 'Edit vendor' : 'New vendor'}</div>
                    {message && <div className="alert alert-danger py-2">{message}</div>}
                    <div className="row g-3">
                        <div className="col-md-6">
                            <label className="form-label" htmlFor="vn">Company name</label>
                            <input id="vn" className={'form-control' + (err('name') ? ' is-invalid' : '')} value={form.name} onChange={e => set('name', e.target.value)} />
                            <div className="invalid-feedback">{err('name')}</div>
                        </div>
                        <div className="col-md-6">
                            <label className="form-label" htmlFor="vp">KRA PIN</label>
                            <input id="vp" className={'form-control' + (err('tax_pin') ? ' is-invalid' : '')} placeholder="P000000001A" value={form.tax_pin} onChange={e => set('tax_pin', e.target.value.toUpperCase())} maxLength={11} />
                            <div className="invalid-feedback">{err('tax_pin')}</div>
                        </div>
                        <div className="col-md-4">
                            <label className="form-label" htmlFor="vc">Contact person</label>
                            <input id="vc" className="form-control" value={form.contact_person} onChange={e => set('contact_person', e.target.value)} />
                        </div>
                        <div className="col-md-4">
                            <label className="form-label" htmlFor="vt">Phone</label>
                            <input id="vt" className="form-control" value={form.phone} onChange={e => set('phone', e.target.value)} />
                        </div>
                        <div className="col-md-4">
                            <label className="form-label" htmlFor="ve">Email</label>
                            <input id="ve" type="email" className={'form-control' + (err('email') ? ' is-invalid' : '')} value={form.email} onChange={e => set('email', e.target.value)} />
                            <div className="invalid-feedback">{err('email')}</div>
                        </div>
                    </div>
                    <div className="d-flex gap-2 mt-4">
                        <button className="btn btn-accent" disabled={busy}>{busy ? <span className="spinner-border spinner-border-sm" /> : (editId ? 'Save changes' : 'Add vendor')}</button>
                        <button type="button" className="btn btn-link" onClick={() => setForm(null)}>Cancel</button>
                    </div>
                </form>
            )}

            <div className="d-flex flex-wrap gap-2 mb-3">
                <input className="form-control" style={{ maxWidth: 280 }} placeholder="Search name, PIN or contact" value={search} onChange={e => setSearch(e.target.value)} aria-label="Search vendors" />
                <select className="form-select" style={{ maxWidth: 180 }} value={status} onChange={e => setStatus(e.target.value)} aria-label="Filter by status">
                    <option value="">All vendors</option>
                    <option value="active">Active</option>
                    <option value="blocked">Blocked</option>
                </select>
            </div>

            {!rows && !message && <div className="spinner-border text-success" role="status" />}
            {rows && rows.length === 0 && <div className="panel text-secondary">No vendors match.</div>}
            {rows && rows.length > 0 && (
                <div className="panel p-0 overflow-auto">
                    <table className="table table-hover align-middle m-0">
                        <thead><tr><th>Vendor</th><th>KRA PIN</th><th>Contact</th><th>Phone</th><th>Status</th>{canManage && <th />}</tr></thead>
                        <tbody>
                            {rows.map(v => (
                                <tr key={v.id}>
                                    <td className="fw-semibold">{v.name}<div className="small text-secondary fw-normal">{v.email}</div></td>
                                    <td>{v.tax_pin}</td>
                                    <td>{v.contact_person || '-'}</td>
                                    <td>{v.phone || '-'}</td>
                                    <td><span className={'badge text-bg-' + (v.status === 'active' ? 'success' : 'danger')}>{v.status === 'active' ? 'Active' : 'Blocked'}</span></td>
                                    {canManage && (
                                        <td className="text-end text-nowrap">
                                            <button className="btn btn-sm btn-outline-secondary me-2" onClick={() => open(v)}>Edit</button>
                                            <button className={'btn btn-sm ' + (v.status === 'active' ? 'btn-outline-danger' : 'btn-outline-success')} onClick={() => toggleBlock(v)}>
                                                {v.status === 'active' ? 'Block' : 'Unblock'}
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