import { useEffect, useState } from 'react';
import { api } from '../api';
import { kes } from '../format';

export default function Settings() {
    const [data, setData] = useState(null);
    const [values, setValues] = useState({});
    const [ap, setAp] = useState(null);
    const [error, setError] = useState('');
    const [flash, setFlash] = useState('');
    const [errA, setErrA] = useState({});
    const [errB, setErrB] = useState({});
    const [msgA, setMsgA] = useState('');
    const [msgB, setMsgB] = useState('');
    const [busy, setBusy] = useState('');

    function apply(d) {
        setData(d);
        const v = {};
        d.settings.forEach(s => { v[s.key] = s.value; });
        setValues(v);
        setAp({
            level2_min: d.approval.level2_min,
            level2_role_id: String(d.approval.level2_role_id ?? ''),
            level3_enabled: d.approval.level3_enabled,
            level3_min: d.approval.level3_min,
            level3_role_id: String(d.approval.level3_role_id ?? ''),
        });
    }
    useEffect(() => { api('settings').then(apply).catch(e => setError(e.message)); }, []);

    if (error) return <div className="alert alert-danger">{error}</div>;
    if (!data || !ap) return <div className="spinner-border text-success" role="status" />;

    const edit = data.can_edit;
    const roleName = (id) => data.roles.find(r => String(r.id) === String(id))?.name ?? 'the chosen role';
    const setA = (k, v) => setAp(x => ({ ...x, [k]: v }));

    const l2 = Number(ap.level2_min) || 0;
    const l3 = Number(ap.level3_min) || 0;
    const preview = [
        `Up to ${kes(l2)}: the Branch Manager approves.`,
        ap.level3_enabled
            ? `Above ${kes(l2)} up to ${kes(l3)}: the Branch Manager, then ${roleName(ap.level2_role_id)}.`
            : `Above ${kes(l2)}: the Branch Manager, then ${roleName(ap.level2_role_id)}.`,
        ap.level3_enabled ? `Above ${kes(l3)}: the Branch Manager, then ${roleName(ap.level2_role_id)}, then ${roleName(ap.level3_role_id)}.` : null,
        'A request above the branch budget also needs Finance, whatever its value.',
    ].filter(Boolean);

    async function saveApproval(e) {
        e.preventDefault();
        setBusy('approval'); setErrA({}); setMsgA(''); setFlash('');
        try {
            const d = await api('approval-rules', {
                method: 'PUT',
                body: {
                    level2_min: ap.level2_min,
                    level2_role_id: ap.level2_role_id,
                    level3_enabled: ap.level3_enabled,
                    level3_min: ap.level3_enabled ? ap.level3_min : null,
                    level3_role_id: ap.level3_enabled ? ap.level3_role_id : null,
                },
            });
            apply(d);
            setFlash('Approval limits saved. They apply to requests submitted from now on.');
        } catch (e2) {
            setErrA(e2.errors || {});
            setMsgA(Object.keys(e2.errors || {}).length ? 'Please correct the fields marked below.' : e2.message);
        } finally { setBusy(''); }
    }

    async function saveRules(e) {
        e.preventDefault();
        setBusy('rules'); setErrB({}); setMsgB(''); setFlash('');
        try {
            apply(await api('settings', { method: 'PUT', body: values }));
            setFlash('Business rules saved.');
        } catch (e2) {
            setErrB(e2.errors || {});
            setMsgB(Object.keys(e2.errors || {}).length ? 'Please correct the fields marked below.' : e2.message);
        } finally { setBusy(''); }
    }

    return (
        <>
            <div className="page-head">
                <div>
                    <h2>Settings</h2>
                    <p className="text-secondary m-0">{edit ? 'Approval limits and business rules for the whole system.' : 'Approval limits and business rules. Only the System Administrator can change them.'}</p>
                </div>
            </div>

            {flash && <div className="alert alert-success py-2 mb-0">{flash}</div>}

            <form className="panel form-card" onSubmit={saveApproval} noValidate>
                <fieldset disabled={!edit} className="border-0 p-0 m-0">
                    <div className="section-title">Approval limits</div>
                    {msgA && <div className="alert alert-danger py-2">{msgA}</div>}

                    <div className="rule-row mb-3">
                        <div className="fw-semibold pb-2">Level 1</div>
                        <div className="pb-2">Branch Manager (always)</div>
                        <div className="pb-2 text-secondary">Starts at KES 0</div>
                    </div>

                    <div className="rule-row mb-3">
                        <div className="fw-semibold pb-2">Level 2</div>
                        <div>
                            <label className="form-label" htmlFor="r2">Role</label>
                            <select id="r2" className={'form-select' + (errA.level2_role_id ? ' is-invalid' : '')} value={ap.level2_role_id} onChange={e => setA('level2_role_id', e.target.value)}>
                                {data.roles.map(r => <option key={r.id} value={r.id}>{r.name}</option>)}
                            </select>
                            <div className="invalid-feedback">{errA.level2_role_id?.[0]}</div>
                        </div>
                        <div>
                            <label className="form-label" htmlFor="m2">Starts above (KES)</label>
                            <input id="m2" type="number" min="0" step="any" className={'form-control' + (errA.level2_min ? ' is-invalid' : '')} value={ap.level2_min} onChange={e => setA('level2_min', e.target.value)} />
                            <div className="invalid-feedback">{errA.level2_min?.[0]}</div>
                        </div>
                    </div>

                    <div className="rule-row mb-3">
                        <div className="fw-semibold pb-2">Level 3</div>
                        <div>
                            <label className="form-label" htmlFor="r3">Role</label>
                            <select id="r3" className={'form-select' + (errA.level3_role_id ? ' is-invalid' : '')} value={ap.level3_role_id} disabled={!ap.level3_enabled} onChange={e => setA('level3_role_id', e.target.value)}>
                                {data.roles.map(r => <option key={r.id} value={r.id}>{r.name}</option>)}
                            </select>
                            <div className="invalid-feedback">{errA.level3_role_id?.[0]}</div>
                        </div>
                        <div>
                            <label className="form-label" htmlFor="m3">Starts above (KES)</label>
                            <input id="m3" type="number" min="0" step="any" className={'form-control' + (errA.level3_min ? ' is-invalid' : '')} value={ap.level3_min} disabled={!ap.level3_enabled} onChange={e => setA('level3_min', e.target.value)} />
                            <div className="invalid-feedback">{errA.level3_min?.[0]}</div>
                        </div>
                    </div>

                    <div className="form-check mb-3">
                        <input id="en3" type="checkbox" className="form-check-input" checked={ap.level3_enabled} onChange={e => setA('level3_enabled', e.target.checked)} />
                        <label className="form-check-label" htmlFor="en3">Use a third approval level</label>
                    </div>

                    <div className="section-title mt-4">What this means</div>
                    <ul className="rule-preview mb-3">{preview.map(t => <li key={t}>{t}</li>)}</ul>
                    <div className="small text-secondary mb-3">Requests already in approval keep their approvers.</div>

                    {edit && <button className="btn btn-accent" disabled={busy === 'approval'}>{busy === 'approval' ? <span className="spinner-border spinner-border-sm" /> : 'Save approval limits'}</button>}
                </fieldset>
            </form>

            <form className="panel form-card" onSubmit={saveRules} noValidate>
                <fieldset disabled={!edit} className="border-0 p-0 m-0">
                    <div className="section-title">Business rules</div>
                    {msgB && <div className="alert alert-danger py-2">{msgB}</div>}
                    <div className="row g-4">
                        {data.settings.map(s => (
                            <div key={s.key} className="col-md-6">
                                <label className="form-label" htmlFor={s.key}>{s.label}</label>
                                <input id={s.key} type="number" step="any" className={'form-control' + (errB[s.key] ? ' is-invalid' : '')} value={values[s.key] ?? ''} onChange={e => setValues(v => ({ ...v, [s.key]: e.target.value }))} />
                                <div className="invalid-feedback">{errB[s.key]?.[0]}</div>
                                <div className="form-text">{s.help}</div>
                            </div>
                        ))}
                    </div>
                    {edit && <button className="btn btn-accent mt-4" disabled={busy === 'rules'}>{busy === 'rules' ? <span className="spinner-border spinner-border-sm" /> : 'Save business rules'}</button>}
                </fieldset>
            </form>
        </>
    );
}