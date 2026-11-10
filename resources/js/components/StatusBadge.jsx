import { STATUS } from '../format';

export default function StatusBadge({ status }) {
    const [label, colour] = STATUS[status] ?? [status, 'secondary'];
    return <span className={`badge text-bg-${colour}`}>{label}</span>;
}