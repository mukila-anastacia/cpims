import { useAuth } from '../AuthContext';
import { TASKS } from '../menu';

export default function Dashboard() {
    const { user } = useAuth();
    const first = user.name.split(' ')[0];

    return (
        <>
            <h2 className="fw-semibold mb-1" style={{ color: 'var(--brand)' }}>Welcome, {first}</h2>
            <p className="text-secondary mb-4">Here is your workspace at A-Solar Power Works.</p>

            <div className="row g-3 mb-4">
                <div className="col-md-4"><div className="panel"><div className="label">Role</div><div className="value">{user.role}</div></div></div>
                <div className="col-md-4"><div className="panel"><div className="label">Branch</div><div className="value">{user.branch}</div></div></div>
                <div className="col-md-4"><div className="panel"><div className="label">Access</div><div className="value">{user.is_head_office ? 'All branches' : 'Own branch'}</div></div></div>
            </div>

            <h5 className="fw-semibold mb-3" style={{ color: 'var(--brand)' }}>What you will do here</h5>
            {(TASKS[user.role] ?? []).map(t => <div key={t} className="task">{t}</div>)}
            <p className="text-secondary small mt-4">More screens appear here as each part of the system is built.</p>
        </>
    );
}