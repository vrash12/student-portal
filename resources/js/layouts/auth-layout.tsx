import { usePage } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import { BrandMark } from '@/components/brand-mark';
import { Toaster } from '@/components/ui/toaster';

export default function AuthLayout({ children }: { children: ReactNode }) {
    const { app } = usePage().props;
    const [imageFailed, setImageFailed] = useState(false);
    const showImage = Boolean(app.loginImageUrl) && !imageFailed;

    return (
        <div className="flex min-h-dvh flex-col items-center justify-center bg-white px-4 py-8 sm:px-6 sm:py-12">
            <div className={`flex w-full flex-col overflow-hidden rounded-2xl border border-line bg-surface shadow-xl ${showImage ? 'max-w-6xl lg:grid lg:grid-cols-[1.15fr_1fr]' : 'max-w-lg'}`}>
                {showImage && <figure className="relative order-2 bg-surface-muted lg:order-1">
                    <img src={app.loginImageUrl!} alt="Uniformed personnel gathered in a hall with Valor, Integrity and Duty displayed on the wall" width={1536} height={2048} className="aspect-[3/4] w-full object-cover object-[center_65%] lg:absolute lg:inset-0 lg:h-full lg:aspect-auto" onError={() => setImageFailed(true)} />
                </figure>}

                <main className="order-1 flex flex-col justify-center p-6 sm:p-10 lg:order-2 lg:py-12">
                    <div className="mb-8 flex items-center gap-4 border-b border-line pb-6">
                        <BrandMark className="size-16 sm:size-20" />
                        <div className="min-w-0">
                            <p className="text-xs font-medium leading-relaxed text-ink-muted">{app.organizationName}</p>
                            <p className="mt-2 text-lg font-bold leading-snug text-primary-900 sm:text-xl">{app.name}</p>
                        </div>
                    </div>
                    {children}
                    <p className="mt-8 border-t border-line pt-5 text-xs leading-relaxed text-ink-subtle">For authorized users only. Sign-in activity is recorded.</p>
                </main>
            </div>

            <Toaster position="top" />
        </div>
    );
}
