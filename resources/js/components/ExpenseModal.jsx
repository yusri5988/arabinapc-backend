import { useCallback, useEffect, useRef, useState } from 'react';
import { X, Camera, Loader2, Upload, ImagePlus, Trash2, Image as ImageIcon, FileText, Sparkles, CheckCircle2 } from 'lucide-react';
import api from '../lib/axios';
import { logAction } from '../lib/logger';
import { getDetailsOptions } from '../lib/expenseDetails';

const MAX_IMAGE_SIZE_BYTES = 15 * 1024 * 1024;
const MAX_IMAGE_SIZE_LABEL = '15 MB';

const formatFileSize = (bytes) => {
    if (!bytes || bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
};

export default function ExpenseModal({ isOpen, onClose, onRefresh, maxAmount, department = 'Site' }) {
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
    const [stagedReceipts, setStagedReceipts] = useState([]);
    const [scannedSuccess, setScannedSuccess] = useState(false);
    const [itemImages, setItemImages] = useState([]);
    const [detailsOther, setDetailsOther] = useState('');
    const [form, setForm] = useState({
        amount: '',
        payment_to: '',
        details: '',
        description: '',
        site_id: '',
        date: new Date().toISOString().split('T')[0],
        receipt_url: '',
        receipt_urls: [],
        item_images: []
    });

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
        if (!isOpen) {
            stopPolling();
            setLoading(false);
            setProcessing(false);
            setItemImageProcessing(false);
            setDetailsOpen(false);
            setOcrError('');
            setItemImageError('');
            setReceiptFileName('');
            stagedReceipts.forEach((item) => {
                if (item.preview) URL.revokeObjectURL(item.preview);
            });
            setStagedReceipts([]);
            setScannedSuccess(false);
            setItemImages([]);
            setDetailsOther('');
            setForm({
                amount: '',
                payment_to: '',
                details: '',
                description: '',
                site_id: '',
                date: new Date().toISOString().split('T')[0],
                receipt_url: '',
                receipt_urls: [],
                item_images: []
            });
        }
    }, [isOpen, stopPolling]);

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

    if (!isOpen) return null;

    const detailsOptions = getDetailsOptions(department);

    const handleSelectReceiptFiles = (e) => {
        const files = Array.from(e.target.files ?? []);
        if (!files.length) return;

        const siteId = form.site_id.trim();

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
        if (!siteId) {
            alert('Please enter the Site ID first.');
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

        const names = stagedReceipts.map((i) => i.name).join(', ');
        setReceiptFileName(names);

        logAction('receipt.scan_started', 'success', {
            file_count: stagedReceipts.length,
            files: stagedReceipts.map((i) => ({ file_name: i.name, file_size: i.size })),
            site_id: siteId,
        });

        try {
            const res = await api.post('/supervisor/process-receipt', formData, {
                headers: { 'Content-Type': 'multipart/form-data' }
            });

            const { job_id, receipt_url, receipt_urls } = res.data;

            setForm((currentForm) => ({
                ...currentForm,
                receipt_url: receipt_url || currentForm.receipt_url,
                receipt_urls: receipt_urls || (receipt_url ? [receipt_url] : currentForm.receipt_urls)
            }));

            pollIntervalRef.current = setInterval(async () => {
                try {
                    const statusRes = await api.get(`/supervisor/receipt-status/${job_id}`);
                    const { status, data, error } = statusRes.data;

                    if (status === 'completed') {
                        stopPolling();
                        logAction('receipt.polling_completed', 'success', {
                            job_id,
                            amount: data.amount,
                            payment_to: data.payment_to,
                        });
                        setForm((currentForm) => ({
                            ...currentForm,
                            amount: data.amount ? String(data.amount) : currentForm.amount,
                            payment_to: data.payment_to || currentForm.payment_to,
                            description: data.description || currentForm.description,
                            date: data.date || currentForm.date,
                            receipt_url: data.receipt_url || currentForm.receipt_url,
                            receipt_urls: data.receipt_urls || (data.receipt_url ? [data.receipt_url] : currentForm.receipt_urls)
                        }));
                        setScannedSuccess(true);
                        setProcessing(false);
                    } else if (status === 'failed') {
                        stopPolling();
                        logAction('receipt.polling_completed', 'fail', {
                            job_id,
                            error: error || 'AI failed to read receipt',
                        });
                        setOcrError(`Receipt uploaded successfully, but AI could not read the details. Please fill the form manually. ${error || ''}`);
                        setProcessing(false);
                    }
                } catch (pollErr) {
                    if (pollErr.response?.status === 404) {
                        stopPolling();
                        logAction('receipt.polling_completed', 'fail', {
                            job_id,
                            error: 'AI processing status not found (404)',
                        });
                        setOcrError('Receipt saved. AI processing status not found. Please fill the form manually.');
                        setProcessing(false);
                    }
                }
            }, 3000);

            pollTimeoutRef.current = setTimeout(() => {
                stopPolling();
                logAction('receipt.polling_completed', 'fail', {
                    job_id,
                    error: 'Polling timeout (60s)',
                });
                setOcrError('AI is still processing the receipt. Please fill the form manually or try again.');
                setProcessing(false);
            }, 60000);

        } catch (err) {
            const message = err.response?.data?.message || err.response?.data?.error || err.message || 'Unknown error';
            logAction('receipt.api_response_received', 'fail', {
                file_count: stagedReceipts.length,
                error: message,
                status: err.response?.status,
            });
            setOcrError(`Upload failed: ${message}`);
            setProcessing(false);
            console.error('AI Processing Error:', err);
        }
    };

    const handleItemImageUpload = async (e) => {
        const files = Array.from(e.target.files ?? []);
        if (!files.length) return;

        const siteId = form.site_id.trim();

        logAction('item_image.upload_started', 'success', {
            file_count: files.length,
            files: files.map((file) => ({ file_name: file.name, file_size: file.size })),
            site_id: siteId,
        });

        const oversizedFiles = files.filter((file) => file.size > MAX_IMAGE_SIZE_BYTES);
        if (oversizedFiles.length > 0) {
            const message = `Image size cannot exceed ${MAX_IMAGE_SIZE_LABEL}.`;
            logAction('item_image.validation', 'fail', {
                file_names: oversizedFiles.map((file) => file.name),
                file_sizes: oversizedFiles.map((file) => file.size),
                status: 'rejected_client_size',
                error: message,
            });
            setItemImageError(`${message} File: ${oversizedFiles.map((file) => file.name).join(', ')}`);
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

                logAction('item_image.api_request_sent', 'success', {
                    file_name: file.name,
                    site_id: siteId,
                });

                const res = await api.post('/supervisor/process-item-image', formData, {
                    headers: { 'Content-Type': 'multipart/form-data' }
                });

                logAction('item_image.api_response_received', 'success', {
                    file_name: file.name,
                    image_url: res.data.image_url,
                });

                uploaded.push({
                    url: res.data.image_url || '',
                    name: res.data.file_name || file.name,
                });
            }

            setItemImages((current) => {
                const next = [...current, ...uploaded].slice(0, 4);
                setForm((currentForm) => ({
                    ...currentForm,
                    item_images: next,
                }));
                return next;
            });
        } catch (err) {
            const message = err.response?.data?.message || err.response?.data?.error || err.message || 'Unknown error';
            logAction('item_image.api_response_received', 'fail', {
                error: message,
                status: err.response?.status,
            });
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
            setForm((currentForm) => ({
                ...currentForm,
                item_images: next,
            }));
            return next;
        });
    };

    const handleSubmit = async (e) => {
        e.preventDefault();
        
        const detailsValue = form.details === 'Others' ? detailsOther.trim() : form.details;

        if (!detailsValue) {
            alert('Please select details.');
            return;
        }

        logAction('expense.submit_started', 'success', {
            amount: form.amount,
            site_id: form.site_id,
            details: detailsValue,
            has_receipt: !!form.receipt_url,
            item_images_count: form.item_images.length,
        });

        setLoading(true);
        try {
            logAction('expense.api_request_sent', 'success', {
                amount: form.amount,
                site_id: form.site_id,
            });

            await api.post('/supervisor/expense', {
                ...form,
                details: detailsValue,
            });

            logAction('expense.api_response_received', 'success', {
                amount: form.amount,
                site_id: form.site_id,
            });

            onRefresh();
            onClose();
        } catch (err) {
            logAction('expense.api_response_received', 'fail', {
                amount: form.amount,
                site_id: form.site_id,
                error: err.response?.data?.message || err.message,
                status: err.response?.status,
            });
            alert(err.response?.data?.message || 'Error saving data.');
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="fixed inset-0 z-50 flex items-end md:items-center justify-center bg-black/40 backdrop-blur-sm animate-in fade-in duration-300">
            <div className="bg-white border border-slate-200 w-full md:max-w-lg md:mx-4 rounded-t-3xl md:rounded-3xl shadow-2xl overflow-hidden max-h-[92vh] md:max-h-none flex flex-col">
                <div className="p-5 md:p-6 border-b border-slate-100 flex justify-between items-center bg-slate-50 shrink-0">
                    <h3 className="text-lg md:text-xl font-bold text-slate-900">Record Expense</h3>
                    <button onClick={onClose} className="p-2 text-slate-400 hover:text-slate-600 transition-colors">
                        <X size={22} />
                    </button>
                </div>

                <form onSubmit={handleSubmit} className="p-5 md:p-6 space-y-5 overflow-y-auto">
                    <div>
                        <label className="block text-xs font-bold text-slate-400 uppercase tracking-widest mb-2">Site ID</label>
                        <input 
                            required
                            maxLength={100}
                            pattern="[A-Za-z0-9_-]+"
                            title="Site ID hanya boleh mengandungi huruf, nombor, underscore (_) dan dash (-)."
                            value={form.site_id}
                            onChange={e => setForm({...form, site_id: e.target.value})}
                            className="w-full bg-white border border-slate-200 rounded-xl px-4 py-3.5 text-base text-slate-900 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 outline-none transition-all"
                            placeholder="A102"
                        />
                        <p className="mt-1 text-[11px] font-semibold text-slate-400">
                            Required before uploading receipt or item photos. Use letters, numbers, underscore (_) or dash (-).
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
                                        <span className="text-xs text-slate-400">Snap pictures or upload from gallery, up to 4 (maksimum 15 MB setiap imej)</span>
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

                    {/* AI Upload Section */}
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
                            <div className="flex items-center justify-between gap-3">
                                <div>
                                    <p className="font-bold text-slate-900">Selected Receipts ({stagedReceipts.length})</p>
                                    <p className="text-xs text-slate-500 font-medium">
                                        Review file list before clicking the AI scan button.
                                    </p>
                                </div>
                                <span className="inline-flex items-center rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-bold text-emerald-800">
                                    {stagedReceipts.length} {stagedReceipts.length === 1 ? 'file' : 'files'}
                                </span>
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

                    {scannedSuccess && form.receipt_url && (
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

                    {!scannedSuccess && form.receipt_url && (
                        <div className="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">
                            <p className="font-bold">Receipt Attached</p>
                            <p className="mt-0.5 text-xs font-medium text-slate-500">
                                {receiptFileName || 'Receipt'} will be saved with this expense.
                            </p>
                        </div>
                    )}

                    <div className="space-y-4">
                        <div>
                            <label className="block text-xs font-bold text-slate-400 uppercase tracking-widest mb-2">Payment To</label>
                            <input 
                                value={form.payment_to}
                                onChange={e => setForm({...form, payment_to: e.target.value})}
                                className="w-full bg-white border border-slate-200 rounded-xl px-4 py-3.5 text-base text-slate-900 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 outline-none transition-all"
                                placeholder="e.g. Shell, Pasar Mini Mubarak"
                            />
                        </div>
                        <div ref={detailsDropdownRef} className="relative">
                            <label className="block text-xs font-bold text-slate-400 uppercase tracking-widest mb-2">Details</label>
                            <button
                                type="button"
                                onClick={() => setDetailsOpen((current) => !current)}
                                className="w-full bg-white border border-slate-200 rounded-xl px-4 py-3.5 text-left text-base text-slate-900 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 outline-none transition-all flex items-center justify-between gap-3"
                            >
                                <span className={form.details ? 'text-slate-900' : 'text-slate-400'}>
                                    {form.details || 'Select details'}
                                </span>
                                <span className="text-slate-400 text-sm">▾</span>
                            </button>

                            {detailsOpen && (
                                <div className="absolute z-20 mt-2 w-full rounded-xl border border-slate-200 bg-white shadow-xl overflow-hidden">
                                    <div className="max-h-56 overflow-y-auto">
                                        {detailsOptions.map((option) => (
                                            <button
                                                key={option}
                                                type="button"
                                                onClick={() => {
                                                    setForm({...form, details: option});
                                                    if (option !== 'Others') {
                                                        setDetailsOther('');
                                                    }
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
                        {form.details === 'Others' && (
                            <div>
                                <label className="block text-xs font-bold text-slate-400 uppercase tracking-widest mb-2">Other Details</label>
                                <input
                                    required
                                    value={detailsOther}
                                    onChange={e => setDetailsOther(e.target.value)}
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
                                onChange={e => setForm({...form, description: e.target.value})}
                                className="w-full bg-white border border-slate-200 rounded-xl px-4 py-3.5 text-base text-slate-900 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 outline-none transition-all"
                                placeholder="e.g. Site meals, Hardware supplies"
                            />
                        </div>
                        <div className="grid grid-cols-2 gap-3 md:gap-4">
                            <div>
                                <label className="block text-xs font-bold text-slate-400 uppercase tracking-widest mb-2">Date</label>
                                <input
                                    required
                                    type="date"
                                    value={form.date}
                                    onChange={e => setForm({...form, date: e.target.value})}
                                    className="w-full bg-white border border-slate-200 rounded-xl px-4 py-3.5 text-base text-slate-900 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 outline-none transition-all"
                                />
                            </div>
                            <div>
                                <label className="block text-xs font-bold text-slate-400 uppercase tracking-widest mb-2">Amount (RM)</label>
                                <input 
                                    required
                                    type="number"
                                    step="0.01"
                                    inputMode="decimal"
                                    value={form.amount}
                                    onChange={e => setForm({...form, amount: e.target.value})}
                                    className="w-full bg-white border border-slate-200 rounded-xl px-4 py-3.5 text-base text-slate-900 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 outline-none transition-all"
                                    placeholder="0.00"
                                />
                                {maxAmount !== undefined && (
                                    <p className="text-[10px] mt-1 font-bold text-slate-400">
                                        Current Balance: RM {Number(maxAmount).toFixed(2)}
                                    </p>
                                )}
                            </div>
                        </div>

                    </div>

                    <button 
                        type="submit"
                        disabled={loading || processing || itemImageProcessing}
                        className="w-full py-4 bg-emerald-600 hover:bg-emerald-700 active:bg-emerald-800 text-white font-bold rounded-2xl transition-all shadow-lg shadow-emerald-600/20 disabled:opacity-50 text-base"
                    >
                        {loading ? 'Saving...' : 'CONFIRM EXPENSE'}
                    </button>
                </form>
            </div>
        </div>
    );
}
