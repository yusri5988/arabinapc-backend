import { useEffect, useMemo, useRef, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { ImagePlus, Loader2, ReceiptText, Trash2, Upload, X } from 'lucide-react';
import api from '../lib/axios';
import { normalizeSupervisors } from '../lib/normalize';
import { getDetailsOptions } from '../lib/expenseDetails';

const today = () => new Date().toISOString().split('T')[0];
const MAX_FILE_SIZE = 20 * 1024 * 1024;

const existingReceiptUrls = (transaction) => {
    const urls = Array.isArray(transaction?.metadata?.receipt_urls)
        ? transaction.metadata.receipt_urls
        : [];

    return [...new Set((urls.length ? urls : [transaction?.receipt_url]).filter(Boolean))];
};

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
    const receiptInputRef = useRef(null);
    const itemImageInputRef = useRef(null);
    const [loading, setLoading] = useState(false);
    const [uploadingReceipts, setUploadingReceipts] = useState(false);
    const [uploadingItems, setUploadingItems] = useState(false);
    const [receiptUrls, setReceiptUrls] = useState([]);
    const [itemImages, setItemImages] = useState([]);
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
            setReceiptUrls(existingReceiptUrls(transaction));
            setItemImages(Array.isArray(transaction.metadata?.item_images) ? transaction.metadata.item_images : []);
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
            setReceiptUrls([]);
            setItemImages([]);
        }
        setUploadingReceipts(false);
        setUploadingItems(false);
    }, [isOpen, isEdit, transaction]);

    const isExpense = form.type === 'expense';

    const selectedDepartment = useMemo(() => {
        const selected = supervisors.find((sv) => String(sv.id) === String(form.supervisor_id));
        return selected?.department || transaction?.user?.department || 'Site';
    }, [supervisors, form.supervisor_id, transaction]);

    const detailsOptions = useMemo(() => getDetailsOptions(selectedDepartment), [selectedDepartment]);

    const visibleDetailsOptions = useMemo(() => {
        if (!form.details || detailsOptions.includes(form.details)) return detailsOptions;
        return [form.details, ...detailsOptions];
    }, [detailsOptions, form.details]);

    useEffect(() => {
        if (isEdit) return;
        if (form.details && !detailsOptions.includes(form.details)) {
            setForm((prev) => ({ ...prev, details: '' }));
        }
    }, [isEdit, selectedDepartment, detailsOptions, form.details]);

    if (!isOpen) return null;

    const validateFiles = (files, allowed) => {
        const invalid = files.find((file) => file.size > MAX_FILE_SIZE || !allowed(file));
        if (!invalid) return true;

        toast.error(`${invalid.name} is invalid or exceeds 20 MB.`);
        return false;
    };

    const handleReceiptUpload = async (event) => {
        const files = Array.from(event.target.files || []);
        event.target.value = '';
        if (!files.length) return;
        if (!form.site_id.trim()) {
            toast.error('Enter Site ID before uploading receipts.');
            return;
        }
        if (!validateFiles(files, (file) => file.type.startsWith('image/') || file.type === 'application/pdf')) return;

        setUploadingReceipts(true);
        try {
            const data = new FormData();
            files.forEach((file) => data.append('receipts[]', file));
            data.append('site_id', form.site_id.trim());
            data.append('supervisor_id', form.supervisor_id);

            const response = await api.post('/admin/process-receipt', data, {
                headers: { 'Content-Type': 'multipart/form-data' },
            });
            const uploaded = response.data.receipt_urls || (response.data.receipt_url ? [response.data.receipt_url] : []);
            setReceiptUrls((current) => [...new Set([...current, ...uploaded])]);
            toast.success(`${uploaded.length} receipt file(s) uploaded.`);
        } catch (error) {
            toast.error(getErrorMessage(error));
        } finally {
            setUploadingReceipts(false);
        }
    };

    const handleItemImageUpload = async (event) => {
        const files = Array.from(event.target.files || []);
        event.target.value = '';
        if (!files.length) return;
        if (!form.site_id.trim()) {
            toast.error('Enter Site ID before uploading item photos.');
            return;
        }
        const remaining = 4 - itemImages.length;
        if (remaining <= 0 || files.length > remaining) {
            toast.error(`Maximum 4 item photos. ${remaining} slot(s) available.`);
            return;
        }
        if (!validateFiles(files, (file) => file.type.startsWith('image/'))) return;

        setUploadingItems(true);
        try {
            const uploaded = [];
            for (const file of files) {
                const data = new FormData();
                data.append('item_image', file);
                data.append('site_id', form.site_id.trim());
                data.append('supervisor_id', form.supervisor_id);
                const response = await api.post('/admin/process-item-image', data, {
                    headers: { 'Content-Type': 'multipart/form-data' },
                });
                uploaded.push({ url: response.data.image_url, name: response.data.file_name || file.name });
            }
            setItemImages((current) => [...current, ...uploaded]);
            toast.success(`${uploaded.length} item photo(s) uploaded.`);
        } catch (error) {
            toast.error(getErrorMessage(error));
        } finally {
            setUploadingItems(false);
        }
    };

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
                    receipt_url: receiptUrls[0] || null,
                    receipt_urls: receiptUrls,
                    item_images: itemImages,
                    date: form.date,
                });
                toast.success('Transaction updated.');
            } else {
                await api.post('/admin/transactions', {
                    ...form,
                    receipt_url: receiptUrls[0] || null,
                    receipt_urls: receiptUrls,
                    item_images: itemImages,
                });
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
                                        {supervisor.name} {supervisor.department ? `[${supervisor.department}]` : ''} {supervisor.phone ? `(${supervisor.phone})` : ''}
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
                            {visibleDetailsOptions.map((option) => (
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

                    {isExpense && (
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div className="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                <input ref={receiptInputRef} type="file" accept="image/*,application/pdf" multiple onChange={handleReceiptUpload} className="hidden" />
                                <div className="flex items-center justify-between gap-3">
                                    <div>
                                        <p className="flex items-center gap-2 text-sm font-bold text-slate-800"><ReceiptText size={16} /> Receipts</p>
                                        <p className="mt-1 text-xs text-slate-400">{receiptUrls.length} attached, 20 MB each</p>
                                    </div>
                                    <button type="button" disabled={uploadingReceipts} onClick={() => receiptInputRef.current?.click()} className="inline-flex items-center gap-1.5 rounded-xl bg-emerald-600 px-3 py-2 text-xs font-bold text-white disabled:opacity-50">
                                        {uploadingReceipts ? <Loader2 size={14} className="animate-spin" /> : <Upload size={14} />} Add
                                    </button>
                                </div>
                                <div className="mt-3 space-y-2">
                                    {receiptUrls.map((url, index) => (
                                        <div key={`${url}-${index}`} className="flex items-center justify-between gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2">
                                            <a href={url} target="_blank" rel="noreferrer" className="min-w-0 truncate text-xs font-semibold text-emerald-700">Receipt {index + 1}</a>
                                            <button type="button" onClick={() => setReceiptUrls((current) => current.filter((_, itemIndex) => itemIndex !== index))} className="text-slate-400 hover:text-red-600" title="Remove receipt"><Trash2 size={14} /></button>
                                        </div>
                                    ))}
                                </div>
                            </div>

                            <div className="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                <input ref={itemImageInputRef} type="file" accept="image/*" multiple onChange={handleItemImageUpload} className="hidden" />
                                <div className="flex items-center justify-between gap-3">
                                    <div>
                                        <p className="flex items-center gap-2 text-sm font-bold text-slate-800"><ImagePlus size={16} /> Item Photos</p>
                                        <p className="mt-1 text-xs text-slate-400">{itemImages.length}/4 attached</p>
                                    </div>
                                    <button type="button" disabled={uploadingItems || itemImages.length >= 4} onClick={() => itemImageInputRef.current?.click()} className="inline-flex items-center gap-1.5 rounded-xl bg-slate-800 px-3 py-2 text-xs font-bold text-white disabled:opacity-50">
                                        {uploadingItems ? <Loader2 size={14} className="animate-spin" /> : <Upload size={14} />} Add
                                    </button>
                                </div>
                                <div className="mt-3 space-y-2">
                                    {itemImages.map((image, index) => (
                                        <div key={`${image.url}-${index}`} className="flex items-center justify-between gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2">
                                            <a href={image.url} target="_blank" rel="noreferrer" className="min-w-0 truncate text-xs font-semibold text-sky-700">{image.name || `Item ${index + 1}`}</a>
                                            <button type="button" onClick={() => setItemImages((current) => current.filter((_, itemIndex) => itemIndex !== index))} className="text-slate-400 hover:text-red-600" title="Remove item photo"><Trash2 size={14} /></button>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        </div>
                    )}

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
                            disabled={loading || uploadingReceipts || uploadingItems}
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
