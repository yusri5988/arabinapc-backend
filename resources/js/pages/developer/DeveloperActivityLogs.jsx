import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import api from '../../lib/axios';
import { CheckCircle, XCircle, ChevronLeft, ChevronRight } from 'lucide-react';

export default function DeveloperActivityLogs() {
    const [page, setPage] = useState(1);

    const { data, isLoading, isError, error, refetch } = useQuery({
        queryKey: ['developerActivityLogs', page],
        queryFn: async () => {
            const res = await api.get('/developer/activity-logs', { params: { page, filter: 'topup' } });
            return res.data;
        },
        retry: false,
    });

    if (isLoading) return <div className="flex items-center justify-center h-40 text-emerald-600 animate-pulse font-bold">Loading activity logs...</div>;

    if (isError) {
        const message = error?.response?.data?.message || error?.message || 'Failed to load activity logs.';
        return (
            <div className="rounded-2xl border border-red-100 bg-red-50 px-5 py-4 text-red-600">
                <p className="font-bold">{message}</p>
                <button type="button" onClick={() => refetch()} className="mt-3 rounded-xl bg-white px-4 py-2 text-sm font-bold text-red-600 shadow-sm border border-red-100">
                    Try again
                </button>
            </div>
        );
    }

    const logs = data?.data || [];
    const pagination = {
        currentPage: data?.current_page,
        lastPage: data?.last_page,
        total: data?.total,
        from: data?.from,
        to: data?.to,
    };

    const StatusBadge = ({ status }) => (
        <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[10px] font-bold uppercase tracking-wider ${
            status === 'success'
                ? 'bg-emerald-50 text-emerald-700'
                : 'bg-red-50 text-red-700'
        }`}>
            {status === 'success' ? <CheckCircle size={10} /> : <XCircle size={10} />}
            {status}
        </span>
    );

    return (
        <div className="space-y-4">
            <div>
                <h2 className="text-2xl font-black text-slate-900 tracking-tight">Send Petty Cash Logs</h2>
                <p className="text-slate-500 text-sm font-medium mt-0.5">Monitor all topup transactions and system activity</p>
            </div>

            {/* Summary */}
            {pagination.total > 0 && (
                <p className="text-xs font-semibold text-slate-400">
                    Showing {pagination.from}–{pagination.to} of {pagination.total} logs
                </p>
            )}

            {/* Table */}
            <div className="bg-white border border-slate-200/60 rounded-2xl overflow-hidden shadow-sm">
                {logs.length === 0 ? (
                    <div className="p-6 text-center">
                        <p className="text-sm text-slate-400 font-bold">No activity logs found</p>
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-sm">
                            <thead className="bg-slate-50/50 text-slate-400 text-[10px] uppercase tracking-widest font-bold border-b border-slate-100">
                                <tr>
                                    <th className="px-4 py-2.5">Time</th>
                                    <th className="px-4 py-2.5">Action</th>
                                    <th className="px-4 py-2.5">Status</th>
                                    <th className="px-4 py-2.5">User</th>
                                    <th className="px-4 py-2.5">IP</th>
                                    <th className="px-4 py-2.5">Context</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100/60">
                                {logs.map((log) => (
                                    <tr key={log.id} className="hover:bg-slate-50/40 transition-colors">
                                        <td className="px-4 py-2 whitespace-nowrap">
                                            <p className="text-xs font-semibold text-slate-700">
                                                {new Date(log.created_at).toLocaleString('en-MY', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })}
                                            </p>
                                        </td>
                                        <td className="px-4 py-2">
                                            <code className="text-xs font-mono font-bold text-slate-800">{log.action}</code>
                                        </td>
                                        <td className="px-4 py-2">
                                            <StatusBadge status={log.status} />
                                        </td>
                                        <td className="px-4 py-2">
                                            <p className="text-xs font-semibold text-slate-600">{log.user?.name || 'System'}</p>
                                        </td>
                                        <td className="px-4 py-2">
                                            <p className="text-[11px] text-slate-400 font-mono">{log.ip_address || '-'}</p>
                                        </td>
                                        <td className="px-4 py-2 max-w-[200px]">
                                            {log.context && Object.keys(log.context).length > 0 ? (
                                                <pre className="text-[9px] bg-slate-50 rounded-lg p-1.5 text-slate-500 overflow-x-auto max-h-20 leading-snug">
                                                    {JSON.stringify(log.context, null, 1)}
                                                </pre>
                                            ) : (
                                                <p className="text-xs text-slate-300">—</p>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>

            {/* Pagination */}
            {pagination.lastPage > 1 && (
                <div className="flex items-center justify-between pt-1">
                    <p className="text-xs text-slate-400 font-semibold">
                        Page {pagination.currentPage} of {pagination.lastPage}
                    </p>
                    <div className="flex gap-2">
                        <button
                            type="button"
                            onClick={() => setPage((p) => Math.max(1, p - 1))}
                            disabled={pagination.currentPage <= 1}
                            className="flex items-center gap-1 px-3 py-2 rounded-xl bg-white border border-slate-200 text-xs font-bold text-slate-700 hover:bg-slate-50 transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                        >
                            <ChevronLeft size={14} />
                            Prev
                        </button>
                        <button
                            type="button"
                            onClick={() => setPage((p) => Math.min(pagination.lastPage, p + 1))}
                            disabled={pagination.currentPage >= pagination.lastPage}
                            className="flex items-center gap-1 px-3 py-2 rounded-xl bg-white border border-slate-200 text-xs font-bold text-slate-700 hover:bg-slate-50 transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                        >
                            Next
                            <ChevronRight size={14} />
                        </button>
                    </div>
                </div>
            )}
        </div>
    );
}
