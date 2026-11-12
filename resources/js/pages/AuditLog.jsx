import { useEffect, useState } from 'react';
import { api } from '../api';

const fmtTime = (s) => new Date(s).toLocaleString('en-GB', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' });
const tone = (a) => (/DENIED|FAILED|LOCKED|BLOCKED|REJECTED|CANCELLED|DEACTIVATED|EXCEPTION/.test(a) ? 'danger' : /APPROVED|MATCHED|SELECTED|ISSUED|CREATED|ADDED|RECEIVED/.test(a) ? 'success' : 'secondary');

export default function AuditLog() {
    const [resp, setResp] = useState(null);
    const [action, setAction] = useState('');
    const [userId, setUserId] = useState('');
    const [from, setFrom] = useState('');
    const [to, setTo] = useState('');
    const [page, setPage] = useState(1);
    const [open, setOpen] = useState(null);
    const [error, setError] = useState('');

    useEffect(() => { setPage(1); }, [action, userId, from, to]);
    useEffect(() => {
        setError(''); setOpen(null);
        api(`audit-logs?page=${page}&action=${action}&user_id=${userId}&from=${from}&to=${to}`).then(setResp).catch(e => setError(e.message));
    }, [page, action, userId, from, to]);

    return (
        <>
            <h2>Audit log</h2>
            <p className="text-secondary mb-4">A permanent record of who did what, and when. Entries cannot be edited or deleted.</p>

            <div className="d-flex flex-wrap gap-2 mb-3">
                <select className="form-select" style={{ maxWidth: 240 }} value={action} onChange={e => setAction(e.target.value)} aria-label="Action">
                    <option value="">All actions</option>
                    {(resp?.actions ?? []).map(a => <option key={a} value={a}>{a}</option>)}
                </select>
                <select className="form-select" style={{ maxWidth: 220 }} value={userId} onChange={e => setUserId(e.target.value)} aria-label="User">
                    <option value="">All users</option>
                    {(resp?.users ?? []).map(u => <option key={u.id} value={u.id}>{u.full_name}</option>)}
                </select>
                <input type="date" className="form-control" style={{ maxWidth: 170 }} value={from} onChange={e => setFrom(e.target.value)} aria-label="From date" />
                <input type="date" className="form-control" style={{ maxWidth: 170 }} value={to} onChange={e => setTo(e.target.value)} aria-label="To date" />
            </div>

            {error && <div className="alert alert-danger">{error}</div>}
            {!resp && !error && <div className="spinner-border text-success" role="status" />}
            {resp && resp.data.length === 0 && <div className="panel text-secondary">No entries match.</div>}
            {resp && resp.data.length > 0 && (
                <>
                    <div className="panel p-0 overflow-auto mb-3">
                        <table className="table align-middle m-0">
                            <thead><tr><th>When</th><th>User</th><th>Action</th><th>Record</th><th>IP address</th><th /></tr></thead>
                            <tbody>
                                {resp.data.map(l => (
                                    <>
                                        <tr key={l.id}>
                                            <td className="small text-nowrap">{fmtTime(l.at)}</td>
                                            <td>{l.user}{l.username && <div className="small text-secondary">{l.username}</div>}</td>
                                            <td><span className={`badge text-bg-${tone(l.action)}`}>{l.action}</span></td>
                                            <td className="small">{l.entity}{l.entity_id ? ` #${l.entity_id}` : ''}</td>
                                            <td className="small">{l.ip || '-'}</td>
                                            <td className="text-end">{(l.old || l.new) && <button className="btn btn-sm btn-outline-secondary" onClick={() => setOpen(open === l.id ? null : l.id)}>{open === l.id ? 'Hide' : 'Details'}</button>}</td>
                                        </tr>
                                        {open === l.id && (
                                            <tr key={l.id + '-d'}>
                                                <td colSpan="6">
                                                    {l.old && <div className="mb-2"><div className="small text-secondary text-uppercase">Before</div><pre className="audit-pre">{JSON.stringify(l.old, null, 2)}</pre></div>}
                                                    {l.new && <div><div className="small text-secondary text-uppercase">After</div><pre className="audit-pre">{JSON.stringify(l.new, null, 2)}</pre></div>}
                                                </td>
                                            </tr>
                                        )}
                                    </>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <div className="d-flex justify-content-between align-items-center mb-4">
                        <div className="small text-secondary">{resp.total} entries | page {resp.page} of {resp.pages}</div>
                        <div className="d-flex gap-2">
                            <button className="btn btn-sm btn-outline-secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>Previous</button>
                            <button className="btn btn-sm btn-outline-secondary" disabled={page >= resp.pages} onClick={() => setPage(page + 1)}>Next</button>
                        </div>
                    </div>
                </>
            )}
        </>
    );
}