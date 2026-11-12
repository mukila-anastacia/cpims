const MAP = {
    matched: ['Matched', 'success'],
    exception: ['Exception', 'danger'],
    resolved: ['Resolved', 'info'],
    pending: ['Pending', 'secondary'],
};

export default function InvoiceBadge({ status }) {
    const [label, colour] = MAP[status] ?? [status, 'secondary'];
    return <span className={`badge text-bg-${colour}`}>{label}</span>;
}