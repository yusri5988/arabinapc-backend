import { useState, useEffect, useRef, useCallback } from 'react';
import { useQuery } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import api from '../../lib/axios';
import AdminTransactionModal from '../../components/AdminTransactionModal';
import { normalizeSupervisors } from '../../lib/normalize';
import { ArrowDownLeft, ArrowUpRight, History, RefreshCw, FileText, UserRound, BadgeInfo, ReceiptText, Pencil, Trash2, Loader2, FileDown, Calendar, FileSpreadsheet } from 'lucide-react';

const money = (value) =>
    Number(value ?? 0).toLocaleString('en-MY', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

const formatDate = (value) => {
    if (!value) return '-';
    const dateObj = typeof value === 'string' && !value.includes('T') && value.length === 10
        ? new Date(`${value}T00:00:00`)
        : new Date(value);
    if (isNaN(dateObj.getTime())) return '-';
    return dateObj.toLocaleDateString('en-MY', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
};

const formatTime = (value) => {
    if (!value) return '';
    try {
        const dateObj = new Date(value);
        if (isNaN(dateObj.getTime())) return '';
        return dateObj.toLocaleTimeString('en-MY', {
            hour: 'numeric',
            minute: '2-digit',
            hour12: true,
        }).toUpperCase();
    } catch {
        return '';
    }
};

const formatFullDate = (value) => {
    if (!value) return '-';
    const dateObj = typeof value === 'string' && !value.includes('T') && value.length === 10
        ? new Date(`${value}T00:00:00`)
        : new Date(value);
    if (isNaN(dateObj.getTime())) return '-';
    return dateObj.toLocaleDateString('en-MY', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    });
};

const normalizeUrl = (value) => {
    if (typeof value !== 'string' || !value) return '';

    const lastHttp = value.lastIndexOf('http://');
    const lastHttps = value.lastIndexOf('https://');
    const lastIndex = Math.max(lastHttp, lastHttps);
    let url = lastIndex > 0 ? value.slice(lastIndex) : value;

    url = url.replace('/storage/expense-items/', '/expense-items/');
    url = url.replace('/storage/receipts/', '/receipts/');

    if (url.includes('/expense-items/') || url.includes('/receipts/')) {
        url += url.includes('?') ? '&v=3' : '?v=3';
    }

    return url;
};

const getErrorMessage = (error) => {
    const data = error?.response?.data;

    if (data?.errors) {
        const first = Object.values(data.errors)?.[0]?.[0];
        if (first) return first;
    }

    return data?.message || error?.message || 'Something went wrong.';
};

const isMoneyIn = (transaction) => transaction.type === 'topup';

const transactionLabel = (transaction) => {
    if (transaction.type === 'topup') return 'Cash in';
    if (transaction.type === 'return_to_admin') return 'Returned to Admin';
    return 'Expense';
};

export default function AdminTransactions() {
    const [modalOpen, setModalOpen] = useState(false);
    const [editingTransaction, setEditingTransaction] = useState(null);
    const [deletingId, setDeletingId] = useState(null);
    const [selectedStaffId, setSelectedStaffId] = useState('');
    const [activeTab, setActiveTab] = useState('transactions');
    const [exporting, setExporting] = useState(false);
    const [exportingConsolidated, setExportingConsolidated] = useState(false);
    const [startDate, setStartDate] = useState('');
    const [endDate, setEndDate] = useState('');
    const [consolidatedData, setConsolidatedData] = useState(null);
    const [loadingConsolidated, setLoadingConsolidated] = useState(false);
    const exportPollRef = useRef(null);
    const exportTimeoutRef = useRef(null);

    const stopExportPolling = useCallback(() => {
        if (exportPollRef.current) {
            clearInterval(exportPollRef.current);
            exportPollRef.current = null;
        }
        if (exportTimeoutRef.current) {
            clearTimeout(exportTimeoutRef.current);
            exportTimeoutRef.current = null;
        }
    }, []);

    const { data: supervisorsData } = useQuery({
        queryKey: ['adminSupervisorsForFilter'],
        queryFn: async () => {
            const res = await api.get('/admin/supervisors');
            return res.data;
        },
        retry: 1,
    });

    const supervisors = normalizeSupervisors(supervisorsData) || [];

    const { data, isLoading, isError, error, refetch, isFetching } = useQuery({
        queryKey: ['adminTransactions', selectedStaffId],
        queryFn: async () => {
            const res = await api.get('/admin/transactions', {
                params: { user_id: selectedStaffId || undefined }
            });
            return res.data;
        },
        retry: 1,
    });

    const transactions = data?.transactions ?? [];
    const totalAmount = transactions.reduce((sum, tx) => sum + Number(tx.amount || 0), 0);

    const openEditModal = (transaction) => {
        setEditingTransaction(transaction);
        setModalOpen(true);
    };

    const handleDelete = async (transaction) => {
        const confirmed = window.confirm(
            `Delete transaction #${transaction.id.toString().padStart(4, '0')}?\n\nBalance will be recalculated from transactions. This action cannot be undone.`
        );

        if (!confirmed) return;

        setDeletingId(transaction.id);
        try {
            await api.delete(`/admin/transactions/${transaction.id}`);
            toast.success('Transaction deleted.');
            refetch();
        } catch (err) {
            toast.error(getErrorMessage(err));
        } finally {
            setDeletingId(null);
        }
    };

    const handleExportAll = useCallback(async () => {
        stopExportPolling();
        setExporting(true);

        try {
            const { data } = await api.post('/admin/transactions/export-excel');
            const { job_id } = data;

            const intervalId = setInterval(async () => {
                try {
                    const { data: statusData } = await api.get(`/admin/export-status/${job_id}`);

                    if (statusData.status === 'completed') {
                        stopExportPolling();
                        const response = await api.get(`/admin/export-download/${job_id}`, {
                            responseType: 'blob'
                        });
                        const blob = new Blob([response.data]);
                        const url = window.URL.createObjectURL(blob);
                        const a = document.createElement('a');
                        a.href = url;
                        a.download = `petty-cash-all-staff-${new Date().toISOString().split('T')[0]}.xlsx`;
                        document.body.appendChild(a);
                        a.click();
                        window.URL.revokeObjectURL(url);
                        a.remove();
                        setExporting(false);
                    } else if (statusData.status === 'failed') {
                        stopExportPolling();
                        toast.error(statusData.error || 'Export failed');
                        setExporting(false);
                    }
                } catch (err) {
                    if (err.response?.status === 404) {
                        stopExportPolling();
                        toast.error('Export status not found');
                        setExporting(false);
                    }
                }
            }, 3000);

            exportPollRef.current = intervalId;

            exportTimeoutRef.current = setTimeout(() => {
                stopExportPolling();
                toast.error('Export taking too long. Please try again.');
                setExporting(false);
            }, 120000);
        } catch (err) {
            toast.error(err.response?.data?.message || 'Failed to start export');
            setExporting(false);
        }
    }, [stopExportPolling]);

    const handleGenerateConsolidated = useCallback(async () => {
        if (!startDate || !endDate) {
            toast.error('Please select start and end dates');
            return;
        }

        setLoadingConsolidated(true);
        try {
            const { data } = await api.get('/admin/transactions/consolidated-report', {
                params: { start_date: startDate, end_date: endDate }
            });
            setConsolidatedData(data);
        } catch (err) {
            toast.error(getErrorMessage(err));
        } finally {
            setLoadingConsolidated(false);
        }
    }, [startDate, endDate]);

    const handleExportConsolidated = useCallback(async () => {
        if (!startDate || !endDate) {
            toast.error('Please select start and end dates');
            return;
        }

        stopExportPolling();
        setExportingConsolidated(true);

        try {
            const { data } = await api.post('/admin/transactions/consolidated-report/export', {
                start_date: startDate,
                end_date: endDate,
            });
            const { job_id } = data;

            const intervalId = setInterval(async () => {
                try {
                    const { data: statusData } = await api.get(`/admin/export-status/${job_id}`);

                    if (statusData.status === 'completed') {
                        stopExportPolling();
                        const response = await api.get(`/admin/export-download/${job_id}`, {
                            responseType: 'blob'
                        });
                        const blob = new Blob([response.data]);
                        const url = window.URL.createObjectURL(blob);
                        const a = document.createElement('a');
                        a.href = url;
                        a.download = `petty-cash-consolidated-${new Date().toISOString().split('T')[0]}.xlsx`;
                        document.body.appendChild(a);
                        a.click();
                        window.URL.revokeObjectURL(url);
                        a.remove();
                        setExportingConsolidated(false);
                    } else if (statusData.status === 'failed') {
                        stopExportPolling();
                        toast.error(statusData.error || 'Export failed');
                        setExportingConsolidated(false);
                    }
                } catch (err) {
                    if (err.response?.status === 404) {
                        stopExportPolling();
                        toast.error('Export status not found');
                        setExportingConsolidated(false);
                    }
                }
            }, 3000);

            exportPollRef.current = intervalId;

            exportTimeoutRef.current = setTimeout(() => {
                stopExportPolling();
                toast.error('Export taking too long. Please try again.');
                setExportingConsolidated(false);
            }, 120000);
        } catch (err) {
            toast.error(err.response?.data?.message || 'Failed to start export');
            setExportingConsolidated(false);
        }
    }, [startDate, endDate, stopExportPolling]);

    useEffect(() => {
        return () => stopExportPolling();
    }, [stopExportPolling]);

    return (
        <div className="space-y-6">
            <div className="flex items-end justify-between gap-4">
                <div>
                    <h2 className="text-2xl md:text-3xl font-black text-slate-900 tracking-tight">Staff Petty Cash Transactions</h2>
                    <p className="text-slate-500 text-sm font-medium mt-0.5">Cash sent and expenses recorded by staff</p>
                </div>

                <button
                    type="button"
                    onClick={() => refetch()}
                    className="hidden md:inline-flex items-center gap-2 rounded-xl border border-slate-200/70 bg-white px-4 py-2.5 text-sm font-bold text-slate-600 shadow-sm transition-colors hover:border-slate-300 hover:text-emerald-600"
                >
                    <RefreshCw size={16} strokeWidth={2.5} className={isFetching ? 'animate-spin text-emerald-600' : ''} />
                    Refresh
                </button>
            </div>

            <AdminTransactionModal
                isOpen={modalOpen}
                onClose={() => setModalOpen(false)}
                onSaved={refetch}
                transaction={editingTransaction}
            />

            <div className="flex items-center gap-2 border-b border-slate-200">
                <button
                    onClick={() => setActiveTab('transactions')}
                    className={`px-4 py-3 text-sm font-bold whitespace-nowrap transition-colors border-b-2 -mb-px ${
                        activeTab === 'transactions'
                            ? 'border-emerald-500 text-emerald-600'
                            : 'border-transparent text-slate-500 hover:text-slate-700'
                    }`}
                >
                    All Transactions
                </button>
                <button
                    onClick={() => setActiveTab('consolidated')}
                    className={`px-4 py-3 text-sm font-bold whitespace-nowrap transition-colors border-b-2 -mb-px ${
                        activeTab === 'consolidated'
                            ? 'border-emerald-500 text-emerald-600'
                            : 'border-transparent text-slate-500 hover:text-slate-700'
                    }`}
                >
                    Consolidated Report
                </button>
            </div>

            {activeTab === 'transactions' && (<>
            <div className="rounded-[2rem] border border-slate-200/60 bg-gradient-to-br from-slate-900 to-slate-800 p-5 md:p-6 text-white shadow-lg shadow-slate-900/10">
                <div className="flex items-center gap-3">
                    <div className="w-11 h-11 rounded-2xl bg-white/10 flex items-center justify-center border border-white/10">
                        <BadgeInfo size={20} strokeWidth={2.5} />
                    </div>
                    <div>
                        <p className="text-[10px] font-bold uppercase tracking-[0.25em] text-slate-300">Total amount recorded</p>
                        <p className="text-2xl md:text-3xl font-black mt-1">RM {money(totalAmount)}</p>
                    </div>
                </div>
            </div>
            <div className="bg-white border border-slate-200/60 rounded-[2rem] overflow-hidden shadow-sm">
                <div className="p-5 md:p-6 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-slate-50/50">
                    <div className="flex items-center gap-3">
                        <div className="p-2 bg-white rounded-xl shadow-sm border border-slate-200/50">
                            <History className="text-slate-700" size={18} strokeWidth={2.5} />
                        </div>
                        <h3 className="text-base font-bold text-slate-900">Transaction History</h3>
                    </div>

                    <div className="flex items-center gap-3 w-full sm:w-auto">
                        <select
                            value={selectedStaffId}
                            onChange={(e) => setSelectedStaffId(e.target.value)}
                            className="w-full sm:w-56 bg-white border border-slate-200/70 rounded-xl px-3 py-2 text-sm font-bold text-slate-700 shadow-sm outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500"
                        >
                            <option value="">All Staff</option>
                            {supervisors.map((sv) => (
                                <option key={sv.id} value={sv.id}>
                                    {sv.name}
                                </option>
                            ))}
                        </select>

                        <button
                            type="button"
                            onClick={handleExportAll}
                            disabled={exporting}
                            className="inline-flex items-center gap-2 px-4 py-2 bg-emerald-600 text-white rounded-xl text-sm font-bold hover:bg-emerald-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors shadow-sm shrink-0"
                        >
                            {exporting ? (
                                <><Loader2 className="w-4 h-4 animate-spin" /> Exporting...</>
                            ) : (
                                <><FileDown size={16} /> Export Excel</>
                            )}
                        </button>

                        <button
                            type="button"
                            onClick={() => refetch()}
                            className="md:hidden w-9 h-9 flex items-center justify-center shrink-0 rounded-xl bg-white border border-slate-200/50 text-slate-500 transition-all hover:border-slate-300 hover:text-emerald-600 shadow-sm active:scale-95"
                        >
                            <RefreshCw size={16} strokeWidth={2.5} className={isFetching ? 'animate-spin text-emerald-600' : ''} />
                        </button>
                    </div>
                </div>
                {isLoading ? (
                    <div className="p-12 text-center text-emerald-600 font-bold animate-pulse">
                        Loading transactions...
                    </div>
                ) : isError ? (
                    <div className="p-12 text-center">
                        <p className="text-red-500 font-bold">Failed to load transaction history.</p>
                        <p className="mt-1 text-xs font-medium text-slate-400">
                            {error?.response?.data?.message || error?.message || 'Please try refreshing.'}
                        </p>
                    </div>
                ) : transactions.length === 0 ? (
                    <div className="p-12 text-center text-slate-400">
                        <div className="w-16 h-16 bg-slate-50 rounded-full flex items-center justify-center mx-auto mb-3">
                            <FileText size={24} className="text-slate-300" />
                        </div>
                        <p className="font-bold text-slate-700">No transactions yet</p>
                        <p className="mt-1 text-xs font-medium">Cash sent and expenses will appear here once they are recorded.</p>
                    </div>
                ) : (
                    <>
                        <div className="md:hidden divide-y divide-slate-100/80">
                            {transactions.map((tx) => (
                                <div key={tx.id} className="p-4">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="flex items-start gap-3 min-w-0">
                                            <div className={`w-11 h-11 flex items-center justify-center rounded-2xl shrink-0 shadow-sm border ${
                                                isMoneyIn(tx)
                                                    ? 'bg-emerald-50 border-emerald-100 text-emerald-600'
                                                    : 'bg-white border-slate-200 text-slate-700'
                                            }`}>
                                                {isMoneyIn(tx) ? <ArrowDownLeft size={20} strokeWidth={2.5} /> : <ArrowUpRight size={20} strokeWidth={2.5} />}
                                            </div>
                                            <div className="min-w-0">
                                                <p className="font-bold text-slate-900 text-[15px] leading-tight truncate">{tx.description || 'No description'}</p>
                                                <div className="mt-1 flex flex-wrap items-center gap-2 text-[11px] font-semibold text-slate-400">
                                                    <span className="inline-flex items-center gap-1">
                                                        <UserRound size={12} />
                                                        {tx.user?.name || 'Unknown user'}
                                                    </span>
                                                    <span className="w-1 h-1 rounded-full bg-slate-300"></span>
                                                    <span className="uppercase tracking-wider">{tx.user?.department || tx.user?.role || '-'}</span>
                                                </div>
                                                <div className="mt-2 flex flex-wrap items-center gap-2 text-[11px] font-semibold text-slate-400">
                                                    <span>
                                                        {formatDate(tx.date || tx.created_at)}
                                                        {tx.created_at && ` • ${formatTime(tx.created_at)}`}
                                                    </span>
                                                    {tx.site_id && <span className="text-slate-500">Site {tx.site_id}</span>}
                                                    {Array.isArray(tx.metadata?.item_images) && tx.metadata.item_images.length > 0 && (
                                                        <a
                                                            href={normalizeUrl(tx.metadata.item_images[0]?.url)}
                                                            className="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-sky-600 underline decoration-sky-200 underline-offset-2 hover:bg-sky-50 hover:text-sky-700"
                                                            target="_blank"
                                                            rel="noreferrer"
                                                        >
                                                            <FileText size={12} />
                                                            Item Photos ({tx.metadata.item_images.length})
                                                        </a>
                                                    )}
                                                    {tx.receipt_url && (
                                                        <a
                                                            href={normalizeUrl(tx.receipt_url)}
                                                            className="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-emerald-600 underline decoration-emerald-200 underline-offset-2 hover:bg-emerald-50 hover:text-emerald-700"
                                                            target="_blank"
                                                            rel="noreferrer"
                                                        >
                                                            <ReceiptText size={12} />
                                                            Receipt
                                                        </a>
                                                    )}
                                                </div>
                                            </div>
                                        </div>
                                        <div className="text-right shrink-0">
                                        <p className={`text-[16px] font-black tracking-tight ${isMoneyIn(tx) ? 'text-emerald-600' : 'text-slate-900'}`}>
                                            {isMoneyIn(tx) ? '+' : '-'}RM {money(tx.amount)}
                                            </p>
                                            <p className="text-[9px] text-slate-400 font-bold tracking-widest mt-0.5">
                                                #{tx.id.toString().padStart(4, '0')}
                                            </p>
                                        </div>
                                    </div>
                                    {tx.type === 'return_to_admin' ? (
                                        <span className="text-[10px] text-slate-400 font-semibold">System record</span>
                                    ) : (
                                        <div className="mt-4 grid grid-cols-2 gap-2">
                                            <button
                                                type="button"
                                                onClick={() => openEditModal(tx)}
                                                className="inline-flex items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-xs font-bold text-slate-600 hover:text-emerald-600"
                                            >
                                                <Pencil size={14} />
                                                Edit
                                            </button>
                                            <button
                                                type="button"
                                                disabled={deletingId === tx.id}
                                                onClick={() => handleDelete(tx)}
                                                className="inline-flex items-center justify-center gap-2 rounded-xl border border-red-100 bg-red-50 px-3 py-2.5 text-xs font-bold text-red-600 hover:bg-red-100 disabled:opacity-50"
                                            >
                                                <Trash2 size={14} />
                                                {deletingId === tx.id ? 'Deleting...' : 'Delete'}
                                            </button>
                                        </div>
                                    )}
                                </div>
                            ))}
                        </div>

                        <div className="hidden md:block overflow-x-auto">
                            <table className="w-full min-w-[700px] text-left">
                                <thead className="bg-slate-50/50 text-slate-400 text-xs uppercase tracking-widest font-bold border-b border-slate-100">
                                    <tr>
                                        <th className="px-6 py-5">Transaction</th>
                                        <th className="px-6 py-5">Amount</th>
                                        <th className="px-6 py-5">Date</th>
                                        <th className="px-6 py-5">User</th>
                                        <th className="sticky right-0 z-10 bg-slate-50/95 px-4 py-5 text-right shadow-[-8px_0_16px_-16px_rgba(15,23,42,0.35)]">Actions</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100/80">
                                    {transactions.map((tx) => (
                                        <tr key={tx.id} className="group hover:bg-slate-50/50 transition-colors">
                                            <td className="px-6 py-4">
                                                <div className="flex items-center gap-3">
                                                    <div className={`w-10 h-10 flex items-center justify-center rounded-2xl shrink-0 border ${
                                                        isMoneyIn(tx)
                                                            ? 'bg-emerald-50 border-emerald-100 text-emerald-600'
                                                            : 'bg-white border-slate-200 text-slate-700'
                                                    }`}>
                                                        {isMoneyIn(tx) ? <ArrowDownLeft size={18} strokeWidth={2.5} /> : <ArrowUpRight size={18} strokeWidth={2.5} />}
                                                    </div>
                                                    <div className="min-w-0">
                                                        <p className="font-bold text-slate-900 truncate">{tx.description || 'No description'}</p>
                                                        <p className="text-[11px] text-slate-400 font-medium mt-0.5">
                                                            {tx.site_id ? `Site ${tx.site_id}` : transactionLabel(tx)}
                                                            {tx.receipt_url ? ' • Receipt attached' : ''}
                                                        </p>
                                                    </div>
                                                </div>
                                            </td>
                                            <td className="px-6 py-4">
                                                <p className={`text-[15px] font-black ${isMoneyIn(tx) ? 'text-emerald-600' : 'text-slate-900'}`}>
                                                    {isMoneyIn(tx) ? '+' : '-'}RM {money(tx.amount)}
                                                </p>
                                            </td>
                                            <td className="px-6 py-4">
                                                <p className="text-slate-700 font-medium">{formatDate(tx.date || tx.created_at)}</p>
                                                {tx.created_at && (
                                                    <p className="text-[11px] text-slate-400 font-medium mt-0.5">
                                                        {formatTime(tx.created_at)}
                                                    </p>
                                                )}
                                            </td>
                                            <td className="px-6 py-4">
                                                <div className="flex items-center gap-2 text-slate-700 font-semibold">
                                                    <UserRound size={14} className="text-slate-400" />
                                                    <span>{tx.user?.name || 'Unknown user'}</span>
                                                    <span className="text-[10px] uppercase tracking-widest text-slate-400">
                                                        {tx.user?.department || tx.user?.role || '-'}
                                                    </span>
                                                </div>
                                            </td>
                                            <td className="sticky right-0 z-10 bg-white px-4 py-4 shadow-[-8px_0_16px_-16px_rgba(15,23,42,0.35)] group-hover:bg-slate-50/95">
                                                {tx.type === 'return_to_admin' ? (
                                                    <div className="flex justify-center">
                                                        <span className="text-[10px] text-slate-400 font-semibold">System record</span>
                                                    </div>
                                                ) : (
                                                    <div className="flex justify-end gap-2 whitespace-nowrap">
                                                        <button
                                                            type="button"
                                                            onClick={() => openEditModal(tx)}
                                                            className="inline-flex shrink-0 items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-bold text-slate-600 hover:border-emerald-200 hover:bg-emerald-50 hover:text-emerald-700"
                                                        >
                                                            <Pencil size={14} />
                                                            Edit
                                                        </button>
                                                        <button
                                                            type="button"
                                                            disabled={deletingId === tx.id}
                                                            onClick={() => handleDelete(tx)}
                                                            className="inline-flex shrink-0 items-center gap-1.5 rounded-xl border border-red-100 bg-red-50 px-3 py-2 text-xs font-bold text-red-600 hover:bg-red-100 disabled:opacity-50"
                                                        >
                                                            <Trash2 size={14} />
                                                            {deletingId === tx.id ? 'Deleting...' : 'Delete'}
                                                        </button>
                                                    </div>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </>
                )}
            </div>

            </>)}

            {activeTab === 'consolidated' && (
            <div className="space-y-6">
                <div className="flex flex-wrap gap-4 items-end">
                    <div>
                        <label className="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-1.5">Start Date</label>
                        <div className="relative">
                            <Calendar size={16} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                            <input
                                type="date"
                                value={startDate}
                                onChange={(e) => { setStartDate(e.target.value); setConsolidatedData(null); }}
                                className="w-full pl-10 pr-3 py-2.5 bg-white border border-slate-200/70 rounded-xl text-sm font-semibold text-slate-700 shadow-sm outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500"
                            />
                        </div>
                    </div>
                    <div>
                        <label className="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-1.5">End Date</label>
                        <div className="relative">
                            <Calendar size={16} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                            <input
                                type="date"
                                value={endDate}
                                onChange={(e) => { setEndDate(e.target.value); setConsolidatedData(null); }}
                                className="w-full pl-10 pr-3 py-2.5 bg-white border border-slate-200/70 rounded-xl text-sm font-semibold text-slate-700 shadow-sm outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500"
                            />
                        </div>
                    </div>
                    <button
                        onClick={handleGenerateConsolidated}
                        disabled={!startDate || !endDate || loadingConsolidated}
                        className="inline-flex items-center gap-2 px-5 py-2.5 bg-blue-600 text-white rounded-xl text-sm font-bold hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors shadow-sm"
                    >
                        {loadingConsolidated ? (
                            <><Loader2 className="w-4 h-4 animate-spin" /> Loading...</>
                        ) : (
                            <><FileSpreadsheet size={16} /> Generate Report</>
                        )}
                    </button>
                    <button
                        onClick={handleExportConsolidated}
                        disabled={!consolidatedData || exportingConsolidated}
                        className="inline-flex items-center gap-2 px-5 py-2.5 bg-emerald-600 text-white rounded-xl text-sm font-bold hover:bg-emerald-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors shadow-sm"
                    >
                        {exportingConsolidated ? (
                            <><Loader2 className="w-4 h-4 animate-spin" /> Exporting...</>
                        ) : (
                            <><FileDown size={16} /> Export Excel</>
                        )}
                    </button>
                </div>

                {consolidatedData && (
                    <>
                        <div className="bg-slate-50 border border-slate-200/60 rounded-[1rem] px-5 md:px-6 py-3 md:py-4 flex flex-col sm:flex-row sm:items-center justify-between gap-2 shadow-sm">
                            <span className="text-[10px] font-bold uppercase tracking-widest text-slate-400">Report Period</span>
                            <span className="text-sm font-bold text-slate-700">
                                {formatFullDate(consolidatedData.summary.start_date)} - {formatFullDate(consolidatedData.summary.end_date)}
                            </span>
                        </div>

                        <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3">
                            <div className="rounded-2xl border border-sky-100 bg-sky-50 p-4 shadow-sm">
                                <p className="text-[10px] font-bold uppercase tracking-widest text-sky-500">Staff</p>
                                <p className="text-xl font-black text-sky-700 mt-1">{consolidatedData.summary.total_staff}</p>
                            </div>
                            <div className="rounded-2xl border border-slate-200/60 bg-white p-4 shadow-sm">
                                <p className="text-[10px] font-bold uppercase tracking-widest text-slate-400">Transactions</p>
                                <p className="text-xl font-black text-slate-800 mt-1">{consolidatedData.summary.total_transactions}</p>
                            </div>
                            <div className="rounded-2xl border border-emerald-100 bg-emerald-50 p-4 shadow-sm">
                                <p className="text-[10px] font-bold uppercase tracking-widest text-emerald-500">Total Topup</p>
                                <p className="text-xl font-black text-emerald-700 mt-1">RM {money(consolidatedData.summary.total_topup)}</p>
                            </div>
                            <div className="rounded-2xl border border-red-100 bg-red-50 p-4 shadow-sm">
                                <p className="text-[10px] font-bold uppercase tracking-widest text-red-500">Total Expense</p>
                                <p className="text-xl font-black text-red-700 mt-1">RM {money(consolidatedData.summary.total_expense)}</p>
                            </div>
                            <div className="rounded-2xl border border-rose-100 bg-rose-50 p-4 shadow-sm">
                                <p className="text-[10px] font-bold uppercase tracking-widest text-rose-500">Returned to Admin</p>
                                <p className="text-xl font-black text-rose-700 mt-1">RM {money(consolidatedData.summary.total_returned_to_admin)}</p>
                            </div>
                            <div className="rounded-2xl border border-violet-100 bg-violet-50 p-4 shadow-sm">
                                <p className="text-[10px] font-bold uppercase tracking-widest text-violet-500">Balance</p>
                                <p className="text-xl font-black text-violet-700 mt-1">RM {money(consolidatedData.summary.balance)}</p>
                            </div>
                        </div>

                        {consolidatedData.by_staff?.length > 0 && (
                        <div className="bg-white border border-slate-200/60 rounded-2xl overflow-hidden shadow-sm">
                            <div className="p-4 border-b border-slate-100">
                                <h3 className="text-base font-bold text-slate-900">By Staff</h3>
                            </div>
                            <div className="overflow-x-auto p-2">
                                <table className="w-full text-left text-sm">
                                    <thead>
                                        <tr className="text-[10px] uppercase tracking-widest font-bold text-slate-400 border-b border-slate-100">
                                            <th className="px-3 py-3">Staff</th>
                                            <th className="px-3 py-3">Department</th>
                                            <th className="px-3 py-3 text-right">Topup</th>
                                            <th className="px-3 py-3 text-right">Expense</th>
                                            <th className="px-3 py-3 text-right">Returned</th>
                                            <th className="px-3 py-3 text-right">Balance</th>
                                            <th className="px-3 py-3 text-right"># Tx</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100">
                                        {consolidatedData.by_staff.map((staff) => (
                                            <tr key={staff.user_id} className="hover:bg-slate-50/50">
                                                <td className="px-3 py-3 font-bold text-slate-900">{staff.name}</td>
                                                <td className="px-3 py-3 text-slate-500 font-medium">{staff.department}</td>
                                                <td className="px-3 py-3 text-right font-semibold text-emerald-600">RM {money(staff.total_topup)}</td>
                                                <td className="px-3 py-3 text-right font-semibold text-red-600">RM {money(staff.total_expense)}</td>
                                                <td className="px-3 py-3 text-right font-semibold text-rose-600">RM {money(staff.total_returned_to_admin)}</td>
                                                <td className="px-3 py-3 text-right font-bold text-slate-900">RM {money(staff.balance)}</td>
                                                <td className="px-3 py-3 text-right font-medium text-slate-500">{staff.transaction_count}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        )}

                        {consolidatedData.by_department?.length > 0 && (
                        <div className="bg-white border border-slate-200/60 rounded-2xl overflow-hidden shadow-sm">
                            <div className="p-4 border-b border-slate-100">
                                <h3 className="text-base font-bold text-slate-900">By Department</h3>
                            </div>
                            <div className="overflow-x-auto p-2">
                                <table className="w-full text-left text-sm">
                                    <thead>
                                        <tr className="text-[10px] uppercase tracking-widest font-bold text-slate-400 border-b border-slate-100">
                                            <th className="px-3 py-3">Department</th>
                                            <th className="px-3 py-3 text-right">Staff</th>
                                            <th className="px-3 py-3 text-right">Topup</th>
                                            <th className="px-3 py-3 text-right">Expense</th>
                                            <th className="px-3 py-3 text-right">Returned</th>
                                            <th className="px-3 py-3 text-right">Balance</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100">
                                        {consolidatedData.by_department.map((dept, i) => (
                                            <tr key={i} className="hover:bg-slate-50/50">
                                                <td className="px-3 py-3 font-bold text-slate-900">{dept.department}</td>
                                                <td className="px-3 py-3 text-right font-medium text-slate-500">{dept.total_staff}</td>
                                                <td className="px-3 py-3 text-right font-semibold text-emerald-600">RM {money(dept.total_topup)}</td>
                                                <td className="px-3 py-3 text-right font-semibold text-red-600">RM {money(dept.total_expense)}</td>
                                                <td className="px-3 py-3 text-right font-semibold text-rose-600">RM {money(dept.total_returned_to_admin)}</td>
                                                <td className="px-3 py-3 text-right font-bold text-slate-900">RM {money(dept.balance)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        )}

                        {consolidatedData.top_expenses?.length > 0 && (
                        <div className="bg-white border border-slate-200/60 rounded-2xl overflow-hidden shadow-sm">
                            <div className="p-4 border-b border-slate-100">
                                <h3 className="text-base font-bold text-slate-900">Top 10 Expenses</h3>
                            </div>
                            <div className="overflow-x-auto p-2">
                                <table className="w-full text-left text-sm">
                                    <thead>
                                        <tr className="text-[10px] uppercase tracking-widest font-bold text-slate-400 border-b border-slate-100">
                                            <th className="px-3 py-3">Date</th>
                                            <th className="px-3 py-3">Staff</th>
                                            <th className="px-3 py-3">Payment To</th>
                                            <th className="px-3 py-3">Details</th>
                                            <th className="px-3 py-3 text-right">Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100">
                                        {consolidatedData.top_expenses.map((tx, i) => (
                                            <tr key={i} className="hover:bg-slate-50/50">
                                                <td className="px-3 py-3 font-medium text-slate-500">{tx.date}</td>
                                                <td className="px-3 py-3 font-bold text-slate-900">{tx.staff}</td>
                                                <td className="px-3 py-3 text-slate-700">{tx.payment_to}</td>
                                                <td className="px-3 py-3 text-slate-500">{tx.details}</td>
                                                <td className="px-3 py-3 text-right font-bold text-red-600">RM {money(tx.amount)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        )}

                        {consolidatedData.top_expenses?.length === 0 && (
                        <div className="p-12 text-center text-slate-400">
                            <p className="font-bold text-slate-700">No expenses found</p>
                            <p className="mt-1 text-xs font-medium">No expense transactions in the selected date range.</p>
                        </div>
                        )}
                    </>
                )}

                {!consolidatedData && !loadingConsolidated && (
                <div className="p-12 text-center text-slate-400">
                    <div className="w-16 h-16 bg-slate-50 rounded-full flex items-center justify-center mx-auto mb-3">
                        <FileSpreadsheet size={24} className="text-slate-300" />
                    </div>
                    <p className="font-bold text-slate-700">Select a date range and click Generate Report</p>
                    <p className="mt-1 text-xs font-medium">Consolidated summary will appear here.</p>
                </div>
                )}
            </div>
            )}

            {/* Mobile bottom nav spacer */}
            <div className="h-24 md:hidden" />
        </div>
    );
}
