export const kes = (n) =>
    'KES ' + Number(n ?? 0).toLocaleString('en-KE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export const fmtDate = (s) => (s ? new Date(s).toLocaleDateString('en-GB') : '-');

export const STATUS = {
    draft: ['Draft', 'secondary'],
    in_approval: ['In approval', 'warning'],
    approved: ['Approved', 'success'],
    rejected: ['Rejected', 'danger'],
    returned: ['Returned', 'info'],
    ordered: ['Ordered', 'primary'],
    received: ['Received', 'success'],
    closed: ['Closed', 'dark'],
    cancelled: ['Cancelled', 'secondary'],
};