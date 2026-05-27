import { useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { Loader2, X } from 'lucide-react';
import api from '../lib/axios';
import { normalizeSupervisors } from '../lib/normalize';

const DETAILS_OPTIONS = [
    'Site Meal',
    'Upkeep Motor Vehicle',
    'Upkeep Hostel',
    'Upkeep Office',
    'Maintenance Motor Vehicle',
    'Stationary & Printing',
    'Hardware',
    'Fuel',
    'Travel Expenses',
    'Logistic to Site',
    'Uniform',
    'Tools & Equipment',
    'Welfare',
    'TNG',
    'Advertising',
    'Others',
];

const today = () => new Date().toISOString().split('T')[0];

const getErrorMessage = (error) => {
    const data = error?.response?.data;

    if (data?.errors) {
        const first = Object.values(data.errors)?.[0]?.[0];
        if (first) return first;
    }

    return data?.message || error?.message || 'Something went wrong.';
};

export default function AdminTransactionModal({ isOpen, onClose, onSaved, transaction }) {
    const isEdit = Boolean(transaction?.id);
    const [loading, setLoading] = useState(false);
    const [form, setForm] = useState({
        supervisor_id: '',
        type: 'expense',
        amount: '',
        payment_to: '',
        details: '',
        description: '',
        site_id: '',
        date: today(),
    });

    const { data: supervisorsData, isLoading: supervisorsLoading } = useQuery({
        queryKey: ['adminSupervisorsForTransactionModal'],
        queryFn: async () => {
            const res = await api.get('/admin/supervisors', {
                params: { _t: Date.now() },
                headers: { 'Cache-Control': 'no-cache' },
            });
            return res.data;
        },
        enabled: isOpen,
        retry: 1,
    });

    const supervisors = normalizeSupervisors(supervisorsData);

    useEffect(() => {
        if (!isOpen) return;

        if (isEdit) {
            setForm({
                supervisor_id: transaction.user?.id || '',
                type: transaction.type || 'expense',
                amount: transaction.amount || '',
                payment_to: transaction.payment_to || '',
                details: transaction.details || '',
                description: transaction.description || '',
                site_id: transaction.site_id || '',
                date: transaction.date || today(),
            });
        } else {
            setForm({
                supervisor_id: '',
                type: 'expense',
                amount: '',
                payment_to: '',
                details: '',
                description: '',
                site_id: '',
                date: today(),
            });
        }
    }, [isOpen, isEdit, transaction]);

    if (!isOpen) return null;

    const isExpense = form.type === 'expense';

    const handleSubmit = async (event) => {
        event.preventDefault();
        setLoading(true);

        try {
            if (isEdit) {
                await api.put(`/admin/transactions/${transaction.id}`, {
                    amount: form.amount,
                    payment_to: form.payment_to,
                    details: form.details,
                    description: form.description,
                    site_id: form.site_id,
                    date: form.date,
                });
                toast.success('Transaction updated.');
            } else {
                await api.post('/admin/transactions', form);
                toast.success('Transaction created.');
            }

            onSaved?.();
            onClose();
        } catch (error) {
            toast.error(getErrorMessage(error));
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="fixed inset-0 z-50 flex items-end md:items-center justify-center bg-black/40 backdrop-blur-sm">
            <div className="bg-white border border-slate-200 w-full md:max-w-2xl md:mx-4 rounded-t-3xl md:rounded-3xl shadow-2xl overflow-hidden max-h-[92vh] flex flex-col">
                <div className="p-5 md:p-6 border-b border-slate-100 flex justify-between items-center bg-slate-50 shrink-0">
                    <div>
                        <h3 className="text-lg md:text-xl font-bold text-slate-900">
                            {isEdit ? 'Edit Transaction' : 'Add Transaction'}
                        </h3>
                        <p className="mt-0.5 text-xs font-semibold text-slate-400">
                            {isEdit ? 'Supervisor and type are locked after create.' : 'Create topup or expense for staff.'}
                        </p>
                    </div>
                    <button type="button" onClick={onClose} className="p-2 text-slate-400 hover:text-slate-600 transition-colors">
                        <X size={22} />
                    </button>
                </div>

                <form onSubmit={handleSubmit} className="p-5 md:p-6 space-y-4 overflow-y-auto">
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label className="block text-xs font-bold text-slate-400 uppercase tracking-widest mb-2">Staff</label>
                            <select
                                required
                                disabled={isEdit || supervisorsLoading}
                                value={form.supervisor_id}
                                onChange={(e) => setForm({ ...form, supervisor_id: e.target.value })}
                                className="w-full bg-white border border-slate-200 rounded-xl px-4 py-3.5 text-base text-slate-900 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 outline-none disabled:bg-slate-100 disabled:text-slate-500"
                            >
                                <option value="">{supervisorsLoading ? 'Loading staff...' : 'Select staff'}</option>
                                {supervisors.map((supervisor) => (
                                    <option key={supervisor.id} value={supervisor.id}>
                                        {supervisor.name} {supervisor.phone ? `(${supervisor.phone})` : ''}
                                    </option>
                                ))}
                            </select>
                        </div>

                        <div>
                            <label className="block text-xs font-bold text-slate-400 uppercase tracking-widest mb-2">Type</label>
                            <select
                                required
                                disabled={isEdit}
                                value={form.type}
                                onChange={(e) => setForm({ ...form, type: e.target.value })}
                                className="w-full bg-white border border-slate-200 rounded-xl px-4 py-3.5 text-base text-slate-900 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 outline-none disabled:bg-slate-100 disabled:text-slate-500"
                            >
                                <option value="expense">Expense</option>
                                <option value="topup">Topup</option>
                            </select>
                        </div>
                    </div>

                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label className="block text-xs font-bold text-slate-400 uppercase tracking-widest mb-2">Date</label>
                            <input
                                required
                                type="date"
                                value={form.date}
                                onChange={(e) => setForm({ ...form, date: e.target.value })}
                                className="w-full bg-white border border-slate-200 rounded-xl px-4 py-3.5 text-base text-slate-900 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 outline-none"
                            />
                        </div>

                        <div>
                            <label className="block text-xs font-bold text-slate-400 uppercase tracking-widest mb-2">Amount (RM)</label>
                            <input
                                required
                                type="number"
                                step="0.01"
                                min="0.01"
                                inputMode="decimal"
                                value={form.amount}
                                onChange={(e) => setForm({ ...form, amount: e.target.value })}
                                className="w-full bg-white border border-slate-200 rounded-xl px-4 py-3.5 text-base text-slate-900 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 outline-none"
                                placeholder="0.00"
                            />
                        </div>
                    </div>

                    <div>
                        <label className="block text-xs font-bold text-slate-400 uppercase tracking-widest mb-2">Payment To</label>
                        <input
                            value={form.payment_to}
                            onChange={(e) => setForm({ ...form, payment_to: e.target.value })}
                            className="w-full bg-white border border-slate-200 rounded-xl px-4 py-3.5 text-base text-slate-900 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 outline-none"
                            placeholder={form.type === 'topup' ? 'Supervisor Topup' : 'e.g. Shell, Pasar Mini Mubarak'}
                        />
                    </div>

                    <div>
                        <label className="block text-xs font-bold text-slate-400 uppercase tracking-widest mb-2">
                            Details {isExpense && <span className="text-red-400">*</span>}
                        </label>
                        <select
                            required={isExpense}
                            value={form.details}
                            onChange={(e) => setForm({ ...form, details: e.target.value })}
                            className="w-full bg-white border border-slate-200 rounded-xl px-4 py-3.5 text-base text-slate-900 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 outline-none"
                        >
                            <option value="">Select details</option>
                            {DETAILS_OPTIONS.map((option) => (
                                <option key={option} value={option}>{option}</option>
                            ))}
                        </select>
                    </div>

                    <div>
                        <label className="block text-xs font-bold text-slate-400 uppercase tracking-widest mb-2">
                            Description {isExpense && <span className="text-red-400">*</span>}
                        </label>
                        <input
                            required={isExpense}
                            value={form.description}
                            onChange={(e) => setForm({ ...form, description: e.target.value })}
                            className="w-full bg-white border border-slate-200 rounded-xl px-4 py-3.5 text-base text-slate-900 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 outline-none"
                            placeholder={form.type === 'topup' ? 'Duit diterima daripada Admin' : 'e.g. Site meals, Hardware supplies'}
                        />
                    </div>

                    <div>
                        <label className="block text-xs font-bold text-slate-400 uppercase tracking-widest mb-2">
                            Site ID {isExpense && <span className="text-red-400">*</span>}
                        </label>
                        <input
                            required={isExpense}
                            value={form.site_id}
                            onChange={(e) => setForm({ ...form, site_id: e.target.value })}
                            className="w-full bg-white border border-slate-200 rounded-xl px-4 py-3.5 text-base text-slate-900 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 outline-none"
                            placeholder="A102"
                        />
                    </div>

                    <div className="flex flex-col-reverse md:flex-row md:justify-end gap-3 pt-2">
                        <button
                            type="button"
                            onClick={onClose}
                            className="px-5 py-3 rounded-2xl border border-slate-200 text-slate-600 font-bold hover:bg-slate-50"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            disabled={loading}
                            className="inline-flex items-center justify-center gap-2 px-5 py-3 rounded-2xl bg-emerald-600 text-white font-bold shadow-lg shadow-emerald-600/20 hover:bg-emerald-700 disabled:opacity-50"
                        >
                            {loading && <Loader2 size={18} className="animate-spin" />}
                            {isEdit ? 'Save Changes' : 'Create Transaction'}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}
