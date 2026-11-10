import { useEffect, useState } from 'react';
import { Link, useLocation, useNavigate, useParams } from 'react-router-dom';
import { api } from '../api';
import StatusBadge from '../components/StatusBadge';
import { fmtDate, kes } from '../format';

const DECISION = { pending: ['Waiting', 'warning'], approved: ['Approved', 'success'], rejected: ['Rejected', 'danger'], returned: ['Returned', 'info'] };

export default function RequestDetail() {
    const { id } = useParams();
    const navigate = useNavigate();
    const flash = useLocation().state?.flash;
    const [r, setR] = useState(null);
    const [error, setError] = useState('');
    const [comment, setComment] = useState('');
    const [formError, setFormError] = useState('');
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        api(`requests/${id}`).then(d => setR(d.data)).catch(e => setError(e.status === 404 ? 'This request was not found, or you do not have access to it.' : e.message));
    }, [id]);

    async function cancel() {
        if (!window.confirm(`Cancel request ${r.request_no}? This cannot be undone.`)) return;
        try {
            const d = await api(`requests/${id}/cancel`, { method: 'POST' });
            setR(d.data);
        } catch (e) { setError(e.message); }
    }

    async function decide(decision) {
        setFormError('');
        if (decision !== 'approve' && !comment.trim()) { setFormError('Enter a comment explaining your decision.'); return; }
        const verb = { approve: 'approve', reject: 'reject', return: 'return for changes' }[decision];
        if (!window.confirm(`Are you sure you want to ${verb} request ${r.request_no}?`)) return;
        setBusy(true);
        try {
            await api(`requests/${id}/decision`, { method: 'POST', body: { decision, comment } });
            navigate('/approvals', { state: { flash: `Request ${r.request_no}: decision recorded.` } });
        } catch (e) { setFormError(e.errors?.comment?.[0] || e.message); } finally { setBusy(false); }
    }

    if (error) return <div className="alert alert-danger">{error} <Link to="/requests">Back to requests</Link></div>;
    if (!r) return <div className="spinner-border text-success" role="status" />;

    const lastRound = r.approvals.length ? r.approvals : [];

    return (
        <>
            <nav className="mb-2 small"><Link to="/requests">Requests</Link> / {r.request_no}</nav>
            {flash && <div className="alert alert-success py-2">{flash}</div>}

            <div className="d-flex justify-content-between align-items-start mb-3">
                <div>
                    <h2 className="fw-semibold mb-1" style={{ color: 'var(--brand)' }}>{r.request_no} <StatusBadge status={r.status} /></h2>
                    <div className="text-secondary">{r.category} | {r.branch} | requested by {r.requester}</div>
                </div>
                <div className="d-flex gap-2">
                    {r.can_edit && <Link to={`/requests/${r.id}/edit`} className="btn btn-brand">Edit and submit</Link>}
                    {r.can_cancel && <button className="btn btn-outline-danger" onClick={cancel}>Cancel request</button>}
                </div>
            </div>

            {r.budget_exception && <div className="alert alert-info py-2">This request is above the remaining branch budget, so Finance also has to approve it.</div>}
            {r.duplicate_flag && (
                <div className="alert alert-warning py-2">
                    <strong>Similar items were requested recently.</strong> Reason given: {r.duplicate_justification}
                </div>
            )}

            <div className="row g-3 mb-3">
                <div className="col-md-4"><div className="panel"><div className="label">Estimated total</div><div className="value">{kes(r.total_estimate)}</div></div></div>
                <div className="col-md-4"><div className="panel"><div className="label">Needed by</div><div className="value">{fmtDate(r.needed_by)}</div></div></div>
                <div className="col-md-4"><div className="panel"><div className="label">Submitted</div><div className="value">{fmtDate(r.submitted_at)}</div></div></div>
            </div>

            <div className="panel mb-3">
                <h5 className="fw-semibold mb-3">Items</h5>
                <div className="table-responsive">
                    <table className="table align-middle m-0">
                        <thead><tr><th>Item</th><th className="text-end">Quantity</th><th className="text-end">Unit cost</th><th className="text-end">Total</th></tr></thead>
                        <tbody>
                            {r.lines.map(l => (
                                <tr key={l.id}>
                                    <td>{l.item}</td>
                                    <td className="text-end">{l.quantity} {l.unit}</td>
                                    <td className="text-end">{kes(l.unit_cost_est)}</td>
                                    <td className="text-end">{kes(l.quantity * l.unit_cost_est)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <div className="mt-3"><span className="label small text-secondary text-uppercase">Justification</span><div>{r.justification || '-'}</div></div>
            </div>

            {r.can_decide && (
                <div className="panel mb-3" style={{ borderLeft: '4px solid var(--accent)' }}>
                    <h5 className="fw-semibold mb-2">Your decision</h5>
                    <p className="text-secondary small">You are approving at level {r.current_level}. A comment is required to return or reject.</p>
                    {formError && <div className="alert alert-danger py-2">{formError}</div>}
                    <textarea className="form-control mb-3" rows="3" value={comment} onChange={e => setComment(e.target.value)} placeholder="Comment" aria-label="Comment" />
                    <div className="d-flex gap-2">
                        <button className="btn btn-brand" disabled={busy} onClick={() => decide('approve')}>Approve</button>
                        <button className="btn btn-outline-secondary" disabled={busy} onClick={() => decide('return')}>Return for changes</button>
                        <button className="btn btn-outline-danger" disabled={busy} onClick={() => decide('reject')}>Reject</button>
                    </div>
                </div>
            )}

            <div className="panel mb-4">
                <h5 className="fw-semibold mb-3">Approvals</h5>
                {lastRound.length === 0 && <div className="text-secondary">Not submitted yet.</div>}
                {lastRound.map((a, i) => {
                    const [label, colour] = DECISION[a.decision] ?? [a.decision, 'secondary'];
                    return (
                        <div key={i} className="d-flex justify-content-between border-bottom py-2">
                            <div>
                                <strong>Level {a.level}</strong>: {a.approver} <span className="text-secondary">({a.role})</span>
                                {a.comment && <div className="small text-secondary">"{a.comment}"</div>}
                                {a.decided_at && <div className="small text-secondary">{fmtDate(a.decided_at)}</div>}
                            </div>
                            <span className={`badge text-bg-${colour} align-self-start`}>{label}</span>
                        </div>
                    );
                })}
            </div>
        </>
    );
}