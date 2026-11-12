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
import RequestDetail from './pages/RequestDetail';
import RequestForm from './pages/RequestForm';
import RequestList from './pages/RequestList';
import Users from './pages/Users';
import Vendors from './pages/Vendors';

function Protected({ children }) {
    const { user, loading } = useAuth();
    if (loading) return <div className="center-screen"><div className="spinner-border text-success" role="status" /></div>;
    return user ? children : <Navigate to="/login" replace />;
}

export default function AppRoutes() {
    const { user, loading } = useAuth();
    return (
        <Routes>
            <Route path="/login" element={!loading && user ? <Navigate to="/" replace /> : <Login />} />
            <Route element={<Protected><Layout><Outlet /></Layout></Protected>}>
                <Route path="/" element={<Dashboard />} />
                <Route path="/requests" element={<RequestList />} />
                <Route path="/requests/new" element={<RequestForm />} />
                <Route path="/requests/:id" element={<RequestDetail />} />
                <Route path="/requests/:id/edit" element={<RequestForm />} />
                <Route path="/approvals" element={<Approvals />} />
                <Route path="/vendors" element={<Vendors />} />
                <Route path="/items" element={<Items />} />
                <Route path="/quotations" element={<QuotationQueue />} />
                <Route path="/quotations/:id" element={<QuotationCompare />} />
                <Route path="/orders" element={<Orders />} />
                <Route path="/orders/:id" element={<OrderDetail />} />
                <Route path="/invoices" element={<Invoices />} />
                <Route path="/invoices/new" element={<InvoiceForm />} />
                <Route path="/invoices/:id" element={<InvoiceDetail />} />
                <Route path="/budgets" element={<Budgets />} />
                <Route path="/users" element={<Users />} />
                <Route path="/audit-log" element={<AuditLog />} />
            </Route>
            <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
    );
}