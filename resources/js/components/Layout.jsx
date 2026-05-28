import { Navigate, Link, useNavigate, useLocation, useOutlet } from 'react-router-dom';
import { LayoutDashboard, LogOut, Users, PlusCircle, Leaf, Menu, X, History, Send, UserRound, ScrollText, Receipt } from 'lucide-react';
import { useState } from 'react';
import { motion, AnimatePresence } from 'framer-motion';
import { useQueryClient } from '@tanstack/react-query';
import api from '../lib/axios';
import { clearAuth, getToken } from '../lib/authStorage';

export default function Layout({ user, setUser }) {
    const navigate = useNavigate();
    const location = useLocation();
    const outlet = useOutlet();
    const queryClient = useQueryClient();
    const [mobileMenuOpen, setMobileMenuOpen] = useState(false);

    if (!user || !getToken()) return <Navigate to="/login" />;

    const handleLogout = async () => {
        try {
            await api.post('/logout');
        } catch (err) {
            console.error(err);
        } finally {
            queryClient.clear(); // clear cache bila logout
            clearAuth();
            setUser(null);
            navigate('/login');
        }
    };

    const isActive = (path) => location.pathname === path;

    const navLinkClass = (path, accent = 'emerald') =>
        `flex flex-col items-center justify-center gap-1.5 py-2 px-4 rounded-2xl transition-all duration-300 ${
            isActive(path)
                ? accent === 'red'
                    ? 'text-red-600 scale-110'
                    : 'text-emerald-600 scale-110'
                : 'text-slate-400 hover:text-slate-600'
        }`;

    const desktopLinkClass = (path, accent = 'emerald') =>
        `flex items-center gap-3 px-4 py-3.5 rounded-2xl transition-all duration-300 text-sm font-semibold ${
            isActive(path)
                ? accent === 'red'
                    ? 'bg-red-50 text-red-700 shadow-sm shadow-red-100'
                    : 'bg-emerald-50 text-emerald-700 shadow-sm shadow-emerald-100'
                : 'text-slate-500 hover:bg-slate-50 hover:text-slate-900'
        }`;

    const adminLinks = [
        { to: '/admin/dashboard', label: 'Home', icon: LayoutDashboard },
        { to: '/admin/supervisors', label: 'Staff', icon: Users },
        { to: '/admin/send-to-supervisor', label: 'Send', icon: Send },
        { to: '/admin/transactions', label: 'History', icon: History },
    ];

    const supervisorLinks = [
        { to: '/supervisor/dashboard', label: 'Home', icon: LayoutDashboard },
        { to: '/supervisor/ledger', label: 'Add Expenses', icon: PlusCircle, accent: 'red' },
    ];

    const developerLinks = [
        { to: '/developer/dashboard', label: 'Home', icon: LayoutDashboard },
        { to: '/developer/activity-logs', label: 'Send Petty Cash', icon: ScrollText },
        { to: '/developer/expense-logs', label: 'Add Expenses', icon: Receipt, accent: 'red' },
    ];

    const profileLinks = [
        { to: '/profile', label: 'Edit Profile', icon: UserRound },
    ];

    const links = user.role === 'admin' ? adminLinks : user.role === 'developer' ? developerLinks : supervisorLinks;

    return (
        <div className="min-h-screen bg-slate-50/50 text-slate-900 md:flex font-['Outfit'] font-medium selection:bg-emerald-100">
            {/* Mobile Top Header */}
            <header className="md:hidden fixed top-0 left-0 right-0 z-40 bg-white/70 backdrop-blur-xl border-b border-white/50 px-5 h-16 flex items-center justify-between shadow-[0_2px_10px_-3px_rgba(0,0,0,0.02)]">
                <div className="flex items-center gap-3">
                    <div className="w-9 h-9 rounded-[14px] bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center shadow-md shadow-emerald-500/20">
                        <Leaf className="text-white w-4 h-4 drop-shadow-sm" />
                    </div>
                    <div>
                        <h1 className="text-[17px] font-bold text-slate-900 leading-none">Arabina PC</h1>
                        <p className="text-[10px] text-emerald-600 font-semibold uppercase tracking-wider mt-0.5">{user.role}</p>
                    </div>
                </div>
                <button 
                    onClick={() => setMobileMenuOpen(!mobileMenuOpen)}
                    className="w-10 h-10 flex items-center justify-center rounded-full bg-slate-100/80 text-slate-600 hover:bg-emerald-50 hover:text-emerald-600 transition-colors active:scale-95"
                >
                    {mobileMenuOpen ? <X size={20} strokeWidth={2.5} /> : <Menu size={20} strokeWidth={2.5} />}
                </button>
            </header>

            {/* Mobile Menu Overlay */}
            {mobileMenuOpen && (
                <div className="md:hidden fixed inset-0 z-50 bg-slate-900/40 backdrop-blur-sm" onClick={() => setMobileMenuOpen(false)}>
                    <div 
                        className="absolute right-0 top-0 bottom-0 w-[80%] max-w-sm bg-white shadow-2xl p-6 flex flex-col animate-in slide-in-from-right-full duration-300"
                        onClick={e => e.stopPropagation()}
                    >
                        <div className="flex items-center justify-between mb-8">
                            <h2 className="text-lg font-bold text-slate-900">Menu</h2>
                            <button onClick={() => setMobileMenuOpen(false)} className="p-2 bg-slate-100 rounded-full text-slate-500">
                                <X size={18} />
                            </button>
                        </div>

                        <div className="flex items-center gap-4 p-4 mb-6 rounded-2xl bg-emerald-50/50 border border-emerald-100/50">
                            <div className="w-12 h-12 rounded-2xl bg-gradient-to-br from-emerald-400 to-teal-500 flex items-center justify-center font-bold text-white shadow-sm shadow-emerald-500/20 text-lg">
                                {user.name[0]}
                            </div>
                            <div>
                                <p className="text-base font-bold text-slate-900">{user.name}</p>
                                <p className="text-xs font-semibold text-emerald-600 uppercase tracking-wider">{user.role}</p>
                            </div>
                        </div>

                        <div className="space-y-1 flex-1">
                            {profileLinks.map((link) => (
                                <Link
                                    key={link.to}
                                    to={link.to}
                                    onClick={() => setMobileMenuOpen(false)}
                                    className={`flex items-center gap-4 px-4 py-4 rounded-2xl text-[15px] font-semibold transition-all ${
                                        isActive(link.to)
                                            ? 'bg-emerald-50 text-emerald-700 shadow-sm shadow-emerald-100/50'
                                            : 'text-slate-600 hover:bg-slate-50'
                                    }`}
                                >
                                    <link.icon
                                        size={20}
                                        strokeWidth={isActive(link.to) ? 2.5 : 2}
                                    />
                                    {link.label}
                                </Link>
                            ))}
                        </div>

                        <button
                            onClick={handleLogout}
                            className="w-full flex items-center justify-center gap-3 px-4 py-4 rounded-2xl text-red-500 bg-red-50/50 hover:bg-red-50 font-bold mt-auto transition-colors"
                        >
                            <LogOut size={18} strokeWidth={2.5} />
                            Sign Out
                        </button>
                    </div>
                </div>
            )}

            {/* Desktop Sidebar */}
            <aside className="hidden md:flex w-[280px] border-r border-slate-200/60 bg-white/80 backdrop-blur-xl sticky top-0 h-screen flex-col">
                <div className="p-8">
                    <h1 className="text-2xl font-black text-slate-900 flex items-center gap-3">
                        <div className="w-10 h-10 rounded-2xl bg-gradient-to-br from-emerald-500 to-teal-500 flex items-center justify-center shadow-lg shadow-emerald-500/20">
                            <Leaf className="text-white w-5 h-5 drop-shadow-sm" />
                        </div>
                        Arabina PC
                    </h1>
                </div>

                <nav className="flex-1 px-5 space-y-2 mt-4">
                    {links.map((link) => (
                        <Link key={link.to} to={link.to} className={desktopLinkClass(link.to, link.accent)}>
                            <link.icon
                                size={20}
                                strokeWidth={isActive(link.to) ? 2.5 : 2}
                            />
                            {link.label}
                        </Link>
                    ))}

                    <div className="pt-2 mt-2 border-t border-slate-100"></div>

                    {profileLinks.map((link) => (
                        <Link key={link.to} to={link.to} className={desktopLinkClass(link.to, link.accent)}>
                            <link.icon
                                size={20}
                                strokeWidth={isActive(link.to) ? 2.5 : 2}
                            />
                            {link.label}
                        </Link>
                    ))}
                </nav>

                <div className="p-6 border-t border-slate-100">
                    <div className="flex items-center gap-4 mb-6 px-2">
                        <div className="w-11 h-11 rounded-2xl bg-gradient-to-br from-emerald-100 to-teal-50 border border-emerald-200/50 flex items-center justify-center font-bold text-emerald-700 shadow-sm">
                            {user.name[0]}
                        </div>
                        <div className="flex-1 min-w-0">
                            <p className="text-[15px] font-bold text-slate-900 truncate">{user.name}</p>
                            <p className="text-[11px] font-bold text-emerald-600 uppercase tracking-wider">{user.role}</p>
                        </div>
                    </div>
                    <button
                        onClick={handleLogout}
                        className="w-full flex items-center justify-center gap-2 px-4 py-3.5 rounded-2xl hover:bg-red-50 text-red-500 transition-colors text-[14px] font-bold"
                    >
                        <LogOut size={18} strokeWidth={2.5} />
                        Sign Out
                    </button>
                </div>
            </aside>

            {/* Main Content */}
            <main className="flex-1 p-5 md:p-10 pt-24 md:pt-10 pb-32 md:pb-10 max-w-5xl mx-auto w-full overflow-x-hidden">
                <AnimatePresence mode="wait">
                    <motion.div
                        key={location.pathname}
                        initial={{ opacity: 0, x: 20 }}
                        animate={{ opacity: 1, x: 0 }}
                        exit={{ opacity: 0, x: -20 }}
                        transition={{ duration: 0.2, ease: "easeOut" }}
                    >
                        {outlet}
                    </motion.div>
                </AnimatePresence>
            </main>

            {/* Bottom Navigation */}
            <div className="fixed bottom-6 left-6 right-6 z-40 pointer-events-none flex justify-center md:hidden">
                <nav className="pointer-events-auto bg-white/90 backdrop-blur-2xl border border-white/60 shadow-[0_8px_30px_rgb(0,0,0,0.12),inset_0_1px_1px_rgba(255,255,255,0.8)] rounded-3xl px-2 py-2 flex items-center justify-around w-full max-w-md">
                    {links.map((link) => (
                        <Link
                            key={link.to}
                            to={link.to}
                            className={navLinkClass(link.to, link.accent)}
                        >
                            <div className={`relative flex items-center justify-center w-12 h-8 rounded-xl transition-all duration-300 ${
                                isActive(link.to)
                                    ? link.accent === 'red'
                                        ? 'bg-red-100 text-red-600'
                                        : 'bg-emerald-100 text-emerald-600'
                                    : 'bg-transparent text-slate-400'
                            }`}>
                                <link.icon
                                    size={22}
                                    strokeWidth={isActive(link.to) ? 2.5 : 2}
                                    className={`relative z-10 ${link.accent === 'red' ? (isActive(link.to) ? 'text-red-600' : 'text-red-500') : ''}`}
                                />
                            </div>
                            <span className={`text-[10px] font-bold transition-all duration-300 ${
                                isActive(link.to)
                                    ? link.accent === 'red'
                                        ? 'text-red-700'
                                        : 'text-emerald-700'
                                    : 'text-slate-500'
                            }`}>{link.label}</span>
                        </Link>
                    ))}
                    
                </nav>
            </div>
        </div>
    );
}
