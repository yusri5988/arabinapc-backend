import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import api from '../../lib/axios';
import AdminTransactionModal from '../../components/AdminTransactionModal';
import { ArrowDownLeft, ArrowUpRight, History, RefreshCw, FileText, UserRound, BadgeInfo, ReceiptText, Pencil, Trash2 } from 'lucide-react';

const money = (value) =>
    Number(value ?? 0).toLocaleString('en-MY', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

const formatDate = (value) => {
    if (!value) return '-';
    return new Date(value).toLocaleDateString('en-MY', {
        day: 'numeric',
        month: 'short',
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

export default function AdminTransactions() {
    const [modalOpen, setModalOpen] = useState(false);
    const [editingTransaction, setEditingTransaction] = useState(null);
    const [deletingId, setDeletingId] = useState(null);
    const { data, isLoading, isError, error, refetch, isFetching } = useQuery({
        queryKey: ['adminTransactions'],
        queryFn: async () => {
            const res = await api.get('/admin/transactions');
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
                <div className="p-5 md:p-6 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
                    <div className="flex items-center gap-3">
                        <div className="p-2 bg-white rounded-xl shadow-sm border border-slate-200/50">
                            <History className="text-slate-700" size={18} strokeWidth={2.5} />
                        </div>
                        <h3 className="text-base font-bold text-slate-900">Transaction History</h3>
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
                                                tx.type === 'topup'
                                                    ? 'bg-emerald-50 border-emerald-100 text-emerald-600'
                                                    : 'bg-white border-slate-200 text-slate-700'
                                            }`}>
                                                {tx.type === 'topup' ? <ArrowDownLeft size={20} strokeWidth={2.5} /> : <ArrowUpRight size={20} strokeWidth={2.5} />}
                                            </div>
                                            <div className="min-w-0">
                                                <p className="font-bold text-slate-900 text-[15px] leading-tight truncate">{tx.description || 'No description'}</p>
                                                <div className="mt-1 flex flex-wrap items-center gap-2 text-[11px] font-semibold text-slate-400">
                                                    <span className="inline-flex items-center gap-1">
                                                        <UserRound size={12} />
                                                        {tx.user?.name || 'Unknown user'}
                                                    </span>
                                                    <span className="w-1 h-1 rounded-full bg-slate-300"></span>
                                                    <span className="uppercase tracking-wider">{tx.user?.role || '-'}</span>
                                                </div>
                                                <div className="mt-2 flex flex-wrap items-center gap-2 text-[11px] font-semibold text-slate-400">
                                                    <span>{formatDate(tx.date)}</span>
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
                                            <p className={`text-[16px] font-black tracking-tight ${tx.type === 'topup' ? 'text-emerald-600' : 'text-slate-900'}`}>
                                                {tx.type === 'topup' ? '+' : '-'}RM {money(tx.amount)}
                                            </p>
                                            <p className="text-[9px] text-slate-400 font-bold tracking-widest mt-0.5">
                                                #{tx.id.toString().padStart(4, '0')}
                                            </p>
                                        </div>
                                    </div>
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
                                </div>
                            ))}
                        </div>

                        <div className="hidden md:block overflow-x-auto">
                            <table className="w-full min-w-[920px] text-left">
                                <thead className="bg-slate-50/50 text-slate-400 text-xs uppercase tracking-widest font-bold border-b border-slate-100">
                                    <tr>
                                        <th className="px-6 py-5">Transaction</th>
                                        <th className="px-6 py-5">User</th>
                                        <th className="px-6 py-5">Date</th>
                                        <th className="px-6 py-5 text-right">Amount</th>
                                        <th className="sticky right-0 z-10 bg-slate-50/95 px-4 py-5 text-right shadow-[-8px_0_16px_-16px_rgba(15,23,42,0.35)]">Actions</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100/80">
                                    {transactions.map((tx) => (
                                        <tr key={tx.id} className="group hover:bg-slate-50/50 transition-colors">
                                            <td className="px-6 py-4">
                                                <div className="flex items-center gap-3">
                                                    <div className={`w-10 h-10 flex items-center justify-center rounded-2xl shrink-0 border ${
                                                        tx.type === 'topup'
                                                            ? 'bg-emerald-50 border-emerald-100 text-emerald-600'
                                                            : 'bg-white border-slate-200 text-slate-700'
                                                    }`}>
                                                        {tx.type === 'topup' ? <ArrowDownLeft size={18} strokeWidth={2.5} /> : <ArrowUpRight size={18} strokeWidth={2.5} />}
                                                    </div>
                                                    <div className="min-w-0">
                                                        <p className="font-bold text-slate-900 truncate">{tx.description || 'No description'}</p>
                                                        <p className="text-[11px] text-slate-400 font-medium mt-0.5">
                                                            {tx.site_id ? `Site ${tx.site_id}` : 'No site reference'}
                                                            {tx.receipt_url ? ' • Receipt attached' : ''}
                                                        </p>
                                                    </div>
                                                </div>
                                            </td>
                                            <td className="px-6 py-4">
                                                <div className="flex items-center gap-2 text-slate-700 font-semibold">
                                                    <UserRound size={14} className="text-slate-400" />
                                                    <span>{tx.user?.name || 'Unknown user'}</span>
                                                    <span className="text-[10px] uppercase tracking-widest text-slate-400">
                                                        {tx.user?.role || '-'}
                                                    </span>
                                                </div>
                                            </td>
                                            <td className="px-6 py-4 text-slate-500 font-medium">{formatDate(tx.date)}</td>
                                            <td className="px-6 py-4 text-right">
                                                <p className={`text-[15px] font-black ${tx.type === 'topup' ? 'text-emerald-600' : 'text-slate-900'}`}>
                                                    {tx.type === 'topup' ? '+' : '-'}RM {money(tx.amount)}
                                                </p>
                                            </td>
                                            <td className="sticky right-0 z-10 bg-white px-4 py-4 shadow-[-8px_0_16px_-16px_rgba(15,23,42,0.35)] group-hover:bg-slate-50/95">
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
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </>
                )}
            </div>

            {/* Mobile bottom nav spacer */}
            <div className="h-24 md:hidden" />
        </div>
    );
}
