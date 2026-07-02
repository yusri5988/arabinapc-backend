import { useCallback, useEffect, useRef, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import api from '../../lib/axios';
import { normalizeSupervisors } from '../../lib/normalize';
import { Wallet, ArrowUpRight, Send, UserPlus, History, KeyRound, Loader2, FileDown, RotateCcw, X } from 'lucide-react';
import CreateSupervisorModal from '../../components/CreateSupervisorModal';
import SupervisorTopupModal from '../../components/SupervisorTopupModal';
import { Link } from 'react-router-dom';

export default function AdminSupervisors() {
    const [selectedSupervisor, setSelectedSupervisor] = useState(null);
    const [isCreateOpen, setIsCreateOpen] = useState(false);
    const [resettingSupervisorId, setResettingSupervisorId] = useState(null);
    const [resetFeedback, setResetFeedback] = useState(null);
    const [receivingBackSupervisorId, setReceivingBackSupervisorId] = useState(null);
    const [receiveBackSupervisor, setReceiveBackSupervisor] = useState(null);
    const [receiveBackFeedback, setReceiveBackFeedback] = useState(null);
    const [exportingSupervisorId, setExportingSupervisorId] = useState(null);
    const exportPollRef = useRef(null);
    const exportTimeoutRef = useRef(null);
    const receiveBackCancelRef = useRef(null);

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

    useEffect(() => {
        return () => {
            stopExportPolling();
        };
    }, [stopExportPolling]);

    useEffect(() => {
        if (!receiveBackSupervisor) return;

        receiveBackCancelRef.current?.focus();

        const handleKeyDown = (event) => {
            if (event.key === 'Escape' && !receivingBackSupervisorId) {
                setReceiveBackSupervisor(null);
            }
        };

        window.addEventListener('keydown', handleKeyDown);

        return () => window.removeEventListener('keydown', handleKeyDown);
    }, [receiveBackSupervisor, receivingBackSupervisorId]);

    const { data, isLoading, isError, error, refetch } = useQuery({
        queryKey: ['adminSupervisors'],
        queryFn: async () => {
            const res = await api.get('/admin/supervisors', {
                // Cache-buster untuk elak browser/HTTP cache
                params: { _t: Date.now() },
            });
            return res.data;
        },
        retry: false,
    });

    const handleResetStaffPassword = async (supervisor) => {
        const confirmed = window.confirm(`Reset password for ${supervisor.name} to 123456?`);
        if (!confirmed) return;

        setResettingSupervisorId(supervisor.id);
        setResetFeedback(null);

        try {
            const res = await api.post(`/admin/supervisors/${supervisor.id}/reset-password`);
            setResetFeedback({
                type: 'success',
                message: `${res.data.supervisor?.name || supervisor.name} password reset to 123456.`
            });
        } catch (err) {
            setResetFeedback({
                type: 'error',
                message: err.response?.data?.message || `Unable to reset password for ${supervisor.name}.`
            });
        } finally {
            setResettingSupervisorId(null);
        }
    };

    const handleOpenReceiveBackConfirm = (supervisor) => {
        const balance = Number(supervisor.balance ?? 0);

        if (balance <= 0) {
            setReceiveBackFeedback({
                type: 'error',
                message: `${supervisor.name} does not have any petty cash balance to receive back.`
            });
            return;
        }

        setReceiveBackFeedback(null);
        setReceiveBackSupervisor(supervisor);
    };

    const handleReceiveBack = async () => {
        if (!receiveBackSupervisor) return;

        const supervisor = receiveBackSupervisor;
        const balance = Number(supervisor.balance ?? 0);

        setReceivingBackSupervisorId(supervisor.id);
        setReceiveBackFeedback(null);

        try {
            const res = await api.post(`/admin/supervisors/${supervisor.id}/receive-back`);
            setReceiveBackFeedback({
                type: 'success',
                message: `Received back RM ${Number(res.data.amount ?? balance).toFixed(2)} from ${supervisor.name}.`
            });
            setReceiveBackSupervisor(null);
            refetch();
        } catch (err) {
            setReceiveBackFeedback({
                type: 'error',
                message: err.response?.data?.message || `Unable to receive back petty cash from ${supervisor.name}.`
            });
            setReceiveBackSupervisor(null);
        } finally {
            setReceivingBackSupervisorId(null);
        }
    };

    const canReceiveBack = (supervisor) => Number(supervisor.balance ?? 0) > 0;

    const handleExportExcel = async (supervisor) => {
        stopExportPolling();
        setExportingSupervisorId(supervisor.id);

        try {
            const res = await api.post(`/admin/supervisors/${supervisor.id}/export-excel`);
            const { job_id } = res.data;

            exportPollRef.current = setInterval(async () => {
                try {
                    const statusRes = await api.get(`/admin/export-status/${job_id}`);
                    const { status, error } = statusRes.data;

                    if (status === 'completed') {
                        stopExportPolling();
                        setExportingSupervisorId(null);

                        try {
                            const downloadRes = await api.get(`/admin/export-download/${job_id}`, {
                                responseType: 'blob',
                            });

                            const blob = new Blob([downloadRes.data], {
                                type: downloadRes.headers['content-type'] || 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            });
                            const url = window.URL.createObjectURL(blob);
                            const link = document.createElement('a');
                            link.href = url;
                            link.download = `petty-cash-${supervisor.name}.xlsx`;
                            document.body.appendChild(link);
                            link.click();
                            link.remove();
                            window.URL.revokeObjectURL(url);
                        } catch (dlErr) {
                            alert(dlErr.response?.data?.message || `Unable to download Excel for ${supervisor.name}.`);
                        }
                    } else if (status === 'failed') {
                        stopExportPolling();
                        setExportingSupervisorId(null);
                        alert(error || `Unable to export Excel for ${supervisor.name}.`);
                    }
                } catch (pollErr) {
                    if (pollErr.response?.status === 404) {
                        stopExportPolling();
                        setExportingSupervisorId(null);
                        alert(`Export status not found for ${supervisor.name}.`);
                    }
                }
            }, 3000);

            exportTimeoutRef.current = setTimeout(() => {
                stopExportPolling();
                setExportingSupervisorId(null);
                alert('Export taking too long. Please try again later.');
            }, 120000);

        } catch (err) {
            setExportingSupervisorId(null);
            alert(err.response?.data?.message || `Unable to export Excel for ${supervisor.name}.`);
        }
    };

    if (isLoading) return <div className="flex items-center justify-center h-40 text-emerald-600 animate-pulse font-bold">Loading staff data...</div>;

    if (isError) {
        const status = error?.response?.status;
        const message = error?.response?.data?.message || error?.message || 'Failed to load staff data.';

        return (
            <div className="rounded-2xl border border-red-100 bg-red-50 px-5 py-4 text-red-600">
                <p className="font-bold">Unable to load staff data{status ? ` (${status})` : ''}</p>
                <p className="mt-1 text-sm font-semibold">{message}</p>
                <button
                    type="button"
                    onClick={() => refetch()}
                    className="mt-3 rounded-xl bg-white px-4 py-2 text-sm font-bold text-red-600 shadow-sm border border-red-100"
                >
                    Try again
                </button>
            </div>
        );
    }

    const supervisors = normalizeSupervisors(data);
    const receiveBackBalance = receiveBackSupervisor ? Number(receiveBackSupervisor.balance ?? 0) : 0;
    const receiveBackLoading = receiveBackSupervisor && receivingBackSupervisorId === receiveBackSupervisor.id;

    return (
        <div className="space-y-6">
            <CreateSupervisorModal
                isOpen={isCreateOpen}
                onClose={() => setIsCreateOpen(false)}
                onSuccess={refetch}
            />
            <SupervisorTopupModal
                isOpen={selectedSupervisor !== null}
                supervisor={selectedSupervisor}
                onClose={() => setSelectedSupervisor(null)}
                onSuccess={refetch}
            />

            {receiveBackSupervisor && (
                <div className="fixed inset-0 z-50 flex items-end justify-center bg-black/40 backdrop-blur-sm animate-in fade-in duration-300 md:items-center">
                    <div
                        role="alertdialog"
                        aria-modal="true"
                        aria-labelledby="receive-back-title"
                        aria-describedby="receive-back-description"
                        className="w-full overflow-hidden rounded-t-3xl border border-slate-200 bg-white shadow-2xl md:mx-4 md:max-w-lg md:rounded-3xl"
                    >
                        <div className="flex items-center justify-between border-b border-slate-100 bg-slate-50 p-5 md:p-6">
                            <div className="flex items-center gap-3">
                                <div className="rounded-2xl bg-emerald-50 p-2.5 text-emerald-600 md:p-3">
                                    <RotateCcw size={20} />
                                </div>
                                <div>
                                    <h3 id="receive-back-title" className="text-lg font-bold text-slate-900 md:text-xl">Receive Back Petty Cash</h3>
                                    <p className="text-xs text-slate-500 md:text-sm">Return cash from staff member.</p>
                                </div>
                            </div>

                            <button
                                type="button"
                                aria-label="Close receive back confirmation"
                                onClick={() => setReceiveBackSupervisor(null)}
                                disabled={receiveBackLoading}
                                className="p-2 text-slate-400 transition-colors hover:text-slate-600 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                <X size={22} />
                            </button>
                        </div>

                        <div className="space-y-5 p-5 md:p-6">
                            <div className="text-center space-y-2">
                                <div className="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-emerald-50">
                                    <RotateCcw size={28} className="text-emerald-600" />
                                </div>
                                <p className="text-lg font-bold text-slate-900">Confirm Receive Back</p>
                                <p id="receive-back-description" className="text-sm text-slate-500">
                                    Are you sure you want to receive back this amount?
                                </p>
                            </div>

                            <div className="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                <p className="text-xs font-bold uppercase tracking-widest text-slate-400">Staff Member</p>
                                <p className="mt-2 text-lg font-bold text-slate-900">{receiveBackSupervisor.name}</p>
                                {receiveBackSupervisor.department && (
                                    <span className="inline-block mt-1 rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-slate-500">{receiveBackSupervisor.department}</span>
                                )}
                                <p className="text-sm text-slate-500">{receiveBackSupervisor.phone}</p>
                            </div>

                            <div className="rounded-2xl border border-emerald-100 bg-emerald-50 p-4 text-center">
                                <p className="text-xs font-bold uppercase tracking-widest text-emerald-600">Amount</p>
                                <p className="mt-1 text-3xl font-black text-emerald-700">RM {receiveBackBalance.toFixed(2)}</p>
                            </div>

                            <div className="flex gap-3">
                                <button
                                    type="button"
                                    ref={receiveBackCancelRef}
                                    onClick={() => setReceiveBackSupervisor(null)}
                                    disabled={receiveBackLoading}
                                    className="flex-1 rounded-2xl border-2 border-slate-200 bg-white px-4 py-4 font-bold text-slate-700 transition-all hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="button"
                                    onClick={handleReceiveBack}
                                    disabled={receiveBackLoading}
                                    className="flex flex-1 items-center justify-center gap-2 rounded-2xl bg-emerald-600 px-4 py-4 font-bold text-white shadow-lg shadow-emerald-600/20 transition-all hover:bg-emerald-700 active:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    {receiveBackLoading ? <Loader2 className="h-5 w-5 animate-spin" /> : <RotateCcw size={18} />}
                                    {receiveBackLoading ? 'Processing...' : 'CONFIRM RECEIVE BACK'}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            )}

            <div className="flex items-center justify-between">
                <div>
                    <h2 className="text-2xl font-black text-slate-900 tracking-tight">Staff</h2>
                    <p className="text-slate-500 text-sm font-medium mt-0.5">Manage staff accounts</p>
                </div>
            </div>

            <button
                type="button"
                onClick={() => setIsCreateOpen(true)}
                className="w-full relative group overflow-hidden flex items-center justify-center gap-2 px-5 py-4 bg-emerald-600 hover:bg-emerald-700 active:bg-emerald-800 text-white rounded-2xl transition-all text-[15px] font-bold shadow-lg shadow-emerald-600/20"
            >
                <div className="absolute inset-0 w-full h-full bg-gradient-to-r from-transparent via-white/20 to-transparent -translate-x-full group-hover:animate-[shimmer_1.5s_infinite]"></div>
                <UserPlus size={18} strokeWidth={2.5} />
                <span className="tracking-wide">Add Staff Member</span>
            </button>

            {resetFeedback && (
                <div className={`rounded-2xl border px-4 py-3 text-sm font-semibold ${
                    resetFeedback.type === 'success'
                        ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                        : 'border-red-200 bg-red-50 text-red-600'
                }`}>
                    {resetFeedback.message}
                </div>
            )}

            {receiveBackFeedback && (
                <div className={`rounded-2xl border px-4 py-3 text-sm font-semibold ${
                    receiveBackFeedback.type === 'success'
                        ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                        : 'border-red-200 bg-red-50 text-red-600'
                }`}>
                    {receiveBackFeedback.message}
                </div>
            )}

            {supervisors.length === 0 && (
                <div className="rounded-2xl border border-amber-100 bg-amber-50 px-5 py-4 text-amber-700">
                    <p className="font-bold">No staff records found.</p>
                    <p className="mt-1 text-sm font-semibold">
                        API response received, but no staff array was found. Response keys: {data && typeof data === 'object' ? Object.keys(data).join(', ') : 'none'}
                    </p>
                </div>
            )}

            {/* Mobile Card List */}
            <div className="md:hidden space-y-3">
                {supervisors.map((sv) => (
                    <div key={sv.id} className="bg-white border border-slate-200/60 p-5 rounded-[1.5rem] space-y-4 shadow-sm hover:shadow-md transition-shadow">
                        <div className="flex items-start justify-between gap-3">
                            <div className="flex items-center gap-4 min-w-0">
                                <div className="w-12 h-12 rounded-2xl bg-gradient-to-br from-emerald-100 to-teal-50 border border-emerald-100 flex items-center justify-center text-emerald-600 font-bold shadow-sm text-lg shrink-0">
                                    {sv.name[0]}
                                </div>
                                <div className="flex-1 min-w-0">
                                    <p className="text-slate-900 font-bold text-[16px] truncate leading-tight">{sv.name}</p>
                                    <p className="text-slate-400 text-[12px] font-medium truncate mt-0.5">{sv.phone}</p>
                                    {sv.department && (
                                        <span className="inline-block mt-1 text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-full bg-slate-100 text-slate-500">{sv.department}</span>
                                    )}
                                </div>
                            </div>

                            <button
                                type="button"
                                onClick={() => handleExportExcel(sv)}
                                disabled={exportingSupervisorId === sv.id}
                                className="inline-flex items-center gap-1.5 rounded-xl border border-emerald-700 bg-emerald-600 px-3 py-2 text-[12px] font-bold text-white shadow-sm transition-colors hover:bg-emerald-700 active:scale-95 shrink-0 disabled:opacity-60 disabled:cursor-not-allowed"
                            >
                                {exportingSupervisorId === sv.id ? <Loader2 size={14} className="animate-spin" /> : <FileDown size={14} strokeWidth={2.5} />}
                                Export
                            </button>
                        </div>
                        <div className="space-y-3 pt-4 border-t border-slate-100/80">
                            <div className="inline-flex items-center gap-2 bg-emerald-50/50 px-3 py-1.5 rounded-lg border border-emerald-100/50">
                                <Wallet size={14} className="text-emerald-600" strokeWidth={2.5} />
                                <span className="text-emerald-700 font-black tracking-tight">RM {sv.balance}</span>
                            </div>
                            <div className="grid grid-cols-2 gap-2">
                                <Link
                                    to={`/admin/supervisors/${sv.id}/transactions`}
                                    className="min-w-0 flex items-center justify-center gap-1.5 text-slate-700 hover:text-emerald-600 hover:bg-emerald-50 text-[12px] font-bold px-2 py-2.5 rounded-xl border border-slate-200/60 active:scale-95 transition-all"
                                >
                                    <History size={14} strokeWidth={2.5} />
                                    History
                                </Link>
                                <button
                                    type="button"
                                    onClick={() => setSelectedSupervisor(sv)}
                                    className="min-w-0 flex items-center justify-center gap-1.5 text-slate-700 hover:text-emerald-600 hover:bg-emerald-50 text-[12px] font-bold px-2 py-2.5 rounded-xl border border-slate-200/60 active:scale-95 transition-all"
                                >
                                    <Send size={14} strokeWidth={2.5} />
                                    Send
                                </button>
                                <button
                                    type="button"
                                    onClick={() => handleResetStaffPassword(sv)}
                                    disabled={resettingSupervisorId === sv.id}
                                    className="min-w-0 flex items-center justify-center gap-1.5 text-amber-700 hover:bg-amber-50 text-[12px] font-bold px-2 py-2.5 rounded-xl border border-amber-200/70 active:scale-95 transition-all disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    {resettingSupervisorId === sv.id ? <Loader2 size={14} className="animate-spin" /> : <KeyRound size={14} strokeWidth={2.5} />}
                                    Reset
                                </button>
                                <button
                                    type="button"
                                    onClick={() => handleOpenReceiveBackConfirm(sv)}
                                    disabled={!canReceiveBack(sv) || receivingBackSupervisorId === sv.id}
                                    className="min-w-0 flex items-center justify-center gap-1.5 text-rose-700 hover:bg-rose-50 text-[12px] font-bold px-2 py-2.5 rounded-xl border border-rose-200/70 active:scale-95 transition-all disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    {receivingBackSupervisorId === sv.id ? <Loader2 size={14} className="animate-spin" /> : <RotateCcw size={14} strokeWidth={2.5} />}
                                    Receive Back
                                </button>
                            </div>
                        </div>
                    </div>
                ))}
            </div>

            {/* Desktop Table */}
            <div className="hidden md:block bg-white border border-slate-200/60 rounded-[2rem] overflow-hidden shadow-sm">
                <div className="overflow-x-auto">
                    <table className="w-full text-left">
                        <thead className="bg-slate-50/50 text-slate-400 text-xs uppercase tracking-widest font-bold border-b border-slate-100">
                            <tr>
                                <th className="px-6 py-5">Staff Member</th>
                                <th className="px-6 py-5">Department</th>
                                <th className="px-6 py-5">Phone Number</th>
                                <th className="px-6 py-5 text-right">Balance (RM)</th>
                                <th className="px-6 py-5 text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100/80">
                            {supervisors.map((sv) => (
                                <tr key={sv.id} className="hover:bg-slate-50/50 transition-colors">
                                    <td className="px-6 py-4">
                                        <div className="flex items-center gap-3">
                                            <div className="w-10 h-10 rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center font-bold">
                                                {sv.name[0]}
                                            </div>
                                            <span className="text-slate-900 font-bold">{sv.name}</span>
                                        </div>
                                    </td>
                                    <td className="px-6 py-4">
                                        <span className="inline-block text-xs font-bold uppercase tracking-wider px-2.5 py-1 rounded-full bg-slate-100 text-slate-500">{sv.department || 'Site'}</span>
                                    </td>
                                    <td className="px-6 py-4 text-slate-500 font-medium">{sv.phone}</td>
                                    <td className="px-6 py-4 text-right text-[15px] font-black text-slate-900">{sv.balance}</td>
                                    <td className="px-6 py-4 text-center">
                                        <div className="inline-flex items-center gap-2">
                                            <Link
                                                to={`/admin/supervisors/${sv.id}/transactions`}
                                                className="inline-flex items-center gap-2 text-slate-700 hover:text-emerald-700 bg-slate-50 px-4 py-2 rounded-xl font-bold transition-colors border border-slate-200/60"
                                            >
                                                <History size={16} strokeWidth={2.5} />
                                                History
                                            </Link>
                                            <button
                                                type="button"
                                                onClick={() => setSelectedSupervisor(sv)}
                                                className="inline-flex items-center gap-2 text-emerald-600 hover:text-emerald-700 bg-emerald-50 px-4 py-2 rounded-xl font-bold transition-colors"
                                            >
                                                Send <ArrowUpRight size={16} strokeWidth={2.5} />
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => handleOpenReceiveBackConfirm(sv)}
                                                disabled={!canReceiveBack(sv) || receivingBackSupervisorId === sv.id}
                                                className="inline-flex items-center gap-2 text-rose-700 hover:text-rose-800 bg-rose-50 px-4 py-2 rounded-xl font-bold transition-colors border border-rose-100 disabled:cursor-not-allowed disabled:opacity-50"
                                            >
                                                {receivingBackSupervisorId === sv.id ? <Loader2 size={16} className="animate-spin" /> : <RotateCcw size={16} strokeWidth={2.5} />}
                                                Receive Back
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => handleResetStaffPassword(sv)}
                                                disabled={resettingSupervisorId === sv.id}
                                                className="inline-flex items-center gap-2 text-amber-700 hover:text-amber-800 bg-amber-50 px-4 py-2 rounded-xl font-bold transition-colors border border-amber-100 disabled:cursor-not-allowed disabled:opacity-60"
                                            >
                                                {resettingSupervisorId === sv.id ? <Loader2 size={16} className="animate-spin" /> : <KeyRound size={16} strokeWidth={2.5} />}
                                                Reset
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => handleExportExcel(sv)}
                                                disabled={exportingSupervisorId === sv.id}
                                                className="inline-flex items-center gap-2 text-sky-700 hover:text-sky-800 bg-sky-50 px-4 py-2 rounded-xl font-bold transition-colors border border-sky-100 disabled:opacity-60 disabled:cursor-not-allowed"
                                            >
                                                {exportingSupervisorId === sv.id ? <Loader2 size={16} className="animate-spin" /> : <FileDown size={16} strokeWidth={2.5} />}
                                                Export
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            {/* Mobile bottom nav spacer */}
            <div className="h-24 md:hidden" />
        </div>
    );
}
