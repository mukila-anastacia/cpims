
// `to: null` means the screen is not built yet
const D = { label: 'Dashboard', to: '/' };
const APPROVALS = { label: 'Approvals', to: '/approvals' };
const soon = (label) => ({ label, to: null });

export const MENUS = {
    'Requester': [D, { label: 'New request', to: '/requests/new' }, { label: 'My requests', to: '/requests' }, soon('Receipts')],
    'Branch Manager': [D, APPROVALS, { label: 'Branch requests', to: '/requests' }, soon('Reports')],
    'Procurement Administrator': [D, APPROVALS, { label: 'Requests', to: '/requests' }, soon('Quotations'), soon('Vendors and items'), soon('Orders'), soon('Reports')],
    'Finance Officer': [D, APPROVALS, { label: 'Requests', to: '/requests' }, soon('Budgets'), soon('Invoices'), soon('Reports')],
    'Head Office Manager': [D, APPROVALS, { label: 'Requests', to: '/requests' }, soon('Reports'), soon('Audit log')],
    'System Administrator': [D, soon('Users and roles'), soon('Branches'), soon('Audit log'), soon('Settings')],
};

export const TASKS = {
    'Requester': ['Raise a purchase request and track it from submission to delivery.', 'Record goods received at your branch.'],
    'Branch Manager': ['Approve, return or reject requests from your branch.', 'Follow your branch spending against budget.'],
    'Procurement Administrator': ['Approve requests at your level.', 'Record and compare vendor quotations, then issue purchase orders.'],
    'Finance Officer': ['Approve over-budget and high-value requests.', 'Set branch budgets and match supplier invoices.'],
    'Head Office Manager': ['Approve high-value requests.', 'Review dashboards and the audit log.'],
    'System Administrator': ['Create users and assign roles and branches.', 'Review the audit log and system settings.'],
};