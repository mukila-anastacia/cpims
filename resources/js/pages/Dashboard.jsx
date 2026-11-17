import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../api';
import { useAuth } from '../AuthContext';
import { TASKS } from '../menu';

export default function Dashboard() {
    const { user } = useAuth();
    const [cards, setCards] = useState(null);
    const [error, setError] = useState('');

    const load = () => {
        setError('');
        setCards(null);
        api('dashboard').then(d => setCards(d.cards)).catch(e => setError(e.message));
    };
    useEffect(() => { load(); }, []);

    return (
        <>
            <section className="hero">
                <div className="hero-title">Welcome,<br />{user.name}</div>
                <p>{user.role} at {user.branch}.</p>
            </section>

            <div className="row g-3">
                {error && (
                    <div className="col-12">
                        <div className="alert alert-danger d-flex justify-content-between align-items-center mb-0">
                            <span>{error}</span>
                            <button className="btn btn-sm btn-outline-light" onClick={load}>Try again</button>
                        </div>
                    </div>
                )}
                {!cards && !error && <div className="col-12"><div className="spinner-border text-success" role="status" /></div>}
                {cards && cards.map(c => (
                    <div key={c.key} className="col-lg-3 col-md-6">
                        <Link to={c.link} className="panel stat-card d-block text-decoration-none">
                            <div className="label">{c.label}</div>
                            <div className={'stat-value' + (c.value > 0 && /exception|waiting|returned|over/i.test(c.label) ? ' attention' : '')}>{c.value}</div>
                        </Link>
                    </div>
                ))}
            </div>

            <h5>What you will do here</h5>
            <div className="tasks">
                {(TASKS[user.role] ?? []).map(t => <div key={t} className="task">{t}</div>)}
            </div>
        </>
    );
}