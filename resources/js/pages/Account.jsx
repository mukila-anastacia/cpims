import { useState } from 'react';
import { api } from '../api';
import { useAuth } from '../AuthContext';

const blank = { current_password: '', password: '', password_confirmation: '' };

export default function Account() {
    const { user } = useAuth();
    const [f, setF] = useState({ ...blank });
    const [errors, setErrors] = useState({});
    const [message, setMessage] = useState('');
    const [flash, setFlash] = useState('');
    const [busy, setBusy] = useState(false);

    const set = (k, v) => setF(x => ({ ...x, [k]: v }));
    const err = (k) => errors[k]?.[0];

    async function submit(e) {
        e.preventDefault();
        setBusy(true); setErrors({}); setMessage(''); setFlash('');
        try {
            await api('me/password', { method: 'POST', body: f });
            setF({ ...blank });
            setFlash('Password changed. Any other sessions of yours were ended.');
        } catch (e2) {
            setErrors(e2.errors || {});
            setMessage(Object.keys(e2.errors || {}).length ? 'Please correct the fields marked below.' : e2.message);
        } finally { setBusy(false); }
    }

    return (
        <>
            <h2>Your account</h2>

            <div className="panel form-card">
                <div className="section-title">Your details</div>
                <div className="row g-3">
                    <div className="col-md-3"><div className="label">Name</div><div className="fw-semibold">{user.name}</div></div>
                    <div className="col-md-3"><div className="label">Username</div><div className="fw-semibold">{user.username}</div></div>
                    <div className="col-md-3"><div className="label">Role</div><div className="fw-semibold">{user.role}</div></div>
                    <div className="col-md-3"><div className="label">Branch</div><div className="fw-semibold">{user.branch}</div></div>
                </div>

                <hr />

                <form onSubmit={submit} noValidate style={{ maxWidth: 460 }}>
                    <div className="section-title">Change password</div>
                    {flash && <div className="alert alert-success py-2">{flash}</div>}
                    {message && <div className="alert alert-danger py-2" role="alert">{message}</div>}

                    <div className="mb-3">
                        <label className="form-label" htmlFor="cp">Current password</label>
                        <input id="cp" type="password" autoComplete="current-password" className={'form-control' + (err('current_password') ? ' is-invalid' : '')} value={f.current_password} onChange={e => set('current_password', e.target.value)} />
                        <div className="invalid-feedback">{err('current_password')}</div>
                    </div>
                    <div className="mb-3">
                        <label className="form-label" htmlFor="np">New password</label>
                        <input id="np" type="password" autoComplete="new-password" className={'form-control' + (err('password') ? ' is-invalid' : '')} value={f.password} onChange={e => set('password', e.target.value)} />
                        <div className="invalid-feedback">{err('password')}</div>
                        <div className="form-text">At least 8 characters, with letters and numbers.</div>
                    </div>
                    <div className="mb-4">
                        <label className="form-label" htmlFor="rp">Repeat new password</label>
                        <input id="rp" type="password" autoComplete="new-password" className="form-control" value={f.password_confirmation} onChange={e => set('password_confirmation', e.target.value)} />
                    </div>
                    <button className="btn btn-accent" disabled={busy}>{busy ? <span className="spinner-border spinner-border-sm" /> : 'Change password'}</button>
                </form>
            </div>
        </>
    );
}