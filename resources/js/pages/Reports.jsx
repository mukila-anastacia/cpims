import { useEffect, useState } from 'react';
import { api } from '../api';
import { useAuth } from '../AuthContext';
import { STATUS, kes } from '../format';

const dur = (h) => {
    if (h === null || h === undefined) return 'No data yet';
    if (h < 1) return `${Math.max(1, Math.round(h * 60))} min`;
    if (h < 48) return `${h.toFixed(1)} hours`;
    return `${(h / 24).toFixed(1)} days`;
};
const pct = (v) => (v === null || v === undefined ? 'No data yet' : `${v}%`);

function Bars({ rows, empty }) {
    if (!rows.length) return <div className="text-secondary small">{empty}</div>;
    const max = Math.max(1, ...rows.map(r => r.total));
    return rows.map(r => (
        <div key={r.name} className="mb-3">
            <div className="d-flex justify-content-between small">
                <span className="fw-semibold">{r.name}</span>
                <span>{kes(r.total)} <span className="text-secondary">({r.orders} {r.orders === 1 ? 'order' : 'orders'})</span></span>
            </div>
            <div className="bar-track"><div className="bar-fill" style={{ width: (r.total / max * 100) + '%' }} /></div>
        </div>
    ));
}

export default function Reports() {
    const { user } = useAuth();
    const isBM = user.role === 'Branch Manager';
    const [data, setData] = useState(null);
    const [from, setFrom] = useState('');
    const [to, setTo] = useState('');
    const [branch, setBranch] = useState('');
    const [error, setError] = useState('');

    const query = () => {
        const p = new URLSearchParams();
        if (from) p.set('from', from);
        if (to) p.set('to', to);
        if (branch && !isBM) p.set('branch_id', branch);
        return p;
    };
    useEffect(() => {
        setError('');
        api(`reports/summary?${query()}`).then(setData).catch(e => setError(e.message));
    }, [from, to, branch]);

    const exportUrl = (type) => { const p = query(); p.set('type', type); return `/api/reports/export?${p}`; };

    if (error) return <div className="alert alert-danger">{error}</div>;
    if (!data) return <div className="spinner-border text-success" role="status" />;

    const i = data.indicators;
    const statusRows = Object.entries(data.by_status).sort((a, b) => b[1] - a[1]);

    return (
        <>
            <div className="d-flex justify-content-between align-items-center mb-1">
                <h2 className="m-0">Reports</h2>
                <button className="btn btn-outline-secondary no-print" onClick={() => window.print()}>Print</button>
            </div>
            <p className="text-secondary mb-4">
                Project indicators for {isBM ? `${user.branch} branch` : (branch ? data.branches.find(b => String(b.id) === branch)?.name : 'all branches')}
                {from || to ? ` | ${from || 'start'} to ${to || 'today'}` : ' | all time'}. Generated {new Date().toLocaleString('en-GB')}.
            </p>

            <div className="d-flex flex-wrap gap-2 mb-4 no-print">
                <div><label className="form-label small mb-1" htmlFor="rf">From</label><input id="rf" type="date" className="form-control" value={from} onChange={e => setFrom(e.target.value)} /></div>
                <div><label className="form-label small mb-1" htmlFor="rt">To</label><input id="rt" type="date" className="form-control" value={to} onChange={e => setTo(e.target.value)} /></div>
                {!isBM && (
                    <div>
                        <label className="form-label small mb-1" htmlFor="rb">Branch</label>
                        <select id="rb" className="form-select" value={branch} onChange={e => setBranch(e.target.value)}>
                            <option value="">All branches</option>
                            {data.branches.map(b => <option key={b.id} value={b.id}>{b.name}</option>)}
                        </select>
                    </div>
                )}
            </div>

            <div className="row g-3 mb-4">
                <div className="col-lg-4 col-md-6"><div className="panel">
                    <div className="label">Approval turnaround</div>
                    <div className="value">{dur(i.approval_turnaround.avg_hours)}</div>
                    <div className="small text-secondary">Average over {i.approval_turnaround.count} approved {i.approval_turnaround.count === 1 ? 'request' : 'requests'}.</div>
                    <div className="small mt-1"><strong>Before the system:</strong> {data.baseline.approval_turnaround}</div>
                </div></div>
                <div className="col-lg-4 col-md-6"><div className="panel">
                    <div className="label">Request-to-order time</div>
                    <div className="value">{dur(i.request_to_order.avg_hours)}</div>
                    <div className="small text-secondary">From submission to purchase order, over {i.request_to_order.count} {i.request_to_order.count === 1 ? 'order' : 'orders'}.</div>
                </div></div>
                <div className="col-lg-4 col-md-6"><div className="panel">
                    <div className="label">Budget compliance</div>
                    <div className="value">{pct(i.budget_compliance.pct)}</div>
                    <div className="small text-secondary">{i.budget_compliance.exceptions} of {i.submitted} submitted requests were above the remaining budget.</div>
                </div></div>
                <div className="col-lg-4 col-md-6"><div className="panel">
                    <div className="label">Duplicate purchases flagged</div>
                    <div className="value">{i.duplicates.flagged}</div>
                    <div className="small text-secondary">{pct(i.duplicates.pct)} of submitted requests matched a recent request for the same item.</div>
                </div></div>
                <div className="col-lg-4 col-md-6"><div className="panel">
                    <div className="label">Reporting time</div>
                    <div className="value">{i.reporting_time_ms} ms</div>
                    <div className="small text-secondary">Time the system took to produce this consolidated report. Before: hours of phone calls.</div>
                </div></div>
                <div className="col-lg-4 col-md-6"><div className="panel">
                    <div className="label">Requests submitted</div>
                    <div className="value">{i.submitted}</div>
                    {data.open_invoice_exceptions !== null && <div className="small text-secondary">{data.open_invoice_exceptions} invoice {data.open_invoice_exceptions === 1 ? 'exception' : 'exceptions'} open.</div>}
                </div></div>
            </div>

            <div className="row g-3 mb-4">
                <div className="col-lg-6"><div className="panel"><h5 className="mb-3">Spend by branch</h5><Bars rows={data.spend_by_branch} empty="No orders issued in this period." /></div></div>
                <div className="col-lg-6"><div className="panel"><h5 className="mb-3">Spend by vendor</h5><Bars rows={data.spend_by_vendor} empty="No orders issued in this period." /></div></div>
            </div>

            <div className="row g-3 mb-4">
                <div className="col-lg-6"><div className="panel">
                    <h5 className="mb-3">Requests by status</h5>
                    {statusRows.length === 0 && <div className="text-secondary small">No requests in this period.</div>}
                    {statusRows.map(([s, n]) => (
                        <div key={s} className="d-flex justify-content-between border-bottom py-2"><span>{STATUS[s]?.[0] ?? s}</span><strong>{n}</strong></div>
                    ))}
                </div></div>
                <div className="col-lg-6"><div className="panel">
                    <h5 className="mb-3">Approvals waiting</h5>
                    <div className="d-flex justify-content-between border-bottom py-2"><span>Up to 1 day</span><strong>{data.ageing.d0_1}</strong></div>
                    <div className="d-flex justify-content-between border-bottom py-2"><span>2 to 3 days</span><strong>{data.ageing.d2_3}</strong></div>
                    <div className="d-flex justify-content-between py-2"><span>4 days or more</span><strong className={data.ageing.d4_plus > 0 ? 'text-danger' : ''}>{data.ageing.d4_plus}</strong></div>
                </div></div>
            </div>

            <div className="panel mb-4 no-print">
                <h5 className="mb-2">Export to Excel</h5>
                <p className="text-secondary small mb-3">Downloads a CSV file for the filters above. Excel opens it directly. Each export is recorded in the audit log.</p>
                <div className="d-flex flex-wrap gap-2">
                    <a className="btn btn-outline-secondary" href={exportUrl('requests')}>Requests</a>
                    <a className="btn btn-outline-secondary" href={exportUrl('orders')}>Orders</a>
                    {!isBM && <a className="btn btn-outline-secondary" href={exportUrl('invoices')}>Invoices</a>}
                </div>
            </div>
        </>
    );
}