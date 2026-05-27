import { useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import api from '../lib/axios';
import { setAuth } from '../lib/authStorage';
import { Leaf, Phone, Lock, ArrowRight, Loader2 } from 'lucide-react';

export default function Login({ setUser }) {
    const [phone, setPhone] = useState('');
    const [password, setPassword] = useState('');
    const [error, setError] = useState('');
    const [loading, setLoading] = useState(false);
    const [focused, setFocused] = useState(null);
    const queryClient = useQueryClient();

    const handleSubmit = async (e) => {
        e.preventDefault();
        setLoading(true);
        setError('');
        const normalizedPhone = phone.trim().replace(/[\s-]+/g, '');
        try {
            const res = await api.post('/login', { phone: normalizedPhone, password });
            const token = res.data?.access_token || res.data?.token;
            const user = res.data?.user;

            setAuth(token, user);
            queryClient.clear();
            setUser(user);
        } catch (err) {
            const apiMessage = err.response?.data?.errors?.phone?.[0] || err.response?.data?.message;
            setError(apiMessage || err.message || 'Unable to connect to the login API.');
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="min-h-screen relative overflow-hidden bg-slate-50 flex items-center justify-center font-['Outfit']">
            {/* Dynamic Background */}
            <div className="absolute top-0 left-0 w-full h-[60vh] bg-gradient-to-br from-emerald-600 via-emerald-500 to-teal-400">
                <div className="absolute -bottom-10 -left-20 w-80 h-80 bg-white/10 blur-3xl rounded-full"></div>
                <div className="absolute top-10 right-0 w-64 h-64 bg-teal-200/20 blur-3xl rounded-full"></div>
            </div>

            <div className="w-full max-w-md px-6 z-10 py-12 flex flex-col items-center">
                
                {/* Brand Header */}
                <div className="flex flex-col items-center mb-8 text-white space-y-4 animate-in fade-in slide-in-from-top-4 duration-700">
                    <div className="w-16 h-16 rounded-2xl bg-white/20 backdrop-blur-xl border border-white/30 flex items-center justify-center shadow-2xl">
                        <Leaf className="w-8 h-8 text-white drop-shadow-md" />
                    </div>
                    <div className="text-center">
                        <h1 className="text-3xl font-black tracking-tight drop-shadow-md">Arabina</h1>
                        <p className="text-emerald-50 text-sm font-medium tracking-wide opacity-90 mt-1">Petty Cash Management</p>
                    </div>
                </div>

                {/* Login Card */}
                <div className="w-full bg-white/90 backdrop-blur-2xl p-8 rounded-[2rem] shadow-2xl shadow-emerald-900/10 border border-white/50 animate-in fade-in slide-in-from-bottom-8 duration-700 delay-150 fill-mode-both">
                    <div className="mb-8">
                        <h2 className="text-2xl font-bold text-slate-800">Welcome Back</h2>
                        <p className="text-slate-500 text-sm mt-1">Sign in to access your dashboard</p>
                    </div>

                    <form onSubmit={handleSubmit} className="space-y-5">
                        {error && (
                            <div className="bg-red-50/80 backdrop-blur border border-red-100 text-red-600 px-4 py-3.5 rounded-2xl text-sm font-medium animate-in zoom-in-95 duration-200">
                                {error}
                            </div>
                        )}

                        <div className="space-y-4">
                            {/* Phone Field */}
                            <div className="group relative">
                                <div className={`absolute inset-0 bg-emerald-500/5 rounded-2xl transition-opacity duration-300 ${focused === 'phone' ? 'opacity-100' : 'opacity-0'}`}></div>
                                <div className={`relative flex items-center px-4 py-3.5 bg-slate-50/50 rounded-2xl border transition-all duration-300 ${
                                    focused === 'phone' ? 'border-emerald-500 shadow-sm shadow-emerald-500/10' : 'border-slate-200 hover:border-slate-300'
                                }`}>
                                    <Phone className={`w-5 h-5 transition-colors duration-300 ${focused === 'phone' ? 'text-emerald-500' : 'text-slate-400'}`} />
                                    <input
                                        type="tel"
                                        inputMode="tel"
                                        required
                                        value={phone}
                                        onChange={(e) => setPhone(e.target.value)}
                                        onFocus={() => setFocused('phone')}
                                        onBlur={() => setFocused(null)}
                                        className="w-full pl-3 bg-transparent text-slate-800 text-base font-medium outline-none placeholder:text-slate-400 placeholder:font-normal"
                                        placeholder="Phone Number"
                                    />
                                </div>
                            </div>

                            {/* Password Field */}
                            <div className="group relative">
                                <div className={`absolute inset-0 bg-emerald-500/5 rounded-2xl transition-opacity duration-300 ${focused === 'password' ? 'opacity-100' : 'opacity-0'}`}></div>
                                <div className={`relative flex items-center px-4 py-3.5 bg-slate-50/50 rounded-2xl border transition-all duration-300 ${
                                    focused === 'password' ? 'border-emerald-500 shadow-sm shadow-emerald-500/10' : 'border-slate-200 hover:border-slate-300'
                                }`}>
                                    <Lock className={`w-5 h-5 transition-colors duration-300 ${focused === 'password' ? 'text-emerald-500' : 'text-slate-400'}`} />
                                    <input
                                        type="password"
                                        required
                                        value={password}
                                        onChange={(e) => setPassword(e.target.value)}
                                        onFocus={() => setFocused('password')}
                                        onBlur={() => setFocused(null)}
                                        className="w-full pl-3 bg-transparent text-slate-800 text-base font-medium outline-none placeholder:text-slate-400 placeholder:font-normal"
                                        placeholder="Password"
                                    />
                                </div>
                            </div>
                        </div>

                        <button
                            type="submit"
                            disabled={loading}
                            className="w-full relative group overflow-hidden mt-6 flex items-center justify-center gap-2 py-4 bg-emerald-600 hover:bg-emerald-700 active:bg-emerald-800 text-white rounded-2xl transition-all duration-300 disabled:opacity-70 disabled:cursor-not-allowed"
                        >
                            <div className="absolute inset-0 w-full h-full bg-gradient-to-r from-transparent via-white/20 to-transparent -translate-x-full group-hover:animate-[shimmer_1.5s_infinite]"></div>
                            {loading ? (
                                <>
                                    <Loader2 className="w-5 h-5 animate-spin" />
                                    <span className="font-semibold text-[15px]">Signing in...</span>
                                </>
                            ) : (
                                <>
                                    <span className="font-semibold text-[15px] tracking-wide">Sign In to Continue</span>
                                    <ArrowRight className="w-5 h-5 group-hover:translate-x-1 transition-transform duration-300" />
                                </>
                            )}
                        </button>
                    </form>
                </div>
                
                <p className="text-slate-400/80 text-xs font-medium mt-10 tracking-wider">
                    &copy; {new Date().getFullYear()} ARABINA PETTY CASH
                </p>
            </div>
        </div>
    );
}
