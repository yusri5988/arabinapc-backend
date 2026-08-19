import React, { useState } from 'react';
import {
    ArrowDownLeft,
    ArrowUpRight,
    Calendar,
    Clock,
    DollarSign,
    ExternalLink,
    FileText,
    Image,
    MapPin,
    MessageSquare,
    Pencil,
    ReceiptText,
    Trash2,
    UserRound,
    X,
    ZoomIn,
} from 'lucide-react';

const money = (value) =>
    Number(value ?? 0).toLocaleString('en-MY', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

const formatDate = (value) => {
    if (!value) return '-';
    const dateObj = typeof value === 'string' && !value.includes('T') && value.length === 10
        ? new Date(`${value}T00:00:00`)
        : new Date(value);
    if (isNaN(dateObj.getTime())) return '-';
    return dateObj.toLocaleDateString('en-MY', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
};

const formatDateTime = (value) => {
    if (!value) return '';
    try {
        const dateObj = new Date(value);
        if (isNaN(dateObj.getTime())) return '';
        const d = dateObj.toLocaleDateString('en-MY', {
            day: 'numeric',
            month: 'short',
            year: 'numeric',
        });
        const t = dateObj.toLocaleTimeString('en-MY', {
            hour: 'numeric',
            minute: '2-digit',
            hour12: true,
        }).toUpperCase();
        return `${d}, ${t}`;
    } catch {
        return '';
    }
};

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

export default function TransactionDetailModal({
    isOpen,
    onClose,
    transaction,
    onEdit,
    onDelete,
    canEdit = false,
}) {
    const [selectedImage, setSelectedImage] = useState(null);

    if (!isOpen || !transaction) return null;

    const isMoneyIn = transaction.type === 'topup';
    const isReturn = transaction.type === 'return_to_admin';
    const formattedId = transaction.id ? `#${transaction.id.toString().padStart(4, '0')}` : '';

    const remark = transaction.metadata?.remark
        || (transaction.type === 'topup' && transaction.details ? transaction.details : null)
        || transaction.metadata?.notes
        || null;

    const itemImages = Array.isArray(transaction.metadata?.item_images)
        ? transaction.metadata.item_images
        : [];

    return (
        <div className="fixed inset-0 z-50 flex items-end md:items-center justify-center bg-black/50 backdrop-blur-sm p-0 md:p-4 animate-in fade-in duration-200">
            {/* Modal Box */}
            <div className="w-full md:max-w-xl max-h-[90vh] flex flex-col rounded-t-[2rem] md:rounded-[2rem] border border-slate-200 bg-white shadow-2xl overflow-hidden">
                {/* Header */}
                <div className="flex items-center justify-between border-b border-slate-100 bg-slate-50/80 px-6 py-4">
                    <div className="flex items-center gap-3">
                        <div className={`w-10 h-10 rounded-2xl flex items-center justify-center shadow-sm border ${
                            isMoneyIn
                                ? 'bg-emerald-100 border-emerald-200 text-emerald-700'
                                : isReturn
                                    ? 'bg-amber-100 border-amber-200 text-amber-700'
                                    : 'bg-slate-100 border-slate-200 text-slate-700'
                        }`}>
                            {isMoneyIn ? (
                                <ArrowDownLeft size={20} strokeWidth={2.5} />
                            ) : (
                                <ArrowUpRight size={20} strokeWidth={2.5} />
                            )}
                        </div>
                        <div>
                            <div className="flex items-center gap-2">
                                <h3 className="text-lg font-black text-slate-900">Transaction Details</h3>
                                {formattedId && (
                                    <span className="text-xs font-bold text-slate-400 bg-slate-200/60 px-2 py-0.5 rounded-full">
                                        {formattedId}
                                    </span>
                                )}
                            </div>
                            <p className="text-xs text-slate-500 font-medium capitalize">
                                {isMoneyIn ? 'Cash In (Topup)' : isReturn ? 'Returned to Admin' : 'Expense / Cash Out'}
                            </p>
                        </div>
                    </div>

                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-full p-2 text-slate-400 transition-colors hover:bg-slate-200/50 hover:text-slate-700"
                    >
                        <X size={20} />
                    </button>
                </div>

                {/* Body Content */}
                <div className="overflow-y-auto p-6 space-y-5">
                    {/* Amount Banner */}
                    <div className={`rounded-2xl p-5 border text-center ${
                        isMoneyIn
                            ? 'bg-emerald-50/60 border-emerald-100'
                            : isReturn
                                ? 'bg-amber-50/60 border-amber-100'
                                : 'bg-slate-50 border-slate-100'
                    }`}>
                        <p className={`text-xs font-bold uppercase tracking-widest ${
                            isMoneyIn ? 'text-emerald-600' : isReturn ? 'text-amber-600' : 'text-slate-400'
                        }`}>
                            Amount
                        </p>
                        <p className={`mt-1 text-3xl md:text-4xl font-black tracking-tight ${
                            isMoneyIn ? 'text-emerald-700' : 'text-slate-900'
                        }`}>
                            {isMoneyIn ? '+' : '-'}RM {money(transaction.amount)}
                        </p>
                    </div>

                    {/* Staff / User Info */}
                    {transaction.user && (
                        <div className="rounded-2xl border border-slate-200/70 bg-slate-50/50 p-4">
                            <p className="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2">Staff Member</p>
                            <div className="flex items-center gap-3">
                                <div className="w-10 h-10 rounded-2xl bg-emerald-100/70 text-emerald-700 font-bold flex items-center justify-center text-sm shadow-sm">
                                    {transaction.user.name?.[0]?.toUpperCase() || 'U'}
                                </div>
                                <div className="min-w-0 flex-1">
                                    <p className="font-bold text-slate-900 text-sm">{transaction.user.name}</p>
                                    <div className="flex items-center gap-2 mt-0.5 flex-wrap">
                                        {transaction.user.department && (
                                            <span className="text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-full bg-slate-200/60 text-slate-600">
                                                {transaction.user.department}
                                            </span>
                                        )}
                                        {transaction.user.phone && (
                                            <span className="text-xs text-slate-500 font-medium">
                                                {transaction.user.phone}
                                            </span>
                                        )}
                                    </div>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Remark / Catatan Section (If Available) */}
                    {remark && (
                        <div className="rounded-2xl border border-teal-100 bg-teal-50/60 p-4">
                            <div className="flex items-center gap-1.5 text-teal-800 text-xs font-bold uppercase tracking-wider mb-1">
                                <MessageSquare size={14} className="text-teal-600" />
                                <span>Remark / Catatan</span>
                            </div>
                            <p className="text-sm font-semibold text-slate-800 break-words leading-relaxed">
                                {remark}
                            </p>
                        </div>
                    )}

                    {/* Transaction Details Grid */}
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
                        {/* Description */}
                        <div className="md:col-span-2 rounded-2xl border border-slate-100 bg-slate-50/50 p-4">
                            <p className="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1">Description / Usage</p>
                            <p className="font-semibold text-slate-900 text-sm leading-relaxed">
                                {transaction.description || '-'}
                            </p>
                        </div>

                        {/* Payment To */}
                        <div className="rounded-2xl border border-slate-100 bg-slate-50/50 p-4">
                            <p className="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1">Payment To</p>
                            <p className="font-bold text-slate-900 text-sm">
                                {transaction.payment_to || '-'}
                            </p>
                        </div>

                        {/* Category / Details */}
                        <div className="rounded-2xl border border-slate-100 bg-slate-50/50 p-4">
                            <p className="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1">Category / Details</p>
                            <p className="font-bold text-slate-900 text-sm">
                                {transaction.details || (isMoneyIn ? 'Topup' : isReturn ? 'Return to Admin' : '-')}
                            </p>
                        </div>

                        {/* Transaction Date */}
                        <div className="rounded-2xl border border-slate-100 bg-slate-50/50 p-4">
                            <p className="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1 flex items-center gap-1">
                                <Calendar size={12} />
                                <span>Transaction Date</span>
                            </p>
                            <p className="font-bold text-slate-900 text-sm">
                                {formatDate(transaction.date || transaction.created_at)}
                            </p>
                        </div>

                        {/* Created At Timestamp */}
                        <div className="rounded-2xl border border-slate-100 bg-slate-50/50 p-4">
                            <p className="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1 flex items-center gap-1">
                                <Clock size={12} />
                                <span>Recorded Timestamp</span>
                            </p>
                            <p className="font-semibold text-slate-700 text-sm">
                                {transaction.created_at ? formatDateTime(transaction.created_at) : '-'}
                            </p>
                        </div>

                        {/* Site ID (if applicable) */}
                        {transaction.site_id && (
                            <div className="md:col-span-2 rounded-2xl border border-slate-100 bg-slate-50/50 p-4">
                                <p className="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1 flex items-center gap-1">
                                    <MapPin size={12} />
                                    <span>Site ID</span>
                                </p>
                                <p className="font-bold text-slate-900 text-sm">
                                    {transaction.site_id}
                                </p>
                            </div>
                        )}
                    </div>

                    {/* Receipt Image / Link */}
                    {transaction.receipt_url && (
                        <div className="rounded-2xl border border-slate-200 bg-slate-50/50 p-4">
                            <div className="flex items-center justify-between mb-3">
                                <div className="flex items-center gap-1.5 text-xs font-bold uppercase tracking-wider text-slate-600">
                                    <ReceiptText size={16} className="text-emerald-600" />
                                    <span>Receipt Document</span>
                                </div>
                                <a
                                    href={normalizeUrl(transaction.receipt_url)}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="inline-flex items-center gap-1 text-xs font-bold text-emerald-600 hover:text-emerald-700 underline underline-offset-2"
                                >
                                    <span>Open Original</span>
                                    <ExternalLink size={12} />
                                </a>
                            </div>

                            <div
                                onClick={() => setSelectedImage(normalizeUrl(transaction.receipt_url))}
                                className="group relative max-h-60 rounded-xl overflow-hidden border border-slate-200/80 bg-white cursor-pointer flex items-center justify-center"
                            >
                                <img
                                    src={normalizeUrl(transaction.receipt_url)}
                                    alt="Receipt"
                                    className="w-full h-auto max-h-60 object-contain group-hover:scale-105 transition-transform duration-300"
                                />
                                <div className="absolute inset-0 bg-black/30 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center gap-2 text-white font-bold text-sm">
                                    <ZoomIn size={18} />
                                    <span>Click to Preview</span>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Item Photos (if any) */}
                    {itemImages.length > 0 && (
                        <div className="rounded-2xl border border-slate-200 bg-slate-50/50 p-4">
                            <div className="flex items-center gap-1.5 text-xs font-bold uppercase tracking-wider text-slate-600 mb-3">
                                <Image size={16} className="text-sky-600" />
                                <span>Item Photos ({itemImages.length})</span>
                            </div>

                            <div className="grid grid-cols-2 sm:grid-cols-3 gap-2.5">
                                {itemImages.map((img, index) => {
                                    const imgUrl = normalizeUrl(img?.url);
                                    if (!imgUrl) return null;
                                    return (
                                        <div
                                            key={index}
                                            onClick={() => setSelectedImage(imgUrl)}
                                            className="group relative aspect-square rounded-xl overflow-hidden border border-slate-200 bg-white cursor-pointer"
                                        >
                                            <img
                                                src={imgUrl}
                                                alt={img?.name || `Item ${index + 1}`}
                                                className="w-full h-full object-cover group-hover:scale-110 transition-transform duration-300"
                                            />
                                            <div className="absolute inset-0 bg-black/30 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center text-white">
                                                <ZoomIn size={16} />
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        </div>
                    )}
                </div>

                {/* Footer Buttons */}
                <div className="border-t border-slate-100 bg-slate-50/60 px-6 py-4 flex items-center justify-between gap-3">
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-xl border border-slate-200 bg-white px-5 py-2.5 text-sm font-bold text-slate-700 shadow-sm hover:bg-slate-50 active:scale-95 transition-all"
                    >
                        Close
                    </button>

                    {canEdit && !isReturn && (
                        <div className="flex items-center gap-2">
                            {onDelete && (
                                <button
                                    type="button"
                                    onClick={() => {
                                        onClose();
                                        onDelete(transaction);
                                    }}
                                    className="inline-flex items-center gap-1.5 rounded-xl border border-red-200 bg-red-50 px-4 py-2.5 text-sm font-bold text-red-600 hover:bg-red-100 active:scale-95 transition-all"
                                >
                                    <Trash2 size={16} />
                                    <span>Delete</span>
                                </button>
                            )}
                            {onEdit && (
                                <button
                                    type="button"
                                    onClick={() => {
                                        onClose();
                                        onEdit(transaction);
                                    }}
                                    className="inline-flex items-center gap-1.5 rounded-xl bg-slate-900 px-5 py-2.5 text-sm font-bold text-white shadow-md shadow-slate-900/20 hover:bg-slate-800 active:scale-95 transition-all"
                                >
                                    <Pencil size={16} />
                                    <span>Edit</span>
                                </button>
                            )}
                        </div>
                    )}
                </div>
            </div>

            {/* Image Full-view Modal */}
            {selectedImage && (
                <div
                    onClick={() => setSelectedImage(null)}
                    className="fixed inset-0 z-[60] flex items-center justify-center bg-black/80 backdrop-blur-md p-4 animate-in fade-in"
                >
                    <div className="relative max-w-4xl max-h-[90vh] overflow-hidden rounded-2xl bg-black">
                        <button
                            type="button"
                            onClick={() => setSelectedImage(null)}
                            className="absolute top-3 right-3 z-10 rounded-full bg-black/60 p-2 text-white hover:bg-black/80"
                        >
                            <X size={20} />
                        </button>
                        <img
                            src={selectedImage}
                            alt="Full View"
                            className="max-h-[85vh] max-w-full object-contain mx-auto"
                        />
                    </div>
                </div>
            )}
        </div>
    );
}
