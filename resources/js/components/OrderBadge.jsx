const MAP = {
    issued: ['Issued', 'primary'],
    partly_received: ['Partly received', 'warning'],
    received: ['Received', 'success'],
    closed: ['Closed', 'dark'],
    cancelled: ['Cancelled', 'secondary'],
};

export default function OrderBadge({ status }) {
    const [label, colour] = MAP[status] ?? [status, 'secondary'];
    return <span className={`badge text-bg-${colour}`}>{label}</span>;
}