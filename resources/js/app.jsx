import '@fontsource-variable/oswald';
import '@fontsource-variable/manrope';
import 'bootstrap/dist/css/bootstrap.min.css';
import '../css/app.css';
import { createRoot } from 'react-dom/client';
import { BrowserRouter } from 'react-router-dom';
import { AuthProvider } from './AuthContext';
import AppRoutes from './AppRoutes';

createRoot(document.getElementById('root')).render(
    <BrowserRouter>
        <AuthProvider>
            <AppRoutes />
        </AuthProvider>
    </BrowserRouter>
);