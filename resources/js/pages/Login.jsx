import { useState } from 'react';
import { useAuth } from '../AuthContext';

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
        <div className="login-wrap">
            <section className="login-side">
                <div className="tag">A-SOLAR POWER WORKS</div>
                <h1>One view of procurement across all nine branches.</h1>
                <ul>
                    <li>Requests, approvals and budgets in one place</li>
                    <li>Quotations compared and duplicates flagged</li>
                    <li>Orders, deliveries and invoices matched</li>
                    <li>Every action recorded in an audit trail</li>
                </ul>
            </section>
            <section className="login-main">
                <form className="login-card" onSubmit={submit} noValidate>
                    <h2>Log in</h2>
                    <p className="text-secondary mb-4">Use your CPIMS username and password.</p>

                    {notice && <div className="alert alert-warning py-2">{notice}</div>}
                    {error && <div className="alert alert-danger py-2" role="alert">{error}</div>}

                    <div className="mb-3">
                        <label htmlFor="username" className="form-label">Username</label>
                        <input id="username" className="form-control form-control-lg" autoComplete="username"
                               autoFocus value={username} onChange={e => setUsername(e.target.value)} />
                    </div>
                    <div className="mb-4">
                        <label htmlFor="password" className="form-label">Password</label>
                        <div className="input-group input-group-lg">
                            <input id="password" className="form-control" autoComplete="current-password"
                                   type={show ? 'text' : 'password'} value={password}
                                   onChange={e => setPassword(e.target.value)} />
                            <button type="button" className="btn btn-outline-secondary" onClick={() => setShow(!show)}>
                                {show ? 'Hide' : 'Show'}
                            </button>
                        </div>
                    </div>
                    <button className="btn btn-brand btn-lg w-100" disabled={busy}>
                        {busy ? <span className="spinner-border spinner-border-sm" /> : 'Log in'}
                    </button>
                    <p className="text-secondary small mt-4 mb-0">
                        Accounts lock for 15 minutes after 5 failed attempts. Sessions end after 15 minutes of inactivity.
                    </p>
                </form>
            </section>
        </div>
    );
}