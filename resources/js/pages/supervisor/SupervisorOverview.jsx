import { useQuery } from '@tanstack/react-query';
import api from '../../lib/axios';
import { Wallet, ArrowDownLeft, ArrowUpRight, ReceiptText, ChevronRight } from 'lucide-react';
import { Link } from 'react-router-dom';

export default function SupervisorOverview() {
    const { data, isLoading } = useQuery({
        queryKey: ['supervisorLedger'],
        queryFn: async () => {
            const res = await api.get('/supervisor/ledger');
            return res.data;
        },
    });

    const transactions = data?.transactions ?? [];
    const balance = data?.balance ?? '0.00';
    const cashIn = transactions
        .filter((t) => t.type === 'topup')
        .reduce((total, t) => total + Number(t.amount), 0);
    const cashOut = transactions
        .filter((t) => t.type === 'expense')
        .reduce((total, t) => total + Number(t.amount), 0);
    const receiptCount = transactions.filter((t) => Boolean(t.receipt_url)).length;

    if (isLoading) return <div className="flex items-center justify-center h-40 text-emerald-600 animate-pulse font-bold">Loading dashboard...</div>;

    return (
        <div className="space-y-6">
            <div className="flex items-center justify-between">
                <div>
                    <h2 className="text-2xl font-black text-slate-900 tracking-tight">Overview</h2>
                    <p className="text-slate-500 text-sm font-medium mt-0.5">Your cash balance and activities</p>
                </div>
            </div>

            {/* Wallet Card */}
            <div className="bg-gradient-to-br from-emerald-600 via-emerald-500 to-teal-500 p-6 md:p-8 rounded-[2rem] shadow-2xl shadow-emerald-600/20 relative overflow-hidden text-white">
                <div className="absolute -top-24 -right-24 w-64 h-64 bg-white/10 blur-3xl rounded-full"></div>
                <div className="absolute -bottom-24 -left-24 w-64 h-64 bg-teal-400/20 blur-3xl rounded-full"></div>
                
                <div className="relative z-10 flex flex-col items-center text-center">
                    <div className="p-3 bg-white/10 backdrop-blur-md rounded-2xl mb-4 border border-white/20">
                        <Wallet size={24} className="text-white drop-shadow-md" />
                    </div>
                    <p className="text-emerald-50 font-semibold tracking-wider text-xs uppercase mb-1 drop-shadow-sm">Current Balance</p>
                    <p className="text-[42px] md:text-5xl font-black tracking-tight drop-shadow-lg leading-none">
                        <span className="text-2xl font-bold align-top mr-1">RM</span>{balance}
                    </p>
                </div>
                
                <div className="relative z-10 mt-8 grid grid-cols-3 gap-3">
                    <div className="rounded-2xl border border-white/20 bg-white/10 px-3 py-3.5 backdrop-blur-md flex flex-col items-center justify-center shadow-inner">
                        <div className="flex items-center gap-1 text-emerald-100 mb-1">
                            <ArrowDownLeft size={12} strokeWidth={3} />
                            <p className="text-[10px] font-bold uppercase tracking-wider">In</p>
                        </div>
                        <p className="text-base font-bold text-white leading-none"><span className="text-[10px] font-semibold mr-0.5">RM</span>{cashIn.toFixed(0)}</p>
                    </div>
                    <div className="rounded-2xl border border-white/20 bg-white/10 px-3 py-3.5 backdrop-blur-md flex flex-col items-center justify-center shadow-inner">
                        <div className="flex items-center gap-1 text-rose-200 mb-1">
                            <ArrowUpRight size={12} strokeWidth={3} />
                            <p className="text-[10px] font-bold uppercase tracking-wider text-rose-100">Out</p>
                        </div>
                        <p className="text-base font-bold text-white leading-none"><span className="text-[10px] font-semibold mr-0.5">RM</span>{cashOut.toFixed(0)}</p>
                    </div>
                    <div className="rounded-2xl border border-white/20 bg-white/10 px-3 py-3.5 backdrop-blur-md flex flex-col items-center justify-center shadow-inner">
                        <div className="flex items-center gap-1 text-emerald-100 mb-1">
                            <ReceiptText size={12} strokeWidth={3} />
                            <p className="text-[10px] font-bold uppercase tracking-wider">Docs</p>
                        </div>
                        <p className="text-base font-bold text-white leading-none">{receiptCount}</p>
                    </div>
                </div>
            </div>

            {/* Quick Action */}
            <Link to="/supervisor/ledger" className="relative overflow-hidden block rounded-[2rem] border border-slate-200/60 bg-white p-5 md:p-6 hover:border-emerald-300 hover:shadow-xl hover:shadow-emerald-500/5 transition-all group active:scale-[0.98]">
                <div className="absolute inset-0 bg-gradient-to-r from-emerald-50 to-transparent opacity-0 group-hover:opacity-100 transition-opacity"></div>
                <div className="relative flex items-center justify-between gap-4">
                    <div className="flex items-center gap-4">
                        <div className="w-14 h-14 rounded-2xl bg-gradient-to-br from-emerald-50 to-teal-50 border border-emerald-100 flex items-center justify-center text-emerald-600 shadow-sm group-hover:scale-110 transition-transform">
                            <ReceiptText size={24} strokeWidth={2.5} />
                        </div>
                        <div>
                            <h3 className="text-[17px] font-bold text-slate-900 group-hover:text-emerald-700 transition-colors">Record Expense</h3>
                            <p className="text-xs text-slate-500 font-medium mt-0.5">
                                Upload a new receipt image
                            </p>
                        </div>
                    </div>
                    <div className="w-8 h-8 rounded-full bg-slate-50 flex items-center justify-center group-hover:bg-emerald-100 text-slate-400 group-hover:text-emerald-600 transition-colors">
                        <ChevronRight size={18} strokeWidth={3} />
                    </div>
                </div>
            </Link>
        </div>
    );
}
