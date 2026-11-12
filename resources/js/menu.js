// `to: null` means the screen is not built yet
const D = { label: 'Dashboard', to: '/' };
const APPROVALS = { label: 'Approvals', to: '/approvals' };
const VENDORS = { label: 'Vendors', to: '/vendors' };
const ITEMS = { label: 'Items', to: '/items' };
const QUOTES = { label: 'Quotations', to: '/quotations' };
const ORDERS = { label: 'Orders', to: '/orders' };
const INVOICES = { label: 'Invoices', to: '/invoices' };
const soon = (label) => ({ label, to: null });

export const MENUS = {
    'Requester': [D, { label: 'New request', to: '/requests/new' }, { label: 'My requests', to: '/requests' }, { label: 'Receipts', to: '/orders' }],
    'Branch Manager': [D, APPROVALS, { label: 'Branch requests', to: '/requests' }, { label: 'Branch orders', to: '/orders' }, soon('Reports')],
    'Procurement Administrator': [D, APPROVALS, { label: 'Requests', to: '/requests' }, QUOTES, ORDERS, INVOICES, VENDORS, ITEMS, soon('Reports')],
    'Finance Officer': [D, APPROVALS, { label: 'Requests', to: '/requests' }, QUOTES, ORDERS, INVOICES, VENDORS, soon('Budgets'), soon('Reports')],
    'Head Office Manager': [D, APPROVALS, { label: 'Requests', to: '/requests' }, QUOTES, ORDERS, INVOICES, VENDORS, soon('Reports'), soon('Audit log')],
    'System Administrator': [D, soon('Users and roles'), soon('Branches'), soon('Audit log'), soon('Settings')],
};

export const TASKS = {
    'Requester': ['Raise a purchase request and track it from submission to delivery.', 'Record goods received at your branch, when a colleague raised the request.'],
    'Branch Manager': ['Approve, return or reject requests from your branch.', 'Follow your branch spending against budget.'],
    'Procurement Administrator': ['Approve requests at your level.', 'Record and compare vendor quotations, issue purchase orders, follow deliveries and resolve invoice exceptions.'],
    'Finance Officer': ['Approve over-budget and high-value requests.', 'Enter supplier invoices and match them to orders and deliveries.'],
    'Head Office Manager': ['Approve high-value requests.', 'Review dashboards and the audit log.'],
    'System Administrator': ['Create users and assign roles and branches.', 'Review the audit log and system settings.'],
};