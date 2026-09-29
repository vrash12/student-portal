import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { BrandMark } from '@/components/brand-mark';
import { Toaster } from '@/components/ui/toaster';

export default function AuthLayout({ children }: { children: ReactNode }) {
    const { app } = usePage().props;

    return (
        <div className="flex min-h-dvh flex-col items-center justify-center px-4 py-12">
            <div className="w-full max-w-sm">
                <div className="mb-8 flex flex-col items-center text-center">
                    <BrandMark className="size-12" />
                    <p className="mt-4 text-sm font-medium text-ink-muted">{app.organizationName}</p>
                    <p className="text-base font-semibold text-ink">{app.name}</p>
                </div>

                <main className="rounded-xl border border-line bg-surface p-6 shadow-sm sm:p-8">{children}</main>

                <p className="mt-6 text-center text-xs text-ink-subtle">
                    For authorized users only. Sign-in activity is recorded.
                </p>
            </div>

            <Toaster position="top" />
        </div>
    );
}
