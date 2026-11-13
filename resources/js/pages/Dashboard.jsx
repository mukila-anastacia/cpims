import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../api';
import { useAuth } from '../AuthContext';
import { TASKS } from '../menu';

export default function Dashboard() {
    const { user } = useAuth();
    const first = user.name.split(' ')[0];
    const [cards, setCards] = useState(null);

    useEffect(() => { api('dashboard').then(d => setCards(d.cards)).catch(() => setCards([])); }, []);

    return (
        <>
            <section className="hero">
                <div className="eyebrow">A-Solar Power Works | {user.role} | {user.branch}</div>
                <div className="hero-title">Welcome,<br />{first}</div>
                <p>Here is where things stand today. Select a card to open it.</p>
            </section>

            <div className="row g-3 mb-4">
                {!cards && <div className="col-12"><div className="spinner-border text-success" role="status" /></div>}
                {cards && cards.map(c => (
                    <div key={c.key} className="col-lg-3 col-md-6">
                        <Link to={c.link} className="panel stat-card d-block text-decoration-none">
                            <div className="label">{c.label}</div>
                            <div className={'stat-value' + (c.value > 0 && /exception|waiting|returned|over/i.test(c.label) ? ' attention' : '')}>{c.value}</div>
                        </Link>
                    </div>
                ))}
            </div>

            <h5 className="mb-3">What you will do here</h5>
            {(TASKS[user.role] ?? []).map(t => <div key={t} className="task">{t}</div>)}
        </>
    );
}