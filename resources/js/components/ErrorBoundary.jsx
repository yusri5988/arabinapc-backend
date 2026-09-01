import React from 'react';
import { AlertTriangle, RefreshCw } from 'lucide-react';

export default class ErrorBoundary extends React.Component {
    constructor(props) {
        super(props);
        this.state = { hasError: false, error: null };
    }

    static getDerivedStateFromError(error) {
        return { hasError: true, error };
    }

    componentDidCatch(error, errorInfo) {
        console.error('ErrorBoundary caught an error:', error, errorInfo);
    }

    handleReload = () => {
        window.location.reload();
    };

    render() {
        if (this.state.hasError) {
            return (
                <div className="min-h-screen bg-slate-50 flex items-center justify-center p-6 text-slate-900">
                    <div className="max-w-md w-full bg-white rounded-3xl border border-slate-200 p-8 shadow-xl text-center space-y-5">
                        <div className="w-16 h-16 bg-red-50 text-red-600 rounded-2xl flex items-center justify-center mx-auto border border-red-100 shadow-sm">
                            <AlertTriangle size={32} strokeWidth={2.5} />
                        </div>
                        <div>
                            <h2 className="text-xl font-black text-slate-900">Sesuatu Ralat Telah Berlaku</h2>
                            <p className="text-slate-500 text-sm font-medium mt-1">
                                Aplikasi menghadapi masalah semasa memproses data. Sila muat semula halaman untuk meneruskan.
                            </p>
                        </div>
                        <div className="pt-2">
                            <button
                                type="button"
                                onClick={this.handleReload}
                                className="w-full inline-flex items-center justify-center gap-2 rounded-2xl bg-emerald-600 px-5 py-3.5 font-bold text-white shadow-lg shadow-emerald-600/20 hover:bg-emerald-700 active:scale-[0.98] transition-all cursor-pointer"
                            >
                                <RefreshCw size={18} strokeWidth={2.5} />
                                <span>Muat Semula Halaman</span>
                            </button>
                        </div>
                    </div>
                </div>
            );
        }

        return this.props.children;
    }
}
