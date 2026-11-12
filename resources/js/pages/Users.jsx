import { useEffect, useState } from 'react';
import { api } from '../api';
import { fmtDate } from '../format';

const HEAD = ['Procurement Administrator', 'Finance Officer', 'Head Office Manager', 'System Administrator'];
const blank = { full_name: '', username: '', email: '', role_id: '', branch_id: '', password: '', status: 'active' };
const BADGE = { active: 'success', inactive: 'secondary', locked: 'danger' };

export default function Users() {
    const [resp, setResp] = useState(null);
    const [search, setSearch] = useState('');
    const [roleF, setRoleF] = useState('');
    const [statusF, setStatusF] = useState('');
    const [form, setForm] = useState(null);
    const [editing, setEditing] = useState(null);
    const [errors, setErrors] = useState({});
    const [message, setMessage] = useState('');
    const [flash, setFlash] = useState('');
    const [busy, setBusy] = useState(false);

    const load = () => api(`users?search=${encodeURIComponent(search)}&role_id=${roleF}&status=${statusF}`).then(setResp).catch(e => setMessage(e.message));
    useEffect(() => { const t = setTimeout(load, 250); return () => clearTimeout(t); }, [search, roleF, statusF]);

    const roles = resp?.roles ?? [];
    const branches = resp?.branches ?? [];
    const err = (k) => errors[k]?.[0];
    const set = (k, v) => setForm(f => ({ ...f, [k]: v }));

    function changeRole(v) {
        const role = roles.find(r => String(r.id) === v);
        const patch = { role_id: v };
        if (role && HEAD.includes(role.name)) {
            const hq = branches.find(b => b.is_head_office);
            if (hq) patch.branch_id = String(hq.id);
        }
        setForm(f => ({ ...f, ...patch }));
    }

    function open(u = null) {
        setErrors({}); setMessage(''); setFlash('');
        setEditing(u);
        setForm(u ? { full_name: u.full_name, username: u.username, email: u.email, role_id: String(u.role_id), branch_id: String(u.branch_id), password: '', status: u.status } : { ...blank });
    }

    async function save(e) {
        e.preventDefault();
        setBusy(true); setErrors({}); setMessage('');
        try {
            if (editing) {
                await api(`users/${editing.id}`, { method: 'PUT', body: { full_name: form.full_name, email: form.email, role_id: form.role_id, branch_id: form.branch_id, status: form.status, password: form.password || null } });
            } else {
                await api('users', { method: 'POST', body: form });
            }
            setFlash(editing ? 'User updated.' : 'User created.');
            setForm(null);
            load();
        } catch (e2) {
            setErrors(e2.errors || {});
            setMessage(Object.keys(e2.errors || {}).length ? 'Please correct the fields marked below.' : e2.message);
        } finally { setBusy(false); }
    }

    return (
        <>
            <div className="d-flex justify-content-between align-items-center mb-1">
                <h2 className="m-0">Users and roles</h2>
                {!form && <button className="btn btn-brand" onClick={() => open()}>Add user</button>}
            </div>
            <p className="text-secondary mb-4">Create accounts, assign roles and branches, and unlock or deactivate users.</p>

            {flash && <div className="alert alert-success py-2">{flash}</div>}
            {message && !form && <div className="alert alert-danger py-2">{message}</div>}

            {form && (
                <form className="panel form-card mb-4" onSubmit={save} noValidate>
                    <div className="section-title">{editing ? `Edit ${editing.username}` : 'New user'}</div>
                    {message && <div className="alert alert-danger py-2">{message}</div>}
                    <div className="row g-3">
                        <div className="col-md-6">
                            <label className="form-label" htmlFor="uf">Full name</label>
                            <input id="uf" className={'form-control' + (err('full_name') ? ' is-invalid' : '')} value={form.full_name} onChange={e => set('full_name', e.target.value)} />
                            <div className="invalid-feedback">{err('full_name')}</div>
                        </div>
                        <div className="col-md-6">
                            <label className="form-label" htmlFor="un">Username</label>
                            <input id="un" className={'form-control' + (err('username') ? ' is-invalid' : '')} value={form.username} disabled={!!editing} onChange={e => set('username', e.target.value)} />
                            <div className="invalid-feedback">{err('username')}</div>
                        </div>
                        <div className="col-md-6">
                            <label className="form-label" htmlFor="ue">Email</label>
                            <input id="ue" type="email" className={'form-control' + (err('email') ? ' is-invalid' : '')} value={form.email} onChange={e => set('email', e.target.value)} />
                            <div className="invalid-feedback">{err('email')}</div>
                        </div>
                        <div className="col-md-6">
                            <label className="form-label" htmlFor="up">{editing ? 'Reset password (leave empty to keep)' : 'Password'}</label>
                            <input id="up" type="password" autoComplete="new-password" className={'form-control' + (err('password') ? ' is-invalid' : '')} value={form.password} onChange={e => set('password', e.target.value)} />
                            <div className="invalid-feedback">{err('password')}</div>
                            <div className="form-text">At least 8 characters, with letters and numbers.</div>
                        </div>
                        <div className="col-md-4">
                            <label className="form-label" htmlFor="ur">Role</label>
                            <select id="ur" className={'form-select' + (err('role_id') ? ' is-invalid' : '')} value={form.role_id} onChange={e => changeRole(e.target.value)}>
                                <option value="">Choose a role</option>
                                {roles.map(r => <option key={r.id} value={r.id}>{r.name}</option>)}
                            </select>
                            <div className="invalid-feedback">{err('role_id')}</div>
                        </div>
                        <div className="col-md-4">
                            <label className="form-label" htmlFor="ub">Branch</label>
                            <select id="ub" className={'form-select' + (err('branch_id') ? ' is-invalid' : '')} value={form.branch_id} onChange={e => set('branch_id', e.target.value)}>
                                <option value="">Choose a branch</option>
                                {branches.map(b => <option key={b.id} value={b.id}>{b.name}{b.is_head_office ? ' (head office)' : ''}</option>)}
                            </select>
                            <div className="invalid-feedback">{err('branch_id')}</div>
                        </div>
                        {editing && (
                            <div className="col-md-4">
                                <label className="form-label" htmlFor="us">Status</label>
                                <select id="us" className={'form-select' + (err('status') ? ' is-invalid' : '')} value={form.status} onChange={e => set('status', e.target.value)}>
                                    <option value="active">Active{editing.status === 'locked' ? ' (unlocks the account)' : ''}</option>
                                    <option value="inactive">Inactive</option>
                                    {editing.status === 'locked' && <option value="locked">Locked</option>}
                                </select>
                                <div className="invalid-feedback">{err('status')}</div>
                            </div>
                        )}
                    </div>
                    <div className="d-flex gap-2 mt-4">
                        <button className="btn btn-accent" disabled={busy}>{busy ? <span className="spinner-border spinner-border-sm" /> : (editing ? 'Save changes' : 'Create user')}</button>
                        <button type="button" className="btn btn-link" onClick={() => setForm(null)}>Cancel</button>
                    </div>
                </form>
            )}

            <div className="d-flex flex-wrap gap-2 mb-3">
                <input className="form-control" style={{ maxWidth: 260 }} placeholder="Search name, username or email" value={search} onChange={e => setSearch(e.target.value)} aria-label="Search users" />
                <select className="form-select" style={{ maxWidth: 230 }} value={roleF} onChange={e => setRoleF(e.target.value)} aria-label="Filter by role">
                    <option value="">All roles</option>
                    {roles.map(r => <option key={r.id} value={r.id}>{r.name}</option>)}
                </select>
                <select className="form-select" style={{ maxWidth: 160 }} value={statusF} onChange={e => setStatusF(e.target.value)} aria-label="Filter by status">
                    <option value="">All statuses</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                    <option value="locked">Locked</option>
                </select>
            </div>

            {!resp && !message && <div className="spinner-border text-success" role="status" />}
            {resp && resp.data.length === 0 && <div className="panel text-secondary">No users match.</div>}
            {resp && resp.data.length > 0 && (
                <div className="panel p-0 overflow-auto">
                    <table className="table table-hover align-middle m-0">
                        <thead><tr><th>User</th><th>Role</th><th>Branch</th><th>Status</th><th>Last login</th><th /></tr></thead>
                        <tbody>
                            {resp.data.map(u => (
                                <tr key={u.id}>
                                    <td className="fw-semibold">{u.full_name}{u.is_self && <span className="badge text-bg-info ms-2">You</span>}<div className="small text-secondary fw-normal">{u.username} | {u.email}</div></td>
                                    <td>{u.role}</td>
                                    <td>{u.branch}</td>
                                    <td><span className={`badge text-bg-${BADGE[u.status] ?? 'secondary'}`}>{u.status[0].toUpperCase() + u.status.slice(1)}</span></td>
                                    <td className="small">{u.last_login_at ? fmtDate(u.last_login_at) : 'Never'}</td>
                                    <td className="text-end"><button className="btn btn-sm btn-outline-secondary" onClick={() => open(u)}>Edit</button></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </>
    );
}