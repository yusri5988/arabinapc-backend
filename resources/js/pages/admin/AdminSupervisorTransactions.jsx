import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link, useParams } from 'react-router-dom';
import api from '../../lib/axios';
import TransactionDetailModal from '../../components/TransactionDetailModal';
import { ArrowLeft, ArrowDownLeft, ArrowUpRight, History, RefreshCw, FileText, UserRound, Wallet, ReceiptText } from 'lucide-react';

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

const formatDateTime = (value) => {
    if (!value) return '';
    try {
        const dateObj = new Date(value);
        if (isNaN(dateObj.getTime())) return '';
        const d = dateObj.toLocaleDateString('en-MY', {
            day: 'numeric',
            month: 'short',
            year: 'numeric',
        });
        const t = dateObj.toLocaleTimeString('en-MY', {
            hour: 'numeric',
            minute: '2-digit',
            hour12: true,
        }).toUpperCase();
        return `${d}, ${t}`;
    } catch {
        return '';
    }
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

const isMoneyIn = (transaction) => transaction.type === 'topup';

const transactionLabel = (transaction) => {
    if (transaction.type === 'topup') return 'Cash in';
    if (transaction.type === 'return_to_admin') return 'Returned to Admin';
    return 'Expense';
};

export default function AdminSupervisorTransactions() {
    const { supervisorId } = useParams();
    const [viewingTransaction, setViewingTransaction] = useState(null);

    const { data, isLoading, isError, error, refetch, isFetching } = useQuery({
        queryKey: ['adminSupervisorTransactions', supervisorId],
        queryFn: async () => {
            const res = await api.get(`/admin/supervisors/${supervisorId}/transactions`);
            return res.data;
        },
        enabled: Boolean(supervisorId),
        retry: 1,
    });

    const supervisor = data?.supervisor;
    const transactions = data?.transactions ?? [];

    return (
        <div className="space-y-6">
            <TransactionDetailModal
                isOpen={Boolean(viewingTransaction)}
                transaction={viewingTransaction ? { ...viewingTransaction, user: viewingTransaction.user || supervisor } : null}
                onClose={() => setViewingTransaction(null)}
            />

            <div className="flex items-end justify-between gap-4">
                <div className="space-y-2">

                    <div>
                        <h2 className="text-2xl md:text-3xl font-black text-slate-900 tracking-tight">
                            {supervisor?.name || 'Staff'} History
                        </h2>
                        <p className="text-slate-500 text-sm font-medium mt-0.5">
                            All transactions recorded for this staff member
                        </p>
                    </div>
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

            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div className="rounded-[2rem] border border-slate-200/60 bg-white p-5 shadow-sm">
                    <p className="text-[10px] font-bold uppercase tracking-widest text-slate-400">Balance</p>
                    <p className="mt-2 text-3xl font-black text-emerald-600">RM {money(supervisor?.balance)}</p>
                    <p className="mt-2 text-xs font-medium text-slate-400">{supervisor?.phone || '-'}</p>
                </div>
            </div>


            <div className="bg-white border border-slate-200/60 rounded-[2rem] overflow-hidden shadow-sm">
                <div className="p-5 md:p-6 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
                    <div className="flex items-center gap-3">
                        <div className="p-2 bg-white rounded-xl shadow-sm border border-slate-200/50">
                            <History className="text-slate-700" size={18} strokeWidth={2.5} />
                        </div>
                        <h3 className="text-base font-bold text-slate-900">Staff Transaction History</h3>
                    </div>

                    <button
                        type="button"
                        onClick={() => refetch()}
                        className="md:hidden w-9 h-9 flex items-center justify-center rounded-xl bg-white border border-slate-200/50 text-slate-500 transition-all hover:border-slate-300 hover:text-emerald-600 shadow-sm active:scale-95"
                    >
                        <RefreshCw size={16} strokeWidth={2.5} className={isFetching ? 'animate-spin text-emerald-600' : ''} />
                    </button>
                </div>

                {isLoading ? (
                    <div className="p-12 text-center text-emerald-600 font-bold animate-pulse">
                        Loading staff transactions...
                    </div>
                ) : isError ? (
                    <div className="p-12 text-center">
                        <p className="text-red-500 font-bold">Failed to load this staff member's transaction history.</p>
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
                        <p className="mt-1 text-xs font-medium">This staff member has not recorded any cash sent or expenses yet.</p>
                    </div>
                ) : (
                    <>
                        <div className="md:hidden divide-y divide-slate-100/80">
                            {transactions.map((tx) => (
                                <div
                                    key={tx.id}
                                    onClick={() => setViewingTransaction(tx)}
                                    className="p-4 cursor-pointer hover:bg-slate-50/80 active:bg-slate-100 transition-colors"
                                >
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="flex items-start gap-3 min-w-0 flex-1">
                                            <div className={`w-11 h-11 flex items-center justify-center rounded-2xl shrink-0 shadow-sm border ${
                                                isMoneyIn(tx)
                                                    ? 'bg-emerald-50 border-emerald-100 text-emerald-600'
                                                    : 'bg-white border-slate-200 text-slate-700'
                                            }`}>
                                                {isMoneyIn(tx) ? <ArrowDownLeft size={20} strokeWidth={2.5} /> : <ArrowUpRight size={20} strokeWidth={2.5} />}
                                            </div>
                                            <div className="min-w-0 flex-1 max-w-[200px] sm:max-w-sm">
                                                <p className="font-bold text-slate-900 text-[15px] leading-tight truncate" title={tx.description || 'No description'}>
                                                    {tx.description || 'No description'}
                                                </p>
                                                <div className="mt-1 flex flex-wrap items-center gap-2 text-[11px] font-semibold text-slate-400">
                                                    <span className="text-slate-700 font-bold">
                                                        {formatDate(tx.date || tx.created_at)}
                                                    </span>
                                                    {tx.created_at && (
                                                        <span className="text-slate-400">
                                                            • Created: {formatDateTime(tx.created_at)}
                                                        </span>
                                                    )}
                                                    {tx.site_id && <span className="text-slate-500">Site {tx.site_id}</span>}
                                                    {Array.isArray(tx.metadata?.item_images) && tx.metadata.item_images.length > 0 && (
                                                        <a
                                                            href={normalizeUrl(tx.metadata.item_images[0]?.url)}
                                                            onClick={(e) => e.stopPropagation()}
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
                                                            onClick={(e) => e.stopPropagation()}
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
                                </div>
                            ))}
                        </div>

                        <div className="hidden md:block overflow-x-auto">
                            <table className="w-full text-left">
                                <thead className="bg-slate-50/50 text-slate-400 text-xs uppercase tracking-widest font-bold border-b border-slate-100">
                                    <tr>
                                        <th className="px-6 py-5">Transaction</th>
                                        <th className="px-6 py-5">Date</th>
                                        <th className="px-6 py-5">Reference</th>
                                        <th className="px-6 py-5 text-right">Amount</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100/80">
                                    {transactions.map((tx) => (
                                        <tr
                                            key={tx.id}
                                            onClick={() => setViewingTransaction(tx)}
                                            className="hover:bg-slate-50/70 transition-colors cursor-pointer"
                                        >
                                            <td className="px-6 py-4 max-w-[280px] lg:max-w-[400px]">
                                                <div className="flex items-center gap-3">
                                                    <div className={`w-10 h-10 flex items-center justify-center rounded-2xl shrink-0 border ${
                                                        isMoneyIn(tx)
                                                            ? 'bg-emerald-50 border-emerald-100 text-emerald-600'
                                                            : 'bg-white border-slate-200 text-slate-700'
                                                    }`}>
                                                        {isMoneyIn(tx) ? <ArrowDownLeft size={18} strokeWidth={2.5} /> : <ArrowUpRight size={18} strokeWidth={2.5} />}
                                                    </div>
                                                    <div className="min-w-0 flex-1">
                                                        <p className="font-bold text-slate-900 truncate" title={tx.description || 'No description'}>
                                                            {tx.description || 'No description'}
                                                        </p>
                                                        <p className="text-[11px] text-slate-400 font-medium mt-0.5 truncate">
                                                            {transactionLabel(tx)}
                                                        </p>
                                                    </div>
                                                </div>
                                            </td>
                                            <td className="px-6 py-4">
                                                <p className="text-slate-900 font-bold">{formatDate(tx.date || tx.created_at)}</p>
                                                {tx.created_at && (
                                                    <p className="text-[11px] text-slate-400 font-medium mt-0.5" title="Tarikh & masa sistem rekod dicipta">
                                                        Created: {formatDateTime(tx.created_at)}
                                                    </p>
                                                )}
                                            </td>
                                            <td className="px-6 py-4 text-slate-500 font-medium">
                                                {tx.site_id || tx.receipt_url ? (
                                                    <div className="flex flex-col gap-1" onClick={(e) => e.stopPropagation()}>
                                                        {tx.site_id && <span>Site {tx.site_id}</span>}
                                                        {Array.isArray(tx.metadata?.item_images) && tx.metadata.item_images.length > 0 && (
                                                            <a
                                                                href={normalizeUrl(tx.metadata.item_images[0]?.url)}
                                                                className="inline-flex items-center gap-1 text-sky-600 hover:text-sky-700 font-bold"
                                                                target="_blank"
                                                                rel="noreferrer"
                                                            >
                                                                <FileText size={14} />
                                                                Item Photos ({tx.metadata.item_images.length})
                                                            </a>
                                                        )}
                                                        {tx.receipt_url && (
                                                            <a
                                                                href={normalizeUrl(tx.receipt_url)}
                                                                className="inline-flex items-center gap-1 text-emerald-600 hover:text-emerald-700 font-bold"
                                                                target="_blank"
                                                                rel="noreferrer"
                                                            >
                                                                <ReceiptText size={14} />
                                                                Receipt
                                                            </a>
                                                        )}
                                                    </div>
                                                ) : (
                                                    '-'
                                                )}
                                            </td>
                                            <td className="px-6 py-4 text-right">
                                                <p className={`text-[15px] font-black ${isMoneyIn(tx) ? 'text-emerald-600' : 'text-slate-900'}`}>
                                                    {isMoneyIn(tx) ? '+' : '-'}RM {money(tx.amount)}
                                                </p>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </>
                )}
            </div>
        </div>
    );
}
