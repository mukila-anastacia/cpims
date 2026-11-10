import { useState } from 'react';
import { useAuth } from '../AuthContext';

function SunMark() {
    return (
        <svg width="38" height="38" viewBox="0 0 38 38" aria-hidden="true">
            <circle cx="19" cy="19" r="8" fill="#f28c28" />
            <g stroke="#f28c28" strokeWidth="2.4" strokeLinecap="round">
                <line x1="19" y1="3" x2="19" y2="8" /><line x1="19" y1="30" x2="19" y2="35" />
                <line x1="3" y1="19" x2="8" y2="19" /><line x1="30" y1="19" x2="35" y2="19" />
                <line x1="7.7" y1="7.7" x2="11.2" y2="11.2" /><line x1="26.8" y1="26.8" x2="30.3" y2="30.3" />
                <line x1="30.3" y1="7.7" x2="26.8" y2="11.2" /><line x1="11.2" y1="26.8" x2="7.7" y2="30.3" />
            </g>
        </svg>
    );
}

export default function Login() {
    const { login, notice } = useAuth();
    const [username, setUsername] = useState('');
    const [password, setPassword] = useState('');
    const [show, setShow] = useState(false);
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);

    async function submit(e) {
        e.preventDefault();
        setError('');
        setBusy(true);
        try {
            await login(username.trim(), password);
        } catch (err) {
            setError(err.status === 422 ? 'Enter your username and password.' : err.message);
        } finally {
            setBusy(false);
        }
    }

    return (
        <div className="login-page" data-bs-theme="dark">
            <header className="login-top">
                <SunMark />
                <div>A-Solar Power Works<small>PROCUREMENT SYSTEM</small></div>
            </header>

            <main className="login-center">
                <form className="login-card" onSubmit={submit} noValidate>
                    <h1>Welcome back</h1>
                    <p className="sub">Log in to continue.</p>

                    {notice && <div className="alert alert-warning py-2">{notice}</div>}
                    {error && <div className="alert alert-danger py-2" role="alert">{error}</div>}

                    <div className="form-floating mb-3">
                        <input id="username" className="form-control" placeholder="Username" autoComplete="username"
                               autoFocus value={username} onChange={e => setUsername(e.target.value)} />
                        <label htmlFor="username">Username</label>
                    </div>
                    <div className="form-floating mb-4">
                        <input id="password" className="form-control" placeholder="Password" autoComplete="current-password"
                               type={show ? 'text' : 'password'} value={password} style={{ paddingRight: 64 }}
                               onChange={e => setPassword(e.target.value)} />
                        <label htmlFor="password">Password</label>
                        <button type="button" className="pw-toggle" onClick={() => setShow(!show)}>{show ? 'Hide' : 'Show'}</button>
                    </div>

                    <button className="btn btn-accent btn-lg w-100" disabled={busy}>
                        {busy ? <span className="spinner-border spinner-border-sm" /> : 'Log in'}
                    </button>
                </form>
            </main>

            <footer className="login-foot">REQUESTS &nbsp;|&nbsp; APPROVALS &nbsp;|&nbsp; ORDERS &nbsp;|&nbsp; INVOICES &nbsp;|&nbsp; AUDIT TRAIL</footer>
        </div>
    );
}