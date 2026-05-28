import { useEffect, useState } from 'react';
import { Loader2, UserPlus, X } from 'lucide-react';
import api from '../lib/axios';

const initialForm = {
    name: '',
    phone: '',
    password: ''
};

export default function CreateSupervisorModal({ isOpen, onClose, onSuccess }) {
    const [form, setForm] = useState(initialForm);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');

    useEffect(() => {
        if (!isOpen) {
            setForm(initialForm);
            setError('');
            setLoading(false);
        }
    }, [isOpen]);

    if (!isOpen) return null;

    const handleChange = (field) => (event) => {
        const value = field === 'phone'
            ? event.target.value.trimStart()
            : event.target.value;

        setForm((currentForm) => ({
            ...currentForm,
            [field]: value
        }));
    };

    const handleSubmit = async (event) => {
        event.preventDefault();
        setLoading(true);
        setError('');

        try {
            await api.post('/admin/supervisors', {
                ...form,
                name: form.name.trim(),
                phone: form.phone.trim().replace(/[\s-]+/g, '')
            });

            onSuccess();
            onClose();
        } catch (err) {
            const apiMessage = err.response?.data?.errors
                ? Object.values(err.response.data.errors).flat()[0]
                : err.response?.data?.message;

            setError(apiMessage || 'Unable to add staff member. Please try again.');
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="fixed inset-0 z-50 flex items-end md:items-center justify-center bg-black/40 backdrop-blur-sm animate-in fade-in duration-300">
            <div className="w-full md:max-w-lg md:mx-4 overflow-hidden rounded-t-3xl md:rounded-3xl border border-slate-200 bg-white shadow-2xl max-h-[90vh] md:max-h-none flex flex-col">
                <div className="flex items-center justify-between border-b border-slate-100 bg-slate-50 p-5 md:p-6 shrink-0">
                    <div className="flex items-center gap-3">
                        <div className="rounded-2xl bg-emerald-50 p-2.5 md:p-3 text-emerald-600">
                            <UserPlus size={20} />
                        </div>
                        <div>
                            <h3 className="text-lg md:text-xl font-bold text-slate-900">Add Staff Member</h3>
                            <p className="text-xs md:text-sm text-slate-500">Create a new staff account.</p>
                        </div>
                    </div>

                    <button onClick={onClose} className="p-2 text-slate-400 transition-colors hover:text-slate-600" type="button">
                        <X size={22} />
                    </button>
                </div>

                <form onSubmit={handleSubmit} className="space-y-5 p-5 md:p-6 overflow-y-auto">
                    {error && (
                        <div className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-600">
                            {error}
                        </div>
                    )}

                    <div>
                        <label className="mb-2 block text-xs font-bold uppercase tracking-widest text-slate-400">Name</label>
                        <input
                            required
                            value={form.name}
                            onChange={handleChange('name')}
                            className="w-full rounded-xl border border-slate-200 bg-white px-4 py-3.5 text-base text-slate-900 outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 transition-all"
                            placeholder="e.g. Ahmad"
                        />
                    </div>

                    <div>
                        <label className="mb-2 block text-xs font-bold uppercase tracking-widest text-slate-400">Phone Number</label>
                        <input
                            required
                            type="tel"
                            inputMode="tel"
                            value={form.phone}
                            onChange={handleChange('phone')}
                            className="w-full rounded-xl border border-slate-200 bg-white px-4 py-3.5 text-base text-slate-900 outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 transition-all"
                            placeholder="0123456789"
                        />
                    </div>

                    <div>
                        <label className="mb-2 block text-xs font-bold uppercase tracking-widest text-slate-400">Password</label>
                        <input
                            required
                            minLength={6}
                            type="password"
                            value={form.password}
                            onChange={handleChange('password')}
                            className="w-full rounded-xl border border-slate-200 bg-white px-4 py-3.5 text-base text-slate-900 outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 transition-all"
                            placeholder="Minimum 6 characters"
                        />
                    </div>

                    <button
                        type="submit"
                        disabled={loading}
                        className="flex w-full items-center justify-center gap-2 rounded-2xl bg-emerald-600 px-4 py-4 font-bold text-white shadow-lg shadow-emerald-600/20 transition-all hover:bg-emerald-700 active:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        {loading ? <Loader2 className="h-5 w-5 animate-spin" /> : <UserPlus size={18} />}
                        {loading ? 'Saving...' : 'CREATE STAFF ACCOUNT'}
                    </button>
                </form>
            </div>
        </div>
    );
}
