import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { BrandMark } from '@/components/brand-mark';
import { Toaster } from '@/components/ui/toaster';

export default function AuthLayout({ children }: { children: ReactNode }) {
    const { app } = usePage().props;

    return (
        <div className="flex min-h-dvh flex-col items-center justify-center border-t-8 border-accent-300 bg-primary-900 px-4 py-12">
            <div className="w-full max-w-sm">
                <div className="mb-8 flex flex-col items-center text-center">
                    <BrandMark className="size-24" />
                    <p className="mt-4 text-sm font-medium text-primary-100">{app.organizationName}</p>
                    <p className="mt-2 text-xl font-bold text-white">{app.name}</p>
                </div>

                <main className="rounded-2xl border-t-4 border-accent-300 bg-surface p-6 shadow-xl sm:p-8">{children}</main>

                <p className="mt-6 text-center text-xs text-primary-200">
                    For authorized users only. Sign-in activity is recorded.
                </p>
            </div>

            <Toaster position="top" />
        </div>
    );
}
