import { BrowserRouter as Router, Routes, Route, Navigate, Outlet } from 'react-router-dom';
import { Toaster } from 'react-hot-toast';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import Login from './pages/Login';
import Layout from './components/Layout';
import AdminOverview from './pages/admin/AdminOverview';
import AdminSupervisors from './pages/admin/AdminSupervisors';
import AdminTopup from './pages/admin/AdminTopup';
import AdminTransactions from './pages/admin/AdminTransactions';
import AdminSupervisorTransactions from './pages/admin/AdminSupervisorTransactions';
import SupervisorOverview from './pages/supervisor/SupervisorOverview';
import SupervisorLedger from './pages/supervisor/SupervisorLedger';
import DeveloperOverview from './pages/developer/DeveloperOverview';
import DeveloperActivityLogs from './pages/developer/DeveloperActivityLogs';
import DeveloperExpenseLogs from './pages/developer/DeveloperExpenseLogs';
import Profile from './pages/Profile';
import { useEffect, useState } from 'react';
import { clearAuth, getToken, getUser } from './lib/authStorage';

const queryClient = new QueryClient({
    defaultOptions: {
        queries: {
            // Data kekal 'fresh' selama 30 saat - tak perlu reload setiap kali
            staleTime: 30 * 1000,
            // Data kekal dalam cache 5 minit selepas page ditinggalkan
            gcTime: 5 * 60 * 1000,
            // Refetch only if data is stale (default behavior, explicit for clarity)
            refetchOnMount: 'if-stale',
            // Refresh bila user balik ke tab
            refetchOnWindowFocus: true,
            // Refresh bila internet kembali
            refetchOnReconnect: true,
            retry: (failureCount, error) => {
                if (error?.response?.status === 401) return false;
                return failureCount < 1;
            },
        },
    },
});

function RoleRedirect({ user, token }) {
    if (!user || !token) return <Navigate to="/login" replace />;
    if (user.role === 'admin') return <Navigate to="/admin/dashboard" replace />;
    if (user.role === 'supervisor') return <Navigate to="/supervisor/dashboard" replace />;
    if (user.role === 'developer') return <Navigate to="/developer/dashboard" replace />;
    return <Navigate to="/login" replace />;
}

function AdminGuard({ user, token }) {
    if (!user || !token) return <Navigate to="/login" replace />;
    if (user.role !== 'admin') return <Navigate to="/" replace />;
    return <Outlet />;
}

function SupervisorGuard({ user, token }) {
    if (!user || !token) return <Navigate to="/login" replace />;
    if (user.role !== 'supervisor') return <Navigate to="/" replace />;
    return <Outlet />;
}

function DeveloperGuard({ user, token }) {
    if (!user || !token) return <Navigate to="/login" replace />;
    if (user.role !== 'developer') return <Navigate to="/" replace />;
    return <Outlet />;
}

function App() {
    const [user, setUser] = useState(null);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        const token = getToken();
        const savedUser = getUser();

        if (token && savedUser) {
            setUser(savedUser);
        } else {
            clearAuth();
        }

        const handleUnauthorized = () => {
            queryClient.clear(); // clear cache bila unauthorized
            setUser(null);
        };
        window.addEventListener('auth:unauthorized', handleUnauthorized);

        setLoading(false);

        return () => window.removeEventListener('auth:unauthorized', handleUnauthorized);
    }, []);

    const token = getToken();

    if (loading) return null;

    return (
        <QueryClientProvider client={queryClient}>
            <Toaster 
                position="top-center" 
                reverseOrder={false}
                toastOptions={{
                    duration: 3000,
                    style: {
                        background: '#fff',
                        color: '#0f172a',
                        fontWeight: 'bold',
                        borderRadius: '1rem',
                        border: '1px solid #e2e8f0',
                        boxShadow: '0 10px 15px -3px rgba(0, 0, 0, 0.1)',
                    },
                }}
            />
            <Router>
                <Routes>
                    <Route path="/login" element={!user || !token ? <Login setUser={setUser} /> : <RoleRedirect user={user} token={token} />} />
                    
                    <Route element={<Layout user={user} setUser={setUser} />}>
                        <Route path="/" element={<RoleRedirect user={user} token={token} />} />
                        
                        <Route path="admin" element={<AdminGuard user={user} token={token} />}>
                            <Route index element={<Navigate to="dashboard" replace />} />
                            <Route path="dashboard" element={<AdminOverview />} />
                            <Route path="supervisors" element={<AdminSupervisors />} />
                            <Route path="supervisors/:supervisorId/transactions" element={<AdminSupervisorTransactions />} />
                            <Route path="send-to-supervisor" element={<AdminTopup />} />
                            <Route path="topup" element={<Navigate to="send-to-supervisor" replace />} />
                            <Route path="transactions" element={<AdminTransactions />} />
                        </Route>
                        
                        <Route path="supervisor" element={<SupervisorGuard user={user} token={token} />}>
                            <Route index element={<Navigate to="dashboard" replace />} />
                            <Route path="dashboard" element={<SupervisorOverview />} />
                            <Route path="ledger" element={<SupervisorLedger user={user} />} />
                        </Route>

                        <Route path="developer" element={<DeveloperGuard user={user} token={token} />}>
                            <Route index element={<Navigate to="dashboard" replace />} />
                            <Route path="dashboard" element={<DeveloperOverview />} />
                            <Route path="activity-logs" element={<DeveloperActivityLogs />} />
                            <Route path="expense-logs" element={<DeveloperExpenseLogs />} />
                        </Route>

                        <Route path="profile" element={<Profile user={user} setUser={setUser} />} />
                    </Route>
                </Routes>
            </Router>
        </QueryClientProvider>
    );
}

export default App;
