const L = (label, to) => ({ label, to });
const S = (label, items) => ({ label, items });

const OVERVIEW = L('Overview', '/');
const APPROVALS = L('Approvals', '/approvals');
const REQUESTS = L('Purchase requests', '/requests');
const QUOTES = L('Quotations', '/quotations');
const ORDERS = L('Purchase orders', '/orders');
const INVOICES = L('Invoices', '/invoices');
const VENDORS = L('Vendors', '/vendors');
const ITEMS = L('Items', '/items');
const BUDGETS = L('Budgets', '/budgets');
const REPORTS = L('Reports', '/reports');
const AUDIT = L('Audit log', '/audit-log');

export const MENUS = {
    'Requester': [S('Workspace', [OVERVIEW, L('New request', '/requests/new'), REQUESTS, L('Deliveries', '/orders')])],
    'Branch Manager': [S('Workspace', [OVERVIEW, APPROVALS, REQUESTS, ORDERS]), S('Management', [REPORTS])],
    'Procurement Administrator': [S('Workspace', [OVERVIEW, APPROVALS, REQUESTS, QUOTES, ORDERS, INVOICES]), S('Catalogue', [VENDORS, ITEMS]), S('Management', [REPORTS])],
    'Finance Officer': [S('Workspace', [OVERVIEW, APPROVALS, REQUESTS, QUOTES, ORDERS, INVOICES]), S('Management', [BUDGETS, VENDORS, REPORTS])],
    'Head Office Manager': [S('Workspace', [OVERVIEW, APPROVALS, REQUESTS, QUOTES, ORDERS, INVOICES]), S('Management', [BUDGETS, VENDORS, REPORTS, AUDIT])],
    'System Administrator': [S('Workspace', [OVERVIEW]), S('Administration', [L('Team', '/users'), AUDIT])],
};

export const TASKS = {
    'Requester': ['Raise a purchase request and track it from submission to delivery.', 'Record goods received at your branch, when a colleague raised the request.'],
    'Branch Manager': ['Approve, return or reject requests from your branch.', 'Follow your branch spending and performance in Reports.'],
    'Procurement Administrator': ['Approve requests at your level.', 'Record and compare vendor quotations, issue purchase orders, follow deliveries and resolve invoice exceptions.'],
    'Finance Officer': ['Approve over-budget and high-value requests.', 'Set branch budgets, enter supplier invoices and match them to orders and deliveries.'],
    'Head Office Manager': ['Approve high-value requests.', 'Review budgets, reports and the audit log.'],
    'System Administrator': ['Create users and assign roles and branches.', 'Review the audit log to see who did what, and when.'],
};