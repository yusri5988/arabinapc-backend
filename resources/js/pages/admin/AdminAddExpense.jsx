import { useCallback, useEffect, useRef, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { Camera, Loader2, Upload, ImagePlus, Trash2, Image as ImageIcon, ChevronDown, FileText, Sparkles, CheckCircle2 } from 'lucide-react';
import api from '../../lib/axios';
import { normalizeSupervisors } from '../../lib/normalize';
import { getDetailsOptions } from '../../lib/expenseDetails';

const MAX_IMAGE_SIZE_BYTES = 15 * 1024 * 1024;
const MAX_IMAGE_SIZE_LABEL = '15 MB';

const formatFileSize = (bytes) => {
    if (!bytes || bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
};

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
    const [receiptUrls, setReceiptUrls] = useState([]);
    const [stagedReceipts, setStagedReceipts] = useState([]);
    const [scannedSuccess, setScannedSuccess] = useState(false);
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
            setReceiptUrls([]);
            setReceiptFileName('');
            stagedReceipts.forEach((item) => {
                if (item.preview) URL.revokeObjectURL(item.preview);
            });
            setStagedReceipts([]);
            setScannedSuccess(false);
            setItemImages([]);
        }
    }, [form.supervisor_id, form.site_id]);

    const handleSelectReceiptFiles = (e) => {
        const files = Array.from(e.target.files ?? []);
        if (!files.length) return;

        const siteId = form.site_id.trim();

        if (!form.supervisor_id) {
            alert('Please select a staff member before selecting receipt files.');
            setOcrError('Please select a staff member before selecting receipt files.');
            e.target.value = '';
            return;
        }

        if (!siteId) {
            alert('Please enter the Site ID before selecting receipt files.');
            setOcrError('Please enter the Site ID before selecting receipt files.');
            e.target.value = '';
            return;
        }

        if (!/^[A-Za-z0-9_-]+$/.test(siteId)) {
            const message = 'Site ID can only contain letters, numbers, underscores (_), and dashes (-).';
            alert(message);
            setOcrError(message);
            e.target.value = '';
            return;
        }

        const oversizedFiles = files.filter((file) => file.size > MAX_IMAGE_SIZE_BYTES);
        if (oversizedFiles.length > 0) {
            const message = `File size cannot exceed ${MAX_IMAGE_SIZE_LABEL}: ${oversizedFiles.map((f) => f.name).join(', ')}`;
            setOcrError(message);
            e.target.value = '';
            return;
        }

        setOcrError('');
        const newItems = files.map((file) => {
            const isPdf = file.type === 'application/pdf' || file.name.toLowerCase().endsWith('.pdf');
            return {
                id: Math.random().toString(36).substring(2, 9),
                file,
                name: file.name,
                size: file.size,
                isPdf,
                preview: isPdf ? null : URL.createObjectURL(file),
            };
        });

        setStagedReceipts((prev) => [...prev, ...newItems]);
        setScannedSuccess(false);
        e.target.value = '';
    };

    const removeStagedReceipt = (id) => {
        setStagedReceipts((prev) => {
            const item = prev.find((i) => i.id === id);
            if (item?.preview) {
                URL.revokeObjectURL(item.preview);
            }
            return prev.filter((i) => i.id !== id);
        });
        setScannedSuccess(false);
    };

    const handleScanReceipts = async () => {
        if (stagedReceipts.length === 0) {
            alert('Please select at least 1 receipt file or PDF first.');
            return;
        }

        const siteId = form.site_id.trim();
        if (!siteId || !form.supervisor_id) {
            alert('Please select a staff member and enter the Site ID first.');
            return;
        }

        stopPolling();
        setProcessing(true);
        setOcrError('');

        const formData = new FormData();
        stagedReceipts.forEach((item) => {
            formData.append('receipts[]', item.file);
        });
        formData.append('site_id', siteId);
        formData.append('supervisor_id', form.supervisor_id);

        const names = stagedReceipts.map((i) => i.name).join(', ');
        setReceiptFileName(names);

        try {
            const res = await api.post('/admin/process-receipt', formData, {
                headers: { 'Content-Type': 'multipart/form-data' }
            });

            const { job_id, receipt_url, receipt_urls } = res.data;
            setReceiptUrl(receipt_url || '');
            setReceiptUrls(receipt_urls || (receipt_url ? [receipt_url] : []));

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
                        if (data?.receipt_url) setReceiptUrl(data.receipt_url);
                        if (data?.receipt_urls) setReceiptUrls(data.receipt_urls);
                        setScannedSuccess(true);
                        setProcessing(false);
                    } else if (status === 'failed') {
                        stopPolling();
                        setOcrError('Receipt uploaded, but AI could not read the details. Please fill the form manually.');
                        setProcessing(false);
                    }
                } catch (pollErr) {
                    if (pollErr.response?.status === 404) {
                        stopPolling();
                        setOcrError('Receipt saved. AI processing status not found. Please fill the form manually.');
                        setProcessing(false);
                    }
                }
            }, 3000);

            pollTimeoutRef.current = setTimeout(() => {
                stopPolling();
                setOcrError('AI is still processing the receipt. Please fill the form manually or try again.');
                setProcessing(false);
            }, 60000);

        } catch (err) {
            const message = err.response?.data?.message || err.response?.data?.error || err.message || 'Unknown error';
            setOcrError(`Upload failed: ${message}`);
            setProcessing(false);
            console.error('AI Processing Error:', err);
        }
    };

    const handleItemImageUpload = async (e) => {
        const files = Array.from(e.target.files ?? []);
        if (!files.length) return;

        const siteId = form.site_id.trim();

        const oversizedFiles = files.filter((file) => file.size > MAX_IMAGE_SIZE_BYTES);
        if (oversizedFiles.length > 0) {
            const message = `Image size cannot exceed ${MAX_IMAGE_SIZE_LABEL}.`;
            setItemImageError(message);
            setItemImageProcessing(false);
            e.target.value = '';
            return;
        }

        if (!siteId) {
            alert('Please enter the Site ID before uploading item photos.');
            setItemImageError('Please enter the Site ID before uploading item photos.');
            e.target.value = '';
            return;
        }

        if (!/^[A-Za-z0-9_-]+$/.test(siteId)) {
            const message = 'Site ID can only contain letters, numbers, underscores (_), and dashes (-).';
            alert(message);
            setItemImageError(message);
            e.target.value = '';
            return;
        }

        if (!form.supervisor_id) {
            alert('Please select a staff member before uploading item photos.');
            setItemImageError('Please select a staff member before uploading item photos.');
            e.target.value = '';
            return;
        }

        const currentCount = itemImages.length;
        const remainingSlots = Math.max(0, 4 - currentCount);

        if (remainingSlots === 0) {
            setItemImageError('Maximum 4 photos allowed.');
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
            toast.error('Please select a staff member first.');
            return;
        }

        if (!receiptUrl) {
            toast.error('Please upload receipts first.');
            return;
        }

        const detailsValue = form.details === 'Others' ? detailsOther.trim() : form.details;

        if (!detailsValue) {
            toast.error('Please select details.');
            return;
        }

        if (!form.description.trim()) {
            toast.error('Please fill in the description.');
            return;
        }

        if (!form.site_id.trim()) {
            toast.error('Please enter the Site ID.');
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
                receipt_urls: receiptUrls,
                date: form.date,
                item_images: itemImages,
            });

            toast.success('Expense successfully recorded for staff member.');
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
            setReceiptUrls([]);
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
                            multiple
                            onChange={handleSelectReceiptFiles}
                            className="hidden"
                        />
                        <input
                            ref={uploadInputRef}
                            type="file"
                            accept="image/*,application/pdf"
                            multiple
                            onChange={handleSelectReceiptFiles}
                            className="hidden"
                        />
                        <div className={`p-6 md:p-8 border-2 border-dashed rounded-2xl flex flex-col items-center justify-center gap-3 transition-all ${
                            processing ? 'bg-emerald-50 border-emerald-300' : 'bg-slate-50 border-slate-200'
                        }`}>
                            {processing ? (
                                 <>
                                     <Loader2 className="text-emerald-600 h-10 w-10 animate-spin" />
                                     <p className="text-emerald-600 font-bold text-sm">
                                         Claude AI is reading {stagedReceipts.length > 0 ? `${stagedReceipts.length} receipt/document(s)` : 'receipt'}...
                                     </p>
                                     <p className="text-xs text-slate-400">Extracting details and calculating grand total...</p>
                                 </>
                             ) : (
                                 <>
                                     <div className="p-4 bg-emerald-50 rounded-full text-emerald-600">
                                         <Camera size={32} />
                                     </div>
                                     <p className="text-slate-600 font-bold text-center text-sm md:text-base">
                                         Select or Capture Receipt / PDF<br />
                                         <span className="text-xs text-slate-400">Files will be listed below before being scanned by AI (maximum 15 MB)</span>
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
                                             <span className="text-xs font-black uppercase tracking-wider">Upload Files</span>
                                         </button>
                                     </div>
                                 </>
                             )}
                         </div>
                     </div>

                     {/* Staged Receipt Files List & Confirm Scan Button */}
                     {stagedReceipts.length > 0 && (
                         <div className="rounded-2xl border border-emerald-200 bg-emerald-50/40 p-4 text-sm text-slate-700 space-y-3">
                            <div>
                                <p className="font-bold text-slate-900">Selected Receipts ({stagedReceipts.length})</p>
                                <p className="text-xs text-slate-500 font-medium">
                                    Review file list before clicking the AI scan button.
                                </p>
                            </div>

                            <div className="grid grid-cols-1 gap-2">
                                {stagedReceipts.map((item) => (
                                    <div key={item.id} className="flex items-center justify-between gap-3 rounded-xl border border-emerald-100 bg-white p-2.5 shadow-sm">
                                        <div className="flex items-center gap-3 min-w-0">
                                            {item.isPdf ? (
                                                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-rose-50 text-rose-600 border border-rose-100 font-bold text-xs">
                                                    PDF
                                                </div>
                                            ) : item.preview ? (
                                                <img
                                                    src={item.preview}
                                                    alt={item.name}
                                                    className="h-10 w-10 shrink-0 rounded-lg object-cover border border-slate-200"
                                                />
                                            ) : (
                                                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-500">
                                                    <FileText size={18} />
                                                </div>
                                            )}
                                            <div className="min-w-0">
                                                <p className="truncate font-semibold text-slate-800 text-xs md:text-sm">{item.name}</p>
                                                <p className="text-[11px] text-slate-400">{formatFileSize(item.size)}</p>
                                            </div>
                                        </div>
                                        <button
                                            type="button"
                                            disabled={processing}
                                            onClick={() => removeStagedReceipt(item.id)}
                                            className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs font-bold text-slate-500 hover:bg-red-50 hover:text-red-600 hover:border-red-200 transition-colors disabled:opacity-50 shrink-0"
                                        >
                                            <Trash2 size={13} />
                                            Remove
                                        </button>
                                    </div>
                                ))}
                            </div>

                            <button
                                type="button"
                                disabled={processing || stagedReceipts.length === 0}
                                onClick={handleScanReceipts}
                                className="w-full flex items-center justify-center gap-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 active:scale-[0.99] text-white py-3 px-4 font-bold text-sm shadow-md shadow-emerald-600/20 transition-all disabled:opacity-50 disabled:cursor-not-allowed"
                            >
                                {processing ? (
                                    <>
                                        <Loader2 size={18} className="animate-spin" />
                                        <span>Reading Receipts...</span>
                                    </>
                                ) : (
                                    <>
                                        <Sparkles size={18} />
                                        <span>Confirm & Scan with AI ({stagedReceipts.length} {stagedReceipts.length === 1 ? 'file' : 'files'})</span>
                                    </>
                                )}
                            </button>
                        </div>
                    )}

                    {ocrError && (
                        <div className="bg-red-50 border border-red-200 rounded-xl p-4 text-red-700 text-sm">
                            <p className="font-bold mb-1">Receipt Reading Error:</p>
                            <p>{ocrError}</p>
                        </div>
                    )}

                    {scannedSuccess && receiptUrl && (
                        <div className="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 flex items-center gap-3">
                            <CheckCircle2 size={20} className="text-emerald-600 shrink-0" />
                            <div>
                                <p className="font-bold">Receipts Scanned Successfully by AI</p>
                                <p className="text-xs text-emerald-600">
                                    Form details below have been auto-filled. Please review before saving.
                                </p>
                            </div>
                        </div>
                    )}

                    {!scannedSuccess && receiptUrl && (
                        <div className="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">
                            <p className="font-bold">Receipt Attached</p>
                            <p className="mt-0.5 text-xs font-medium text-slate-500">
                                {receiptFileName || 'Receipt'} will be saved with this expense.
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
