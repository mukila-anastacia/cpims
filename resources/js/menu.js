const L = (label, to) => ({ label, to });
const S = (label, items) => ({ label, items });

const DASHBOARD = L('Dashboard', '/');
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
const SETTINGS = L('Settings', '/settings');

export const MENUS = {
    'Requester': [S('Workspace', [DASHBOARD, L('New request', '/requests/new'), REQUESTS, L('Deliveries', '/orders')])],
    'Branch Manager': [S('Workspace', [DASHBOARD, APPROVALS, REQUESTS, ORDERS]), S('Management', [REPORTS])],
    'Procurement Administrator': [S('Workspace', [DASHBOARD, APPROVALS, REQUESTS, QUOTES, ORDERS, INVOICES]), S('Catalogue', [VENDORS, ITEMS]), S('Management', [REPORTS])],
    'Finance Officer': [S('Workspace', [DASHBOARD, APPROVALS, REQUESTS, QUOTES, ORDERS, INVOICES]), S('Management', [BUDGETS, VENDORS, REPORTS])],
    'Head Office Manager': [S('Workspace', [DASHBOARD, APPROVALS, REQUESTS, QUOTES, ORDERS, INVOICES]), S('Management', [BUDGETS, VENDORS, REPORTS, SETTINGS, AUDIT])],
    'System Administrator': [S('Workspace', [DASHBOARD]), S('Administration', [L('Team', '/users'), SETTINGS, AUDIT])],
};

export const TASKS = {
    'Requester': ['Raise a purchase request and track it from submission to delivery.', 'Record goods received at your branch when a colleague raised the request.'],
    'Branch Manager': ['Approve requests from your branch or send them back for changes.', 'Follow your branch spending and performance in Reports.'],
    'Procurement Administrator': ['Approve requests at your level.', 'Record and compare vendor quotations and issue purchase orders.', 'Follow deliveries and resolve invoice exceptions.'],
    'Finance Officer': ['Approve over-budget and high-value requests.', 'Set branch budgets and enter supplier invoices.', 'Match invoices to orders and deliveries.'],
    'Head Office Manager': ['Approve high-value requests.', 'Review budgets and reports.', 'Check the settings and the audit log.'],
    'System Administrator': ['Create users and assign roles and branches.', 'Set the approval limits and business rules.', 'Review the audit log to see who did what and when.'],
};