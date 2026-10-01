import { usePage } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import { BrandMark } from '@/components/brand-mark';
import { Toaster } from '@/components/ui/toaster';

/**
 * Sign-in shell. On wide screens a brand panel sits beside the form; it shows
 * the configured login image (LOGIN_IMAGE_URL) when one is set, and the
 * institution's mark and names otherwise. Narrow screens and tablets in
 * portrait show a compact brand header above the form.
 */
export default function AuthLayout({ children }: { children: ReactNode }) {
    const { app } = usePage().props;
    const [imageFailed, setImageFailed] = useState(false);
    const imageUrl = app.loginImageUrl && !imageFailed ? app.loginImageUrl : null;

    return (
        <div className="flex min-h-dvh items-center justify-center bg-canvas px-4 py-8 sm:px-6 sm:py-12">
            <div className="grid w-full max-w-5xl overflow-hidden rounded-2xl border border-line bg-surface shadow-xl lg:min-h-[36rem] lg:grid-cols-[1fr_1.05fr]">
                <aside className="brand-dark relative hidden overflow-hidden bg-primary-900 text-white lg:flex lg:flex-col" aria-label={app.organizationName}>
                    {imageUrl !== null ? (
                        <>
                            <img src={imageUrl} alt="" className="absolute inset-0 h-full w-full object-cover" onError={() => setImageFailed(true)} />
                            <div className="absolute inset-x-0 bottom-0 h-2/3 bg-gradient-to-t from-primary-900/95 to-transparent" aria-hidden="true" />
                        </>
                    ) : (
                        <div className="absolute inset-0 opacity-[0.07] [background-image:repeating-linear-gradient(135deg,#fff_0,#fff_1px,transparent_1px,transparent_18px)]" aria-hidden="true" />
                    )}

                    <div className="relative flex flex-1 flex-col justify-between p-10">
                        {imageUrl === null && <div className="h-1.5 w-16 rounded-full bg-accent-300" aria-hidden="true" />}
                        <div className={imageUrl === null ? 'my-auto' : 'mt-auto'}>
                            {imageUrl === null && <BrandMark className="mb-8 size-28 drop-shadow-lg" />}
                            <p className="text-sm font-medium uppercase tracking-[0.14em] text-accent-300">{app.organizationName}</p>
                            <p className="mt-3 text-3xl font-bold leading-tight">{app.name}</p>
                            <p className="mt-4 max-w-sm text-sm leading-relaxed text-primary-100">
                                Examinations, grades and academic monitoring for the institution, on the internal network.
                            </p>
                        </div>
                        <p className="relative mt-10 text-xs text-primary-200">For authorized users only. Sign-in activity is recorded.</p>
                    </div>
                </aside>

                <main className="flex flex-col">
                    {/* Compact brand header for tablets in portrait and phones. */}
                    <div className="brand-dark flex items-center gap-4 border-b-4 border-accent-300 bg-primary-900 px-6 py-5 text-white lg:hidden">
                        <BrandMark className="size-14 shrink-0" />
                        <div className="min-w-0">
                            <p className="text-xs font-medium text-primary-100">{app.organizationName}</p>
                            <p className="mt-1 text-lg font-bold leading-snug">{app.name}</p>
                        </div>
                    </div>

                    <div className="flex flex-1 flex-col justify-center px-6 py-8 sm:px-12 sm:py-12">
                        <div className="mx-auto w-full max-w-sm">{children}</div>
                    </div>

                    <p className="border-t border-line px-6 py-4 text-center text-xs text-ink-subtle lg:hidden">For authorized users only. Sign-in activity is recorded.</p>
                </main>
            </div>

            <Toaster position="top" />
        </div>
    );
}
