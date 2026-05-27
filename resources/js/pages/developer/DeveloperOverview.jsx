import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import api from '../../lib/axios';
import { ScrollText, Receipt, CheckCircle, XCircle, Activity } from 'lucide-react';

export default function DeveloperOverview() {
    const { data, isLoading, isError, error, refetch } = useQuery({
        queryKey: ['developerDashboard'],
        queryFn: async () => {
            const res = await api.get('/developer/dashboard');
            return res.data;
        },
        staleTime: 30 * 1000,
        retry: false,
    });

    if (isLoading) return <div className="flex items-center justify-center h-40 text-emerald-600 animate-pulse font-bold">Loading dashboard...</div>;

    if (isError) {
        const status = error?.response?.status;
        const message = error?.response?.data?.message || error?.message || 'Failed to load dashboard.';

        return (
            <div className="rounded-2xl border border-red-100 bg-red-50 px-5 py-4 text-red-600">
                <p className="font-bold">{message}</p>
                <button type="button" onClick={() => refetch()} className="mt-3 rounded-xl bg-white px-4 py-2 text-sm font-bold text-red-600 shadow-sm border border-red-100">
                    Try again
                </button>
            </div>
        );
    }

    const { total_logs, success_count, fail_count, topup_count, expense_count, recent_logs } = data;

    return (
        <div className="space-y-6">
            <div>
                <h2 className="text-2xl font-black text-slate-900 tracking-tight">Developer Dashboard</h2>
                <p className="text-slate-500 text-sm font-medium mt-0.5">Activity log overview and monitoring</p>
            </div>

            {/* Summary Cards */}
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div className="rounded-2xl border border-slate-200/60 bg-white p-5 shadow-sm">
                    <div className="flex items-center gap-3 mb-3">
                        <div className="p-2 rounded-xl bg-slate-100 text-slate-600">
                            <Activity size={18} />
                        </div>
                        <p className="text-xs font-bold uppercase tracking-widest text-slate-400">Total Logs</p>
                    </div>
                    <p className="text-3xl font-black text-slate-900">{total_logs}</p>
                </div>

                <div className="rounded-2xl border border-emerald-100/60 bg-white p-5 shadow-sm">
                    <div className="flex items-center gap-3 mb-3">
                        <div className="p-2 rounded-xl bg-emerald-100 text-emerald-600">
                            <CheckCircle size={18} />
                        </div>
                        <p className="text-xs font-bold uppercase tracking-widest text-emerald-500">Success</p>
                    </div>
                    <p className="text-3xl font-black text-emerald-600">{success_count}</p>
                </div>

                <div className="rounded-2xl border border-red-100/60 bg-white p-5 shadow-sm">
                    <div className="flex items-center gap-3 mb-3">
                        <div className="p-2 rounded-xl bg-red-100 text-red-500">
                            <XCircle size={18} />
                        </div>
                        <p className="text-xs font-bold uppercase tracking-widest text-red-400">Failed</p>
                    </div>
                    <p className="text-3xl font-black text-red-500">{fail_count}</p>
                </div>
            </div>

            {/* Send Petty Cash Button */}
            <Link to="/developer/activity-logs" className="relative overflow-hidden bg-gradient-to-br from-emerald-600 via-emerald-500 to-teal-500 p-6 md:p-8 rounded-[2rem] shadow-2xl shadow-emerald-600/20 group active:scale-[0.98] transition-transform block text-white">
                <div className="absolute top-0 right-0 w-40 h-40 bg-white/10 blur-3xl rounded-full group-hover:scale-110 transition-transform duration-700"></div>
                <div className="relative z-10 flex items-center justify-between">
                    <div className="flex items-center gap-3">
                        <div className="p-2.5 bg-white/20 backdrop-blur-md rounded-2xl border border-white/20 shadow-sm">
                            <ScrollText className="text-white drop-shadow-sm" size={24} />
                        </div>
                        <div>
                            <p className="text-emerald-50 font-bold uppercase tracking-widest text-[11px] drop-shadow-sm">Send Petty Cash</p>
                            <p className="text-sm font-bold text-emerald-50 mt-1">View all topup transaction logs ({topup_count})</p>
                        </div>
                    </div>
                    <div className="w-8 h-8 rounded-full bg-white/10 flex items-center justify-center backdrop-blur-sm border border-white/10 group-hover:bg-white/20 transition-colors">
                        <ScrollText size={16} />
                    </div>
                </div>
            </Link>

            {/* Add Expenses Button */}
            <Link to="/developer/expense-logs" className="relative overflow-hidden bg-gradient-to-br from-red-600 via-red-500 to-orange-500 p-6 md:p-8 rounded-[2rem] shadow-2xl shadow-red-600/20 group active:scale-[0.98] transition-transform block text-white">
                <div className="absolute top-0 right-0 w-40 h-40 bg-white/10 blur-3xl rounded-full group-hover:scale-110 transition-transform duration-700"></div>
                <div className="relative z-10 flex items-center justify-between">
                    <div className="flex items-center gap-3">
                        <div className="p-2.5 bg-white/20 backdrop-blur-md rounded-2xl border border-white/20 shadow-sm">
                            <Receipt className="text-white drop-shadow-sm" size={24} />
                        </div>
                        <div>
                            <p className="text-red-50 font-bold uppercase tracking-widest text-[11px] drop-shadow-sm">Add Expenses</p>
                            <p className="text-sm font-bold text-red-50 mt-1">View item photo, receipt & expense logs ({expense_count})</p>
                        </div>
                    </div>
                    <div className="w-8 h-8 rounded-full bg-white/10 flex items-center justify-center backdrop-blur-sm border border-white/10 group-hover:bg-white/20 transition-colors">
                        <Receipt size={16} />
                    </div>
                </div>
            </Link>

            {/* Recent Logs */}
            <div>
                <h3 className="text-lg font-black text-slate-900 mb-4">Recent Activity</h3>
                <div className="bg-white border border-slate-200/60 rounded-[2rem] overflow-hidden shadow-sm">
                    <div className="divide-y divide-slate-100/80">
                        {recent_logs?.map((log) => (
                            <div key={log.id} className="px-5 py-4 flex items-center justify-between hover:bg-slate-50/50 transition-colors">
                                <div className="flex items-center gap-3 min-w-0">
                                    <div className={`w-8 h-8 rounded-xl flex items-center justify-center flex-shrink-0 ${
                                        log.status === 'success' ? 'bg-emerald-50 text-emerald-600' : 'bg-red-50 text-red-500'
                                    }`}>
                                        {log.status === 'success' ? <CheckCircle size={16} /> : <XCircle size={16} />}
                                    </div>
                                    <div className="min-w-0">
                                        <p className="text-sm font-bold text-slate-900 truncate">{log.action}</p>
                                        <p className="text-xs text-slate-400 truncate">{log.user?.name || 'System'}</p>
                                    </div>
                                </div>
                                <div className="text-right flex-shrink-0 ml-3">
                                    <p className={`text-xs font-bold ${log.status === 'success' ? 'text-emerald-600' : 'text-red-500'}`}>
                                        {log.status.toUpperCase()}
                                    </p>
                                    <p className="text-[10px] text-slate-400">{new Date(log.created_at).toLocaleString('en-MY', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })}</p>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            </div>
        </div>
    );
}
