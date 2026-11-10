import { Navigate, Outlet, Route, Routes } from 'react-router-dom';
import { useAuth } from './AuthContext';
import Layout from './Layout';
import Approvals from './pages/Approvals';
import Dashboard from './pages/Dashboard';
import Login from './pages/Login';
import RequestDetail from './pages/RequestDetail';
import RequestForm from './pages/RequestForm';
import RequestList from './pages/RequestList';

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
            </Route>
            <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
    );
}