import { useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import api from '../../lib/axios';
import { normalizeSupervisors } from '../../lib/normalize';
import { ArrowUpRight, Send } from 'lucide-react';
import SupervisorTopupModal from '../../components/SupervisorTopupModal';
import { logAction } from '../../lib/logger';

export default function AdminTopup() {
    const [selectedSupervisor, setSelectedSupervisor] = useState(null);

    const { data, isLoading, isError, error, refetch } = useQuery({
        queryKey: ['adminSupervisors'],
        queryFn: async () => {
            logAction('topup.supervisors_fetch_started', 'success');
            const res = await api.get('/admin/supervisors', {
                params: { _t: Date.now() },
            });
            return res.data;
        },
        retry: false,
    });

    useEffect(() => {
        logAction('topup.page_loaded', 'success');
    }, []);

    useEffect(() => {
        if (data && !isLoading && !isError) {
            logAction('topup.supervisors_fetched', 'success', {
                count: normalizeSupervisors(data).length,
            });
        }
    }, [data, isLoading, isError]);

    const handleSelectSupervisor = (sv) => {
        logAction('topup.supervisor_selected', 'success', {
            supervisor_id: sv.id,
            supervisor_name: sv.name,
        });
        setSelectedSupervisor(sv);
    };

    if (isLoading) return <div className="flex items-center justify-center h-40 text-emerald-600 animate-pulse font-bold">Loading data...</div>;

    if (isError) {
        const status = error?.response?.status;
        const message = error?.response?.data?.message || error?.message || 'Failed to load staff data.';
        logAction('topup.supervisors_fetched', 'fail', {
            status,
            error: message,
        });

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

    return (
        <div className="space-y-6">
            <SupervisorTopupModal
                isOpen={selectedSupervisor !== null}
                supervisor={selectedSupervisor}
                onClose={() => setSelectedSupervisor(null)}
                onSuccess={refetch}
            />

            <div>
                <h2 className="text-2xl font-black text-slate-900 tracking-tight">Send to Staff Petty Cash</h2>
                <p className="text-slate-500 text-sm font-medium mt-0.5">Select a staff member and enter the amount to send</p>
            </div>

            <div className="pt-2">
                <h3 className="text-lg font-black text-slate-900 mb-4 px-1">Staff Members</h3>

                {supervisors.length === 0 && (
                    <div className="mb-4 rounded-2xl border border-amber-100 bg-amber-50 px-5 py-4 text-amber-700">
                        <p className="font-bold">No staff records found.</p>
                        <p className="mt-1 text-sm font-semibold">
                            API response received, but no staff array was found. Response keys: {data && typeof data === 'object' ? Object.keys(data).join(', ') : 'none'}
                        </p>
                    </div>
                )}
                
                {/* Mobile Cards */}
                <div className="md:hidden space-y-3">
                    {supervisors.map((sv) => (
                        <div key={sv.id} className="bg-white border border-slate-200/60 p-4 rounded-[1.5rem] flex items-center justify-between shadow-sm hover:shadow-md transition-shadow">
                            <div className="flex items-center gap-4">
                                <div className="w-12 h-12 rounded-2xl bg-gradient-to-br from-emerald-100 to-teal-50 border border-emerald-100 flex items-center justify-center text-emerald-600 font-bold shadow-sm text-lg">
                                    {sv.name[0]}
                                </div>
                                <div>
                                    <p className="text-slate-900 font-bold leading-tight">{sv.name}</p>
                                    {sv.department && (
                                        <span className="inline-block mt-0.5 text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-full bg-slate-100 text-slate-500">{sv.department}</span>
                                    )}
                                    <p className="text-emerald-600 text-[13px] font-bold mt-0.5">
                                        <span className="text-[10px] text-slate-400 mr-1 font-semibold uppercase tracking-wider">Bal</span>
                                        RM {sv.balance}
                                    </p>
                                </div>
                            </div>
                            <button
                                type="button"
                                onClick={() => handleSelectSupervisor(sv)}
                                className="w-10 h-10 flex items-center justify-center rounded-xl bg-slate-900 text-white shadow-md shadow-slate-900/20 active:scale-95 transition-all"
                            >
                                <Send size={16} strokeWidth={2.5} className="mr-0.5 mt-0.5" />
                            </button>
                        </div>
                    ))}
                    {supervisors.length === 0 && (
                        <div className="p-8 text-center bg-white rounded-[2rem] border border-slate-200/60">
                            <p className="text-slate-400 font-bold">No staff members found</p>
                        </div>
                    )}
                </div>

                {/* Desktop Table */}
                <div className="hidden md:block bg-white border border-slate-200/60 rounded-[2rem] overflow-hidden shadow-sm">
                    <div className="overflow-x-auto">
                        <table className="w-full text-left">
                            <thead className="bg-slate-50/50 text-slate-400 text-xs uppercase tracking-widest font-bold border-b border-slate-100">
                                <tr>
                                    <th className="px-6 py-5">Staff Member</th>
                                    <th className="px-6 py-5">Department</th>
                                    <th className="px-6 py-5 text-right">Current Balance (RM)</th>
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
                                        <td className="px-6 py-4 text-right text-[15px] font-black text-slate-900">{sv.balance}</td>
                                        <td className="px-6 py-4 text-center">
                                            <button
                                                type="button"
                                                onClick={() => handleSelectSupervisor(sv)}
                                                className="inline-flex items-center gap-2 text-emerald-600 hover:text-emerald-700 bg-emerald-50 px-4 py-2 rounded-xl font-bold transition-colors"
                                            >
                                                Send <ArrowUpRight size={16} strokeWidth={2.5} />
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    );
}
