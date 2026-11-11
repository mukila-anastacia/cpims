import { useAuth } from '../AuthContext';
import { TASKS } from '../menu';

export default function Dashboard() {
    const { user } = useAuth();
    const first = user.name.split(' ')[0];

    return (
        <>
            <section className="hero">
                <div className="eyebrow">A-Solar Power Works | {user.role}</div>
                <div className="hero-title">Welcome,<br /><span>{first}</span></div>
                <p>Here is your workspace. Everything you need for procurement is on the left.</p>
            </section>

            <div className="row g-3 mb-4">
                <div className="col-md-4"><div className="panel"><div className="label">Role</div><div className="value">{user.role}</div></div></div>
                <div className="col-md-4"><div className="panel"><div className="label">Branch</div><div className="value">{user.branch}</div></div></div>
                <div className="col-md-4"><div className="panel"><div className="label">Access</div><div className="value">{user.is_head_office ? 'All branches' : 'Own branch'}</div></div></div>
            </div>

            <h5 className="mb-3">What you will do here</h5>
            {(TASKS[user.role] ?? []).map(t => <div key={t} className="task">{t}</div>)}
        </>
    );
}