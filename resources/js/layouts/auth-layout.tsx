import { usePage } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import { BrandMark } from '@/components/brand-mark';
import { Toaster } from '@/components/ui/toaster';

export default function AuthLayout({ children }: { children: ReactNode }) {
    const { app } = usePage().props;
    const [imageFailed, setImageFailed] = useState(false);
    const showImage = Boolean(app.loginImageUrl) && !imageFailed;

    return (
        <div className="flex min-h-dvh flex-col items-center justify-center border-t-8 border-accent-300 bg-primary-900 px-4 py-8 sm:px-6 sm:py-12">
            <div className="w-full max-w-5xl overflow-hidden rounded-2xl border border-white/15 bg-primary-800 shadow-xl lg:grid lg:grid-cols-[1.15fr_1fr]">
                <section aria-label="Institution" className="flex flex-col">
                    <div className="flex items-center gap-4 p-5 sm:p-8">
                        <BrandMark className="size-16 sm:size-20" />
                        <div className="min-w-0">
                            <p className="text-sm font-semibold leading-relaxed text-primary-100">{app.organizationName}</p>
                            <p className="mt-2 text-xl font-bold text-white sm:text-2xl">{app.name}</p>
                        </div>
                    </div>
                    {showImage && <figure className="hidden lg:block">
                        <img src={app.loginImageUrl!} alt="Uniformed candidates standing together with raised right hands during a ceremony" width={474} height={316} className="aspect-[3/2] w-full object-cover" onError={() => setImageFailed(true)} />
                    </figure>}
                    <div className="hidden flex-1 border-t-4 border-accent-300 p-8 lg:block">
                        <p className="text-sm font-semibold text-accent-100">Learning. Assessment. Progress.</p>
                        <p className="mt-2 text-sm leading-relaxed text-primary-100">Your place for academic records, examinations and training progress.</p>
                    </div>
                </section>

                <main className="flex flex-col justify-center border-t-4 border-accent-300 bg-surface p-6 sm:p-10 lg:border-t-0">
                    {children}
                    <p className="mt-8 border-t border-line pt-5 text-xs leading-relaxed text-ink-subtle">For authorized users only. Sign-in activity is recorded.</p>
                </main>

                {showImage && <div className="border-t-4 border-accent-300 lg:hidden">
                    <img src={app.loginImageUrl!} alt="Uniformed candidates standing together with raised right hands during a ceremony" width={474} height={316} className="aspect-[3/2] w-full object-cover" onError={() => setImageFailed(true)} />
                </div>
                }
            </div>

            <Toaster position="top" />
        </div>
    );
}
