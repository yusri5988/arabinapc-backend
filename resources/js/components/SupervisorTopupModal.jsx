import { useEffect, useState } from 'react';
import { ArrowUpRight, Loader2, X } from 'lucide-react';
import api from '../lib/axios';
import { logAction } from '../lib/logger';

const initialForm = {
    amount: '500.00'
};

export default function SupervisorTopupModal({ isOpen, onClose, onSuccess, supervisor }) {
    const [form, setForm] = useState(initialForm);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const [confirming, setConfirming] = useState(false);

    useEffect(() => {
        if (isOpen && supervisor) {
            logAction('topup.modal_opened', 'success', {
                supervisor_id: supervisor.id,
                supervisor_name: supervisor.name,
            });
        }
        if (!isOpen) {
            setForm(initialForm);
            setLoading(false);
            setError('');
            setConfirming(false);
        }
    }, [isOpen, supervisor?.id]);

    if (!isOpen || !supervisor) return null;

    const handleClose = () => {
        logAction('topup.modal_closed', 'success', {
            supervisor_id: supervisor.id,
            supervisor_name: supervisor.name,
        });
        onClose();
    };

    const handleSubmit = (event) => {
        event.preventDefault();

        logAction('topup.submit_started', 'success', {
            supervisor_id: supervisor.id,
            supervisor_name: supervisor.name,
            amount: form.amount,
        });

        logAction('topup.confirmation_shown', 'success', {
            supervisor_id: supervisor.id,
            supervisor_name: supervisor.name,
            amount: form.amount,
        });

        setConfirming(true);
    };

    const handleCancelConfirm = () => {
        logAction('topup.confirmation_cancelled', 'success', {
            supervisor_id: supervisor.id,
            supervisor_name: supervisor.name,
            amount: form.amount,
        });
        setConfirming(false);
    };

    const handleConfirm = async () => {
        setError('');
        setLoading(true);

        logAction('topup.confirmation_confirmed', 'success', {
            supervisor_id: supervisor.id,
            supervisor_name: supervisor.name,
            amount: form.amount,
        });

        logAction('topup.api_request_sent', 'success', {
            supervisor_id: supervisor.id,
            amount: form.amount,
        });

        try {
            await api.post('/admin/topup', {
                supervisor_id: supervisor.id,
                amount: form.amount
            });

            logAction('topup.api_response_received', 'success', {
                supervisor_id: supervisor.id,
                amount: form.amount,
            });

            onSuccess();
            handleClose();
        } catch (err) {
            const apiMessage = err.response?.data?.errors
                ? Object.values(err.response.data.errors).flat()[0]
                : err.response?.data?.message;

            logAction('topup.api_response_received', 'fail', {
                supervisor_id: supervisor.id,
                amount: form.amount,
                error: apiMessage || err.message,
                status: err.response?.status,
            });

            setError(apiMessage || 'Unable to send petty cash to staff member.');
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="fixed inset-0 z-50 flex items-end md:items-center justify-center bg-black/40 backdrop-blur-sm animate-in fade-in duration-300">
            <div className="w-full md:max-w-lg md:mx-4 overflow-hidden rounded-t-3xl md:rounded-3xl border border-slate-200 bg-white shadow-2xl">
                <div className="flex items-center justify-between border-b border-slate-100 bg-slate-50 p-5 md:p-6">
                    <div className="flex items-center gap-3">
                        <div className="rounded-2xl bg-emerald-50 p-2.5 md:p-3 text-emerald-600">
                            <ArrowUpRight size={20} />
                        </div>
                        <div>
                            <h3 className="text-lg md:text-xl font-bold text-slate-900">Send Petty Cash</h3>
                            <p className="text-xs md:text-sm text-slate-500">Credit cash to staff member.</p>
                        </div>
                    </div>

                    <button onClick={handleClose} className="p-2 text-slate-400 transition-colors hover:text-slate-600" type="button">
                        <X size={22} />
                    </button>
                </div>

                {confirming ? (
                    <div className="space-y-5 p-5 md:p-6">
                        {error && (
                            <div className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-600">
                                {error}
                            </div>
                        )}

                        <div className="text-center space-y-2">
                            <div className="mx-auto w-16 h-16 rounded-2xl bg-emerald-50 flex items-center justify-center">
                                <ArrowUpRight size={28} className="text-emerald-600" />
                            </div>
                            <p className="text-lg font-bold text-slate-900">Confirm Send</p>
                            <p className="text-sm text-slate-500">Are you sure you want to send this amount?</p>
                        </div>

                        <div className="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <p className="text-xs font-bold uppercase tracking-widest text-slate-400">Staff Member</p>
                            <p className="mt-2 text-lg font-bold text-slate-900">{supervisor.name}</p>
                            <p className="text-sm text-slate-500">{supervisor.phone}</p>
                        </div>

                        <div className="rounded-2xl bg-emerald-50 border border-emerald-100 p-4 text-center">
                            <p className="text-xs font-bold uppercase tracking-widest text-emerald-600">Amount</p>
                            <p className="mt-1 text-3xl font-black text-emerald-700">RM {form.amount}</p>
                        </div>

                        <div className="flex gap-3">
                            <button
                                type="button"
                                onClick={handleCancelConfirm}
                                disabled={loading}
                                className="flex-1 rounded-2xl border-2 border-slate-200 bg-white px-4 py-4 font-bold text-slate-700 transition-all hover:bg-slate-50 disabled:opacity-50"
                            >
                                Cancel
                            </button>
                            <button
                                type="button"
                                onClick={handleConfirm}
                                disabled={loading}
                                className="flex-1 flex items-center justify-center gap-2 rounded-2xl bg-emerald-600 px-4 py-4 font-bold text-white shadow-lg shadow-emerald-600/20 transition-all hover:bg-emerald-700 active:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                {loading ? <Loader2 className="h-5 w-5 animate-spin" /> : <ArrowUpRight size={18} />}
                                {loading ? 'Saving...' : 'CONFIRM SEND'}
                            </button>
                        </div>
                    </div>
                ) : (
                    <form onSubmit={handleSubmit} className="space-y-5 p-5 md:p-6">
                        {error && (
                            <div className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-600">
                                {error}
                            </div>
                        )}

                        <div className="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <p className="text-xs font-bold uppercase tracking-widest text-slate-400">Staff Member</p>
                            <p className="mt-2 text-lg font-bold text-slate-900">{supervisor.name}</p>
                            <p className="text-sm text-slate-500">{supervisor.phone}</p>
                            <p className="mt-3 text-sm text-emerald-600 font-medium">Current balance: RM {supervisor.balance}</p>
                        </div>

                        <div>
                            <label className="mb-2 block text-xs font-bold uppercase tracking-widest text-slate-400">Amount (RM)</label>
                            <input
                                required
                                min="0.01"
                                step="0.01"
                                type="number"
                                inputMode="decimal"
                                value={form.amount}
                                onChange={(event) => setForm({ amount: event.target.value })}
                                className="w-full rounded-xl border border-slate-200 bg-white px-4 py-3.5 text-base text-slate-900 outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 transition-all"
                                placeholder="500.00"
                            />
                        </div>

                        <button
                            type="submit"
                            disabled={loading}
                            className="flex w-full items-center justify-center gap-2 rounded-2xl bg-emerald-600 px-4 py-4 font-bold text-white shadow-lg shadow-emerald-600/20 transition-all hover:bg-emerald-700 active:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            {loading ? <Loader2 className="h-5 w-5 animate-spin" /> : <ArrowUpRight size={18} />}
                            {loading ? 'Saving...' : 'SEND PETTY CASH'}
                        </button>
                    </form>
                )}
            </div>
        </div>
    );
}
