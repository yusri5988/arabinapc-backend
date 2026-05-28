import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import api from '../../lib/axios';
import { ArrowDownLeft, ArrowUpRight, History, Camera, ReceiptText, RefreshCw, FileText } from 'lucide-react';
import ExpenseModal from '../../components/ExpenseModal';

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

export default function SupervisorLedger() {
    const [isModalOpen, setIsModalOpen] = useState(false);
    const { data, isLoading, isError, error, refetch, isFetching } = useQuery({
        queryKey: ['supervisorLedger'],
        queryFn: async () => {
            const res = await api.get('/supervisor/ledger');
            return res.data;
        },
        retry: 1,
    });

    const transactions = data?.transactions ?? [];

    return (
        <div className="space-y-6">
            <div className="flex justify-between items-end">
                <div>
                    <h2 className="text-2xl md:text-3xl font-black text-slate-900 tracking-tight">Ledger</h2>
                    <p className="text-slate-500 text-sm font-medium mt-0.5">Manage receipts and expenses</p>
                </div>
            </div>

            <ExpenseModal 
                isOpen={isModalOpen} 
                onClose={() => setIsModalOpen(false)} 
                onRefresh={refetch}
                maxAmount={data?.balance}
            />

            <div className="rounded-[2rem] border border-slate-200/60 bg-white p-6 shadow-sm relative overflow-hidden">
                <div className="absolute top-0 right-0 w-32 h-32 bg-emerald-50 rounded-full blur-3xl -z-0"></div>
                <div className="relative z-10 flex items-start gap-4 mb-5">
                    <div className="w-12 h-12 rounded-2xl bg-gradient-to-br from-emerald-100 to-teal-50 border border-emerald-100 flex items-center justify-center text-emerald-600 shadow-sm shrink-0">
                        <Camera size={22} strokeWidth={2.5} />
                    </div>
                    <div>
                        <h3 className="text-lg font-bold text-slate-900">Upload Receipt Image</h3>
                        <p className="text-xs text-slate-500 font-medium mt-1 leading-relaxed">
                            Take a photo of your receipt. The system will auto-fill the basic details.
                        </p>
                    </div>
                </div>

                <div className="relative z-10 space-y-4">
                    <button
                        type="button"
                        onClick={() => setIsModalOpen(true)}
                        className="flex w-full items-center justify-center gap-2 rounded-2xl bg-slate-900 px-4 py-3.5 font-bold text-white shadow-lg shadow-slate-900/20 transition-all hover:bg-slate-800 active:scale-[0.98]"
                    >
                        <ReceiptText size={18} />
                        Upload Receipt Now
                    </button>
                </div>
            </div>

            {/* Transactions History */}
            <div className="bg-white border border-slate-200/60 rounded-[2rem] overflow-hidden shadow-sm">
                <div className="p-5 md:p-6 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
                    <div className="flex items-center gap-3">
                        <div className="p-2 bg-white rounded-xl shadow-sm border border-slate-200/50">
                            <History className="text-slate-700" size={18} strokeWidth={2.5} />
                        </div>
                        <h3 className="text-base font-bold text-slate-900">Recent Activity</h3>
                    </div>

                    <button
                        type="button"
                        onClick={() => refetch()}
                        className="w-9 h-9 flex items-center justify-center rounded-xl bg-white border border-slate-200/50 text-slate-500 transition-all hover:border-slate-300 hover:text-emerald-600 shadow-sm active:scale-95"
                    >
                        <RefreshCw size={16} strokeWidth={2.5} className={isFetching ? 'animate-spin text-emerald-600' : ''} />
                    </button>
                </div>
                
                <div className="divide-y divide-slate-100/80">
                    {isLoading ? (
                        <div className="p-12 text-center text-emerald-600 font-bold animate-pulse">
                            Loading history...
                        </div>
                    ) : isError ? (
                        <div className="p-12 text-center">
                            <p className="text-red-500 font-bold">Failed to load ledger.</p>
                            <p className="mt-1 text-xs font-medium text-slate-400">{error?.response?.data?.message || error?.message || 'Please try refreshing.'}</p>
                        </div>
                    ) : transactions.length === 0 ? (
                        <div className="p-12 text-center text-slate-400">
                            <div className="w-16 h-16 bg-slate-50 rounded-full flex items-center justify-center mx-auto mb-3">
                                <FileText size={24} className="text-slate-300" />
                            </div>
                            <p className="font-bold text-slate-700">No transactions yet</p>
                            <p className="mt-1 text-xs font-medium">Upload your first receipt to get started.</p>
                        </div>
                    ) : (
                        transactions.map((tx) => (
                            <div key={tx.id} className="p-4 md:p-5 hover:bg-slate-50/80 transition-colors flex items-center justify-between gap-3 active:bg-slate-100">
                                <div className="flex items-center gap-3 md:gap-4 min-w-0">
                                    <div className={`w-11 h-11 flex items-center justify-center rounded-2xl shrink-0 shadow-sm border ${
                                        tx.type === 'topup' 
                                            ? 'bg-emerald-50 border-emerald-100 text-emerald-600' 
                                            : 'bg-white border-slate-200 text-slate-700'
                                    }`}>
                                        {tx.type === 'topup' ? <ArrowDownLeft size={20} strokeWidth={2.5} /> : <ArrowUpRight size={20} strokeWidth={2.5} />}
                                    </div>
                                    <div className="min-w-0">
                                        <p className="text-slate-900 font-bold text-[15px] truncate leading-tight">{tx.description}</p>
                                        <div className="flex items-center gap-2 text-[11px] font-semibold text-slate-400 mt-1 flex-wrap">
                                            <span>{new Date(tx.date).toLocaleDateString('en-MY', { day: 'numeric', month: 'short', year: 'numeric' })}</span>
                                            {tx.payment_to && (
                                                <>
                                                    <span className="w-1 h-1 rounded-full bg-slate-300"></span>
                                                    <span className="text-slate-500">To {tx.payment_to}</span>
                                                </>
                                            )}
                                            {tx.details && (
                                                <>
                                                    <span className="w-1 h-1 rounded-full bg-slate-300"></span>
                                                    <span className="text-slate-500">{tx.details}</span>
                                                </>
                                            )}
                                            {tx.site_id && (
                                                <>
                                                    <span className="w-1 h-1 rounded-full bg-slate-300"></span>
                                                    <span className="text-slate-500">{tx.site_id}</span>
                                                </>
                                            )}
                                            {Array.isArray(tx.metadata?.item_images) && tx.metadata.item_images.length > 0 && (
                                                <>
                                                    <span className="w-1 h-1 rounded-full bg-slate-300"></span>
                                                    <a
                                                        href={normalizeUrl(tx.metadata.item_images[0]?.url)}
                                                        onClick={(event) => {
                                                            event.preventDefault();
                                                            const url = normalizeUrl(tx.metadata.item_images[0]?.url);
                                                            if (url) window.location.assign(url);
                                                        }}
                                                        className="inline-flex rounded-md px-1.5 py-0.5 text-slate-600 underline decoration-slate-200 underline-offset-2 hover:bg-slate-50 hover:text-slate-800"
                                                    >
                                                        Item Photos ({tx.metadata.item_images.length})
                                                    </a>
                                                </>
                                            )}
                                            {tx.receipt_url && (
                                                <>
                                                    <span className="w-1 h-1 rounded-full bg-slate-300"></span>
                                                    <a
                                                        href={normalizeUrl(tx.receipt_url)}
                                                        onClick={(event) => {
                                                            event.preventDefault();
                                                            const url = normalizeUrl(tx.receipt_url);
                                                            if (url) window.location.assign(url);
                                                        }}
                                                        className="inline-flex rounded-md px-1.5 py-0.5 text-emerald-600 underline decoration-emerald-200 underline-offset-2 hover:bg-emerald-50 hover:text-emerald-700"
                                                    >
                                                        Receipt
                                                    </a>
                                                </>
                                            )}
                                        </div>
                                    </div>
                                </div>
                                <div className="text-right shrink-0">
                                    <p className={`text-[16px] font-black tracking-tight ${
                                        tx.type === 'topup' ? 'text-emerald-600' : 'text-slate-900'
                                    }`}>
                                        {tx.type === 'topup' ? '+' : '-'}RM {tx.amount}
                                    </p>
                                    <p className="text-[9px] text-slate-400 font-bold tracking-widest mt-0.5">
                                        #{tx.id.toString().padStart(4, '0')}
                                    </p>
                                </div>
                            </div>
                        ))
                    )}
                </div>
            </div>

            {/* Mobile bottom nav spacer */}
            <div className="h-24 md:hidden" />
        </div>
    );
}
