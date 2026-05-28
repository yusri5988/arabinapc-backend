import { useState, useEffect } from 'react';
import { UserRound, Lock, Save } from 'lucide-react';
import api from '../lib/axios';
import { toast } from 'react-hot-toast';
import { setStoredUser } from '../lib/authStorage';

// Reusable Components
const FormCard = ({ title, icon: Icon, children }) => (
    <div className="bg-white border border-slate-200/60 rounded-[2rem] overflow-hidden shadow-sm h-full">
        <div className="p-6 border-b border-slate-100 flex items-center gap-3 bg-slate-50/50">
            <div className="p-2 bg-white rounded-xl shadow-sm border border-slate-200/50">
                <Icon className="text-emerald-600" size={20} strokeWidth={2.5} />
            </div>
            <h3 className="text-base font-bold text-slate-900">{title}</h3>
        </div>
        {children}
    </div>
);

const FormGroup = ({ label, error, children }) => (
    <div>
        <label className="block text-xs font-bold uppercase tracking-widest text-slate-400 mb-1.5 ml-1">{label}</label>
        {children}
        {error && (
            <p className="text-red-500 text-xs font-bold mt-1.5 ml-1 animate-in fade-in slide-in-from-top-1">
                {error[0]}
            </p>
        )}
    </div>
);

const InputField = ({ error, ...props }) => (
    <input
        {...props}
        className={`w-full bg-slate-50 border ${error ? 'border-red-300 ring-2 ring-red-500/10' : 'border-slate-200'} rounded-2xl px-4 py-3 text-[15px] font-semibold text-slate-900 focus:outline-none focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500 transition-all`}
    />
);

const SubmitButton = ({ loading, icon: Icon, text, loadingText, variant = 'primary' }) => {
    const variants = {
        primary: 'bg-emerald-600 hover:bg-emerald-700 shadow-emerald-600/20',
        dark: 'bg-slate-900 hover:bg-slate-800 shadow-slate-900/10'
    };
    
    return (
        <button
            type="submit"
            disabled={loading}
            className={`w-full flex items-center justify-center gap-2 text-white font-bold py-3.5 rounded-2xl shadow-lg transition-all active:scale-[0.98] disabled:opacity-50 ${variants[variant]}`}
        >
            <Icon size={18} strokeWidth={2.5} />
            {loading ? loadingText : text}
        </button>
    );
};

export default function Profile({ user, setUser }) {
    const [profileLoading, setProfileLoading] = useState(false);
    const [passwordLoading, setPasswordLoading] = useState(false);
    const [errors, setErrors] = useState({});
    
    const [profileData, setProfileData] = useState({
        name: user?.name || '',
        phone: user?.phone || '',
    });

    const [passwordData, setPasswordData] = useState({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    useEffect(() => {
        if (user) {
            setProfileData({
                name: user.name || '',
                phone: user.phone || '',
            });
        }
    }, [user]);

    const handleProfileSubmit = async (e) => {
        e.preventDefault();
        setErrors({});
        setProfileLoading(true);
        try {
            const res = await api.put('/profile', profileData);
            setUser(res.data.user);
            setStoredUser(res.data.user);
            toast.success(res.data.message || 'Profile successfully updated!');
        } catch (err) {
            if (err.response?.status === 422) setErrors(err.response.data.errors);
            toast.error(err.response?.data?.message || 'Failed to update profile');
        } finally {
            setProfileLoading(false);
        }
    };

    const handlePasswordSubmit = async (e) => {
        e.preventDefault();
        setErrors({});
        setPasswordLoading(true);
        try {
            const res = await api.put('/password', passwordData);
            toast.success(res.data.message || 'Password successfully updated!');
            setPasswordData({ current_password: '', password: '', password_confirmation: '' });
        } catch (err) {
            if (err.response?.status === 422) setErrors(err.response.data.errors);
            toast.error(err.response?.data?.message || 'Failed to update password');
        } finally {
            setPasswordLoading(false);
        }
    };

    return (
        <div className="space-y-6">
            <div>
                <h2 className="text-2xl font-black text-slate-900 tracking-tight">Edit Profile</h2>
                <p className="text-slate-500 text-sm font-medium mt-0.5">Manage your personal information and security</p>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-6 items-start">
                {/* Profile Information */}
                <FormCard title="Profile Information" icon={UserRound}>
                    <form onSubmit={handleProfileSubmit} className="p-6 space-y-4">
                        <FormGroup label="Full Name" error={errors.name}>
                            <InputField
                                type="text"
                                required
                                value={profileData.name}
                                onChange={(e) => setProfileData({ ...profileData, name: e.target.value })}
                                placeholder="Your full name"
                                error={errors.name}
                            />
                        </FormGroup>

                        <FormGroup label="Phone Number" error={errors.phone}>
                            <InputField
                                type="tel"
                                required
                                value={profileData.phone}
                                onChange={(e) => setProfileData({ ...profileData, phone: e.target.value })}
                                placeholder="0123456789"
                                error={errors.phone}
                            />
                        </FormGroup>
                        
                        <SubmitButton 
                            loading={profileLoading} 
                            icon={Save} 
                            text="Save Profile" 
                            loadingText="Saving..." 
                        />
                    </form>
                </FormCard>

                {/* Change Password */}
                <FormCard title="Change Password" icon={Lock}>
                    <form onSubmit={handlePasswordSubmit} className="p-6 space-y-4">
                        <FormGroup label="Current Password" error={errors.current_password}>
                            <InputField
                                type="password"
                                required
                                value={passwordData.current_password}
                                onChange={(e) => setPasswordData({ ...passwordData, current_password: e.target.value })}
                                placeholder="••••••••"
                                error={errors.current_password}
                            />
                        </FormGroup>

                        <FormGroup label="New Password" error={errors.password}>
                            <InputField
                                type="password"
                                required
                                value={passwordData.password}
                                onChange={(e) => setPasswordData({ ...passwordData, password: e.target.value })}
                                placeholder="Min. 6 characters"
                                error={errors.password}
                            />
                        </FormGroup>

                        <FormGroup label="Confirm New Password">
                            <InputField
                                type="password"
                                required
                                value={passwordData.password_confirmation}
                                onChange={(e) => setPasswordData({ ...passwordData, password_confirmation: e.target.value })}
                                placeholder="Repeat new password"
                            />
                        </FormGroup>
                        
                        <SubmitButton 
                            loading={passwordLoading} 
                            icon={Lock} 
                            text="Update Password" 
                            loadingText="Updating..." 
                            variant="dark"
                        />
                    </form>
                </FormCard>
            </div>
        </div>
    );
}
