import { Navigate, Outlet, Route, Routes } from 'react-router-dom';
import { useAuth } from './AuthContext';
import Layout from './Layout';
import Approvals from './pages/Approvals';
import AuditLog from './pages/AuditLog';
import Budgets from './pages/Budgets';
import Dashboard from './pages/Dashboard';
import InvoiceDetail from './pages/InvoiceDetail';
import InvoiceForm from './pages/InvoiceForm';
import Invoices from './pages/Invoices';
import Items from './pages/Items';
import Login from './pages/Login';
import OrderDetail from './pages/OrderDetail';
import Orders from './pages/Orders';
import QuotationCompare from './pages/QuotationCompare';
import QuotationQueue from './pages/QuotationQueue';
import Reports from './pages/Reports';
import RequestDetail from './pages/RequestDetail';
import RequestForm from './pages/RequestForm';
import RequestList from './pages/RequestList';
import Users from './pages/Users';
import Vendors from './pages/Vendors';

const HEAD = ['Procurement Administrator', 'Finance Officer', 'Head Office Manager'];
const APPROVERS = ['Branch Manager', ...HEAD];
const STAFF = ['Requester', ...APPROVERS];

function Protected({ children }) {
    const { user, loading } = useAuth();
    if (loading) return <div className="center-screen"><div className="spinner-border text-success" role="status" /></div>;
    return user ? children : <Navigate to="/login" replace />;
}

/** Sends a role away from pages it does not use. The server enforces the same rules on every request. */
function Guard({ roles, children }) {
    const { user } = useAuth();
    return roles.includes(user.role) ? children : <Navigate to="/" replace />;
}

export default function AppRoutes() {
    const { user, loading } = useAuth();
    return (
        <Routes>
            <Route path="/login" element={!loading && user ? <Navigate to="/" replace /> : <Login />} />
            <Route element={<Protected><Layout><Outlet /></Layout></Protected>}>
                <Route path="/" element={<Dashboard />} />
                <Route path="/requests" element={<Guard roles={STAFF}><RequestList /></Guard>} />
                <Route path="/requests/new" element={<Guard roles={['Requester']}><RequestForm /></Guard>} />
                <Route path="/requests/:id" element={<Guard roles={STAFF}><RequestDetail /></Guard>} />
                <Route path="/requests/:id/edit" element={<Guard roles={['Requester']}><RequestForm /></Guard>} />
                <Route path="/approvals" element={<Guard roles={APPROVERS}><Approvals /></Guard>} />
                <Route path="/vendors" element={<Guard roles={HEAD}><Vendors /></Guard>} />
                <Route path="/items" element={<Guard roles={HEAD}><Items /></Guard>} />
                <Route path="/quotations" element={<Guard roles={HEAD}><QuotationQueue /></Guard>} />
                <Route path="/quotations/:id" element={<Guard roles={HEAD}><QuotationCompare /></Guard>} />
                <Route path="/orders" element={<Guard roles={STAFF}><Orders /></Guard>} />
                <Route path="/orders/:id" element={<Guard roles={STAFF}><OrderDetail /></Guard>} />
                <Route path="/invoices" element={<Guard roles={HEAD}><Invoices /></Guard>} />
                <Route path="/invoices/new" element={<Guard roles={['Finance Officer']}><InvoiceForm /></Guard>} />
                <Route path="/invoices/:id" element={<Guard roles={HEAD}><InvoiceDetail /></Guard>} />
                <Route path="/budgets" element={<Guard roles={['Finance Officer', 'Head Office Manager']}><Budgets /></Guard>} />
                <Route path="/reports" element={<Guard roles={APPROVERS}><Reports /></Guard>} />
                <Route path="/users" element={<Guard roles={['System Administrator']}><Users /></Guard>} />
                <Route path="/audit-log" element={<Guard roles={['System Administrator', 'Head Office Manager']}><AuditLog /></Guard>} />
            </Route>
            <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
    );
}