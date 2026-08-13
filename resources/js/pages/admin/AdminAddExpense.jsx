import { useCallback, useEffect, useRef, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { Camera, Loader2, Upload, ImagePlus, Trash2, Image as ImageIcon, ChevronDown } from 'lucide-react';
import api from '../../lib/axios';
import { normalizeSupervisors } from '../../lib/normalize';
import { getDetailsOptions } from '../../lib/expenseDetails';

const MAX_IMAGE_SIZE_BYTES = 15 * 1024 * 1024;
const MAX_IMAGE_SIZE_LABEL = '15 MB';

export default function AdminAddExpense() {
    const cameraInputRef = useRef(null);
    const uploadInputRef = useRef(null);
    const itemCameraInputRef = useRef(null);
    const itemUploadInputRef = useRef(null);
    const detailsDropdownRef = useRef(null);
    const pollIntervalRef = useRef(null);
    const pollTimeoutRef = useRef(null);
    const [loading, setLoading] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [itemImageProcessing, setItemImageProcessing] = useState(false);
    const [detailsOpen, setDetailsOpen] = useState(false);
    const [ocrError, setOcrError] = useState('');
    const [itemImageError, setItemImageError] = useState('');
    const [receiptFileName, setReceiptFileName] = useState('');
    const [receiptUrl, setReceiptUrl] = useState('');
    const [itemImages, setItemImages] = useState([]);
    const [detailsOther, setDetailsOther] = useState('');
    const [selectedStaff, setSelectedStaff] = useState(null);
    const [form, setForm] = useState({
        supervisor_id: '',
        amount: '',
        payment_to: '',
        details: '',
        description: '',
        site_id: '',
        date: new Date().toISOString().split('T')[0],
    });

    const { data: supervisorsData, isLoading: supervisorsLoading } = useQuery({
        queryKey: ['adminExpensesSupervisors'],
        queryFn: async () => {
            const res = await api.get('/admin/supervisors');
            return res.data;
        },
    });

    const supervisors = normalizeSupervisors(supervisorsData);

    const stopPolling = useCallback(() => {
        if (pollIntervalRef.current) {
            clearInterval(pollIntervalRef.current);
            pollIntervalRef.current = null;
        }
        if (pollTimeoutRef.current) {
            clearTimeout(pollTimeoutRef.current);
            pollTimeoutRef.current = null;
        }
    }, []);

    useEffect(() => {
        return () => {
            stopPolling();
        };
    }, [stopPolling]);

    useEffect(() => {
        const handleClickOutside = (event) => {
            if (detailsDropdownRef.current && !detailsDropdownRef.current.contains(event.target)) {
                setDetailsOpen(false);
            }
        };

        document.addEventListener('mousedown', handleClickOutside);
        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, []);

    useEffect(() => {
        if (!form.supervisor_id) {
            setSelectedStaff(null);
            return;
        }
        const staff = supervisors?.find((s) => String(s.id) === String(form.supervisor_id));
        setSelectedStaff(staff || null);
    }, [form.supervisor_id, supervisors]);

    useEffect(() => {
        if (!form.supervisor_id || !form.site_id) {
            setReceiptUrl('');
            setReceiptFileName('');
            setItemImages([]);
        }
    }, [form.supervisor_id, form.site_id]);

    const handleFileUpload = async (e) => {
        const file = e.target.files?.[0];
        if (!file) return;

        const siteId = form.site_id.trim();

        if (file.size > MAX_IMAGE_SIZE_BYTES) {
            const message = `Saiz imej tidak boleh melebihi ${MAX_IMAGE_SIZE_LABEL}.`;
            setOcrError(message);
            setReceiptFileName('');
            setProcessing(false);
            e.target.value = '';
            return;
        }

        if (!siteId) {
            alert('Sila masukkan Site ID terlebih dahulu sebelum memuat naik resit.');
            setOcrError('Sila masukkan Site ID terlebih dahulu sebelum memuat naik resit.');
            setReceiptFileName('');
            e.target.value = '';
            return;
        }

        if (!/^[A-Za-z0-9_-]+$/.test(siteId)) {
            const message = 'Site ID hanya boleh mengandungi huruf, nombor, underscore (_) dan dash (-).';
            alert(message);
            setOcrError(message);
            setReceiptFileName('');
            e.target.value = '';
            return;
        }

        if (!form.supervisor_id) {
            alert('Sila pilih staff terlebih dahulu sebelum memuat naik resit.');
            setOcrError('Sila pilih staff terlebih dahulu sebelum memuat naik resit.');
            setReceiptFileName('');
            e.target.value = '';
            return;
        }

        stopPolling();
        setProcessing(true);
        setOcrError('');
        setReceiptFileName(file.name);
        const formData = new FormData();
        formData.append('receipt', file);
        formData.append('site_id', siteId);
        formData.append('supervisor_id', form.supervisor_id);

        try {
            const res = await api.post('/admin/process-receipt', formData, {
                headers: { 'Content-Type': 'multipart/form-data' }
            });

            const { job_id, receipt_url } = res.data;
            setReceiptUrl(receipt_url || '');

            pollIntervalRef.current = setInterval(async () => {
                try {
                    const statusRes = await api.get(`/admin/receipt-status/${job_id}`);
                    const { status, data } = statusRes.data;

                    if (status === 'completed') {
                        stopPolling();
                        setForm((currentForm) => ({
                            ...currentForm,
                            amount: data?.amount ? String(data.amount) : currentForm.amount,
                            payment_to: data?.payment_to || currentForm.payment_to,
                            description: data?.description || currentForm.description,
                            date: data?.date || currentForm.date,
                        }));
                        setProcessing(false);
                    } else if (status === 'failed') {
                        stopPolling();
                        setOcrError('Receipt image saved. AI failed to read, please fill the form manually.');
                        setProcessing(false);
                    }
                } catch (pollErr) {
                    if (pollErr.response?.status === 404) {
                        stopPolling();
                        setOcrError('Receipt image saved. AI processing status not found. Please fill the form manually.');
                        setProcessing(false);
                    }
                }
            }, 3000);

            pollTimeoutRef.current = setTimeout(() => {
                stopPolling();
                setOcrError('AI masih memproses resit. Sila isi borang secara manual atau cuba lagi.');
                setProcessing(false);
            }, 60000);

        } catch (err) {
            const message = err.response?.data?.message || err.response?.data?.error || err.message || 'Unknown error';
            setOcrError(`Upload failed: ${message}`);
            setReceiptFileName('');
            setProcessing(false);
            console.error('AI Processing Error:', err);
        } finally {
            e.target.value = '';
        }
    };

    const handleItemImageUpload = async (e) => {
        const files = Array.from(e.target.files ?? []);
        if (!files.length) return;

        const siteId = form.site_id.trim();

        const oversizedFiles = files.filter((file) => file.size > MAX_IMAGE_SIZE_BYTES);
        if (oversizedFiles.length > 0) {
            const message = `Saiz imej tidak boleh melebihi ${MAX_IMAGE_SIZE_LABEL}.`;
            setItemImageError(message);
            setItemImageProcessing(false);
            e.target.value = '';
            return;
        }

        if (!siteId) {
            alert('Sila masukkan Site ID terlebih dahulu sebelum memuat naik gambar barang.');
            setItemImageError('Sila masukkan Site ID terlebih dahulu sebelum memuat naik gambar barang.');
            e.target.value = '';
            return;
        }

        if (!/^[A-Za-z0-9_-]+$/.test(siteId)) {
            const message = 'Site ID hanya boleh mengandungi huruf, nombor, underscore (_) dan dash (-).';
            alert(message);
            setItemImageError(message);
            e.target.value = '';
            return;
        }

        if (!form.supervisor_id) {
            alert('Sila pilih staff terlebih dahulu sebelum memuat naik gambar barang.');
            setItemImageError('Sila pilih staff terlebih dahulu sebelum memuat naik gambar barang.');
            e.target.value = '';
            return;
        }

        const currentCount = itemImages.length;
        const remainingSlots = Math.max(0, 4 - currentCount);

        if (remainingSlots === 0) {
            setItemImageError('Maximum 4 gambar sahaja dibenarkan.');
            e.target.value = '';
            return;
        }

        setItemImageProcessing(true);
        setItemImageError('');

        const filesToUpload = files.slice(0, remainingSlots);
        const uploaded = [];

        try {
            for (const file of filesToUpload) {
                const formData = new FormData();
                formData.append('item_image', file);
                formData.append('site_id', siteId);
                formData.append('supervisor_id', form.supervisor_id);

                const res = await api.post('/admin/process-item-image', formData, {
                    headers: { 'Content-Type': 'multipart/form-data' }
                });

                uploaded.push({
                    url: res.data.image_url || '',
                    name: res.data.file_name || file.name,
                });
            }

            setItemImages((current) => {
                const next = [...current, ...uploaded].slice(0, 4);
                return next;
            });
        } catch (err) {
            const message = err.response?.data?.message || err.response?.data?.error || err.message || 'Unknown error';
            setItemImageError(`Image upload failed: ${message}`);
            console.error('Item image upload error:', err);
        } finally {
            setItemImageProcessing(false);
            e.target.value = '';
        }
    };

    const removeItemImage = (indexToRemove) => {
        setItemImages((current) => {
            const next = current.filter((_, index) => index !== indexToRemove);
            return next;
        });
    };

    const handleSubmit = async (e) => {
        e.preventDefault();

        if (!form.supervisor_id) {
            toast.error('Sila pilih staff terlebih dahulu.');
            return;
        }

        if (!receiptUrl) {
            toast.error('Sila muat naik resit terlebih dahulu.');
            return;
        }

        const detailsValue = form.details === 'Others' ? detailsOther.trim() : form.details;

        if (!detailsValue) {
            toast.error('Sila pilih details.');
            return;
        }

        if (!form.description.trim()) {
            toast.error('Sila isi description.');
            return;
        }

        if (!form.site_id.trim()) {
            toast.error('Sila isi Site ID.');
            return;
        }

        setLoading(true);
        try {
            await api.post('/admin/transactions', {
                supervisor_id: form.supervisor_id,
                type: 'expense',
                amount: form.amount,
                payment_to: form.payment_to,
                details: detailsValue,
                description: form.description.trim(),
                site_id: form.site_id.trim(),
                receipt_url: receiptUrl,
                date: form.date,
                item_images: itemImages,
            });

            toast.success('Expense berjaya direkodkan untuk staff.');
            setForm({
                supervisor_id: '',
                amount: '',
                payment_to: '',
                details: '',
                description: '',
                site_id: '',
                date: new Date().toISOString().split('T')[0],
            });
            setReceiptUrl('');
            setReceiptFileName('');
            setItemImages([]);
            setDetailsOther('');
            setSelectedStaff(null);
            setOcrError('');
            setItemImageError('');
        } catch (err) {
            const message = err.response?.data?.message || err.response?.data?.error || err.message || 'Error saving expense.';
            toast.error(message);
        } finally {
            setLoading(false);
        }
    };

    const detailsOptions = getDetailsOptions(selectedStaff?.department || 'Site');
    const visibleDetailsOptions = form.details && !detailsOptions.includes(form.details)
        ? [form.details, ...detailsOptions]
        : detailsOptions;

    return (
        <div className="max-w-3xl mx-auto">
            <div className="mb-8">
                <h1 className="text-3xl font-black text-slate-900 tracking-tight">Add Expense</h1>
                <p className="mt-1 text-sm font-semibold text-slate-500">Record expense for a specific staff member with receipt.</p>
            </div>

            <div className="bg-white border border-slate-200 rounded-3xl shadow-sm overflow-hidden">
                <div className="p-6 border-b border-slate-100 bg-slate-50">
                    <h2 className="text-lg font-bold text-slate-900">Staff Expense Entry</h2>
                </div>

                <form onSubmit={handleSubmit} className="p-6 space-y-6">
                    {/* Staff Selector */}
                    <div>
                        <label className="block text-xs font-bold text-slate-400 uppercase tracking-widest mb-2">Select Staff</label>
                        <select
                            required
                            value={form.supervisor_id}
                            onChange={(e) => setForm({ ...form, supervisor_id: e.target.value })}
                            className="w-full bg-white border border-slate-200 rounded-xl px-4 py-3.5 text-base text-slate-900 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 outline-none transition-all"
                        >
                            <option value="">{supervisorsLoading ? 'Loading staff...' : 'Select staff'}</option>
                            {supervisors?.map((supervisor) => (
                                <option key={supervisor.id} value={supervisor.id}>
                                    {supervisor.name} {supervisor.department ? `[${supervisor.department}]` : ''} - Balance: RM {Number(supervisor.balance || 0).toFixed(2)}
                                </option>
                            ))}
                        </select>
                    </div>

                    {/* Staff Info Card */}
                    {selectedStaff && (
                        <div className="bg-emerald-50 border border-emerald-100 rounded-2xl p-4 flex items-center justify-between">
                            <div>
                                <p className="font-bold text-slate-900">{selectedStaff.name}</p>
                                <p className="text-xs text-slate-500">{selectedStaff.department || 'Site'} • {selectedStaff.phone}</p>
                            </div>
                            <div className="text-right">
                                <p className="text-xs text-slate-500 font-semibold uppercase">Current Balance</p>
                                <p className="text-xl font-black text-emerald-700">RM {Number(selectedStaff.balance || 0).toFixed(2)}</p>
                            </div>
                        </div>
                    )}

                    {/* Site ID */}
                    <div>
                        <label className="block text-xs font-bold text-slate-400 uppercase tracking-widest mb-2">Site ID</label>
                        <input
                            required
                            maxLength={100}
                            pattern="[A-Za-z0-9_-]+"
                            title="Site ID hanya boleh mengandungi huruf, nombor, underscore (_) dan dash (-)."
                            value={form.site_id}
                            onChange={(e) => setForm({ ...form, site_id: e.target.value })}
                            className="w-full bg-white border border-slate-200 rounded-xl px-4 py-3.5 text-base text-slate-900 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 outline-none transition-all"
                            placeholder="A102"
                        />
                        <p className="mt-1 text-[11px] font-semibold text-slate-400">
                            Required before uploading receipt or item photos.
                        </p>
                    </div>

                    {/* Item Image Section */}
                    <div className="relative">
                        <input
                            ref={itemCameraInputRef}
                            type="file"
                            accept="image/*"
                            capture="environment"
                            multiple
                            onChange={handleItemImageUpload}
                            className="hidden"
                        />
                        <input
                            ref={itemUploadInputRef}
                            type="file"
                            accept="image/*"
                            multiple
                            onChange={handleItemImageUpload}
                            className="hidden"
                        />
                        <div className={`p-6 md:p-8 border-2 border-dashed rounded-2xl flex flex-col items-center justify-center gap-3 transition-all ${
                            itemImageProcessing ? 'bg-slate-50 border-slate-300' : 'bg-slate-50 border-slate-200'
                        }`}>
                            {itemImageProcessing ? (
                                <>
                                    <Loader2 className="text-slate-600 h-10 w-10 animate-spin" />
                                    <p className="text-slate-600 font-bold text-sm">Uploading item photo...</p>
                                </>
                            ) : (
                                <>
                                    <div className="p-4 bg-slate-100 rounded-full text-slate-700">
                                        <ImagePlus size={32} />
                                    </div>
                                    <p className="text-slate-600 font-bold text-center text-sm md:text-base">
                                        Add item photos<br />
                                        <span className="text-xs text-slate-400">Snap pictures or upload from gallery, up to 4</span>
                                    </p>
                                    <div className="grid grid-cols-2 gap-3 w-full mt-2">
                                        <button
                                            type="button"
                                            onClick={() => itemCameraInputRef.current?.click()}
                                            className="flex flex-col items-center justify-center gap-2 rounded-2xl border border-slate-200 bg-white px-3 py-4 text-slate-700 shadow-sm transition-all hover:border-emerald-300 hover:bg-emerald-50 hover:text-emerald-700 active:scale-[0.98]"
                                        >
                                            <Camera size={20} strokeWidth={2.5} />
                                            <span className="text-xs font-black uppercase tracking-wider">Take Photo</span>
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => itemUploadInputRef.current?.click()}
                                            className="flex flex-col items-center justify-center gap-2 rounded-2xl border border-slate-200 bg-white px-3 py-4 text-slate-700 shadow-sm transition-all hover:border-emerald-300 hover:bg-emerald-50 hover:text-emerald-700 active:scale-[0.98]"
                                        >
                                            <Upload size={20} strokeWidth={2.5} />
                                            <span className="text-xs font-black uppercase tracking-wider">Upload Image</span>
                                        </button>
                                    </div>
                                    <p className="text-[11px] font-semibold text-slate-400 mt-1">
                                        {itemImages.length}/4 attached
                                    </p>
                                </>
                            )}
                        </div>
                    </div>

                    {itemImageError && (
                        <div className="bg-red-50 border border-red-200 rounded-xl p-4 text-red-700 text-sm">
                            <p className="font-bold mb-1">Item Photo Error:</p>
                            <p>{itemImageError}</p>
                        </div>
                    )}

                    {itemImages.length > 0 && (
                        <div className="rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700">
                            <div className="flex items-center justify-between gap-3 mb-3">
                                <div>
                                    <p className="font-bold">Item photos attached</p>
                                    <p className="mt-0.5 text-xs font-medium text-slate-500">
                                        {itemImages.length} image{itemImages.length > 1 ? 's' : ''} will be saved with this expense.
                                    </p>
                                </div>
                                <ImageIcon size={18} className="text-slate-400 shrink-0" />
                            </div>

                            <div className="grid grid-cols-1 gap-2">
                                {itemImages.map((image, index) => (
                                    <div key={`${image.url}-${index}`} className="flex items-center justify-between gap-3 rounded-lg border border-slate-200 bg-white px-3 py-2.5">
                                        <div className="min-w-0">
                                            <p className="truncate font-semibold text-slate-800">{image.name}</p>
                                            <p className="text-[11px] text-slate-400 truncate">{image.url}</p>
                                        </div>
                                        <button
                                            type="button"
                                            onClick={() => removeItemImage(index)}
                                            className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-2.5 py-2 text-xs font-bold text-slate-600 hover:bg-white hover:text-red-600 shrink-0"
                                        >
                                            <Trash2 size={14} />
                                            Remove
                                        </button>
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}

                    {/* Receipt Upload Section */}
                    <div className="relative">
                        <input
                            ref={cameraInputRef}
                            type="file"
                            accept="image/*"
                            capture="environment"
                            onChange={handleFileUpload}
                            className="hidden"
                        />
                        <input
                            ref={uploadInputRef}
                            type="file"
                            accept="image/*"
                            onChange={handleFileUpload}
                            className="hidden"
                        />
                        <div className={`p-6 md:p-8 border-2 border-dashed rounded-2xl flex flex-col items-center justify-center gap-3 transition-all ${
                            processing ? 'bg-emerald-50 border-emerald-300' : 'bg-slate-50 border-slate-200'
                        }`}>
                            {processing ? (
                                <>
                                    <Loader2 className="text-emerald-600 h-10 w-10 animate-spin" />
                                    <p className="text-emerald-600 font-bold text-sm">Claude AI is reading the receipt...</p>
                                </>
                            ) : (
                                <>
                                    <div className="p-4 bg-emerald-50 rounded-full text-emerald-600">
                                        <Camera size={32} />
                                    </div>
                                    <p className="text-slate-600 font-bold text-center text-sm md:text-base">
                                        Upload receipt image<br />
                                        <span className="text-xs text-slate-400">AI will auto-fill the form (maksimum 15 MB)</span>
                                    </p>
                                    <div className="grid grid-cols-2 gap-3 w-full mt-2">
                                        <button
                                            type="button"
                                            onClick={() => cameraInputRef.current?.click()}
                                            className="flex flex-col items-center justify-center gap-2 rounded-2xl border border-emerald-100 bg-white px-3 py-4 text-emerald-700 shadow-sm transition-all hover:border-emerald-300 hover:bg-emerald-50 active:scale-[0.98]"
                                        >
                                            <Camera size={20} strokeWidth={2.5} />
                                            <span className="text-xs font-black uppercase tracking-wider">Take Photo</span>
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => uploadInputRef.current?.click()}
                                            className="flex flex-col items-center justify-center gap-2 rounded-2xl border border-slate-200 bg-white px-3 py-4 text-slate-700 shadow-sm transition-all hover:border-emerald-300 hover:bg-emerald-50 hover:text-emerald-700 active:scale-[0.98]"
                                        >
                                            <Upload size={20} strokeWidth={2.5} />
                                            <span className="text-xs font-black uppercase tracking-wider">Upload Image</span>
                                        </button>
                                    </div>
                                </>
                            )}
                        </div>
                    </div>

                    {ocrError && (
                        <div className="bg-red-50 border border-red-200 rounded-xl p-4 text-red-700 text-sm">
                            <p className="font-bold mb-1">Receipt Reading Error:</p>
                            <p>{ocrError}</p>
                        </div>
                    )}

                    {receiptUrl && (
                        <div className="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                            <p className="font-bold">Receipt image attached</p>
                            <p className="mt-0.5 text-xs font-medium text-emerald-600">
                                {receiptFileName || 'Receipt image'} will be saved with this expense.
                            </p>
                        </div>
                    )}

                    {/* Form Fields */}
                    <div className="space-y-4">
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label className="block text-xs font-bold text-slate-400 uppercase tracking-widest mb-2">Payment To</label>
                                <input
                                    value={form.payment_to}
                                    onChange={(e) => setForm({ ...form, payment_to: e.target.value })}
                                    className="w-full bg-white border border-slate-200 rounded-xl px-4 py-3.5 text-base text-slate-900 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 outline-none transition-all"
                                    placeholder="e.g. Shell, Pasar Mini Mubarak"
                                />
                            </div>
                            <div>
                                <label className="block text-xs font-bold text-slate-400 uppercase tracking-widest mb-2">Details</label>
                                <div ref={detailsDropdownRef} className="relative">
                                    <button
                                        type="button"
                                        onClick={() => setDetailsOpen((current) => !current)}
                                        className="w-full bg-white border border-slate-200 rounded-xl px-4 py-3.5 text-left text-base text-slate-900 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 outline-none transition-all flex items-center justify-between"
                                    >
                                        <span className={form.details ? 'text-slate-900' : 'text-slate-400'}>
                                            {form.details || 'Select details'}
                                        </span>
                                        <ChevronDown size={16} className="text-slate-400" />
                                    </button>
                                    {detailsOpen && (
                                        <div className="absolute z-20 mt-2 w-full rounded-xl border border-slate-200 bg-white shadow-xl overflow-hidden">
                                            <div className="max-h-56 overflow-y-auto">
                                                {visibleDetailsOptions.map((option) => (
                                                    <button
                                                        key={option}
                                                        type="button"
                                                        onClick={() => {
                                                            setForm({ ...form, details: option });
                                                            if (option !== 'Others') setDetailsOther('');
                                                            setDetailsOpen(false);
                                                        }}
                                                        className={`w-full px-4 py-3 text-left text-sm transition-colors hover:bg-emerald-50 ${
                                                            form.details === option ? 'bg-emerald-50 text-emerald-700 font-semibold' : 'text-slate-700'
                                                        }`}
                                                    >
                                                        {option}
                                                    </button>
                                                ))}
                                            </div>
                                        </div>
                                    )}
                                </div>
                            </div>
                        </div>

                        {form.details === 'Others' && (
                            <div>
                                <label className="block text-xs font-bold text-slate-400 uppercase tracking-widest mb-2">Other Details</label>
                                <input
                                    required
                                    value={detailsOther}
                                    onChange={(e) => setDetailsOther(e.target.value)}
                                    className="w-full bg-white border border-slate-200 rounded-xl px-4 py-3.5 text-base text-slate-900 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 outline-none transition-all"
                                    placeholder="Type your custom details"
                                />
                            </div>
                        )}

                        <div>
                            <label className="block text-xs font-bold text-slate-400 uppercase tracking-widest mb-2">Usage / Description</label>
                            <input
                                required
                                value={form.description}
                                onChange={(e) => setForm({ ...form, description: e.target.value })}
                                className="w-full bg-white border border-slate-200 rounded-xl px-4 py-3.5 text-base text-slate-900 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 outline-none transition-all"
                                placeholder="e.g. Site meals, Hardware supplies"
                            />
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <label className="block text-xs font-bold text-slate-400 uppercase tracking-widest mb-2">Date</label>
                                <input
                                    required
                                    type="date"
                                    value={form.date}
                                    onChange={(e) => setForm({ ...form, date: e.target.value })}
                                    className="w-full bg-white border border-slate-200 rounded-xl px-4 py-3.5 text-base text-slate-900 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 outline-none transition-all"
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
                                    className="w-full bg-white border border-slate-200 rounded-xl px-4 py-3.5 text-base text-slate-900 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 outline-none transition-all"
                                    placeholder="0.00"
                                />
                                {selectedStaff && (
                                    <p className="text-[10px] mt-1 font-bold text-slate-400">
                                        Current Balance: RM {Number(selectedStaff.balance || 0).toFixed(2)}
                                    </p>
                                )}
                            </div>
                        </div>
                    </div>

                    <button
                        type="submit"
                        disabled={loading || processing || itemImageProcessing || !form.supervisor_id || !receiptUrl}
                        className="w-full py-4 bg-emerald-600 hover:bg-emerald-700 active:bg-emerald-800 text-white font-bold rounded-2xl transition-all shadow-lg shadow-emerald-600/20 disabled:opacity-50 disabled:cursor-not-allowed text-base"
                    >
                        {loading ? 'Saving...' : 'CONFIRM EXPENSE'}
                    </button>
                </form>
            </div>
        </div>
    );
}
