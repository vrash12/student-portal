import { usePage } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import { Fragment, useEffect, useRef, useState, type CSSProperties, type ReactNode } from 'react';
import { BrandMark } from '@/components/brand-mark';
import { Toaster } from '@/components/ui/toaster';
import { cn } from '@/lib/cn';

/** Same as the signin-wide CSS variant: the supplied picture is used, and the card lines up with it. */
const WIDE_SCREEN = '(min-width: 80rem) and (min-height: 53.75rem)';

/**
 * The supplied picture (login-campus.jpg) is 1672 x 805 px; its painted-over
 * card area is x 834-1398, y 8-805 (the card covers x 846-1386, y 30-788). The picture is drawn with object-cover,
 * anchored right, so the card can be placed over that area from the size of
 * the image box.
 */
const PICTURE = { width: 1672, height: 805, left: 846, top: 30, areaWidth: 540, areaHeight: 758 };

function cardOverPicture(box: { width: number; height: number }): CSSProperties {
    const scale = Math.max(box.width / PICTURE.width, box.height / PICTURE.height);

    return {
        position: 'absolute',
        left: box.width - PICTURE.width * scale + PICTURE.left * scale,
        top: (box.height - PICTURE.height * scale) / 2 + PICTURE.top * scale,
        width: PICTURE.areaWidth * scale,
        height: PICTURE.areaHeight * scale,
        maxWidth: 'none',
        margin: 0,
    };
}

/**
 * Sign-in shell: navy header with the school's name and core values, the
 * locally served photograph behind a glass sign-in card, the motto on wide
 * screens, and a footer. On wide screens the card sits over the area of the
 * supplied picture where the design had its own card; narrower screens use
 * the compact background (loginCompactImageUrl) and a centered card. Texts
 * come from configuration (institution.login); a missing or disabled image
 * falls back to the institutional navy surface.
 */
export default function AuthLayout({ children }: { children: ReactNode }) {
    const { app } = usePage().props;
    const { login } = app;
    const [imageFailed, setImageFailed] = useState(false);
    const imageUrl = app.loginImageUrl && !imageFailed ? app.loginImageUrl : null;
    const mainRef = useRef<HTMLElement>(null);
    const [cardStyle, setCardStyle] = useState<CSSProperties | undefined>(undefined);

    // On wide screens showing the supplied picture, keep the card over its painted area.
    useEffect(() => {
        const main = mainRef.current;
        if (main === null || imageUrl === null || app.loginCompactImageUrl === null || typeof window.matchMedia !== 'function') {
            setCardStyle(undefined);
            return;
        }
        const wide = window.matchMedia(WIDE_SCREEN);
        const update = () => setCardStyle(wide.matches ? cardOverPicture(main.getBoundingClientRect()) : undefined);
        const observer = new ResizeObserver(update);
        observer.observe(main);
        wide.addEventListener('change', update);
        update();

        return () => {
            observer.disconnect();
            wide.removeEventListener('change', update);
        };
    }, [imageUrl, app.loginCompactImageUrl]);
    const year = new Date().getFullYear();

    return (
        <div className="flex min-h-dvh flex-col bg-auth-navy">
            <header className="brand-dark relative z-10 overflow-hidden border-b border-accent-400/70 bg-auth-navy text-white">
                {/* Gold diagonal accent behind the authorized-access notice (decorative). */}
                <div aria-hidden="true" className="pointer-events-none absolute inset-y-0 right-0 hidden w-[26rem] lg:block">
                    <div className="absolute inset-y-0 left-10 w-3 -skew-x-[35deg] bg-gradient-to-b from-accent-300 to-accent-500" />
                    <div className="absolute inset-y-0 left-16 right-0 -skew-x-[35deg] bg-gradient-to-r from-[#152f3f] to-[#0f2532]" />
                </div>

                {/* Below 1560 px the brand, the gold diagonal and the access notice leave no room beside them: the values get their own strip. */}
                <CoreValues
                    values={login.coreValues}
                    className="relative flex justify-center gap-2.5 border-b border-white/10 px-4 py-1.5 text-[10px] tracking-[0.22em] sm:gap-3 sm:text-[11px] sm:tracking-[0.28em] min-[1560px]:hidden"
                />

                <div className="relative mx-auto flex max-w-[110rem] items-center justify-between gap-6 px-4 py-3 sm:px-8 lg:px-10">
                    <div className="flex min-w-0 items-center gap-3 sm:gap-4">
                        <BrandMark round className="size-12 sm:size-16" />
                        <div className="min-w-0">
                            <p className="truncate font-serif text-base font-bold uppercase leading-tight tracking-[0.04em] sm:text-2xl">{login.headerTitle}</p>
                            <p className="mt-1 truncate font-serif text-[10px] font-semibold uppercase tracking-[0.42em] text-accent-300 sm:text-sm">{login.headerSubtitle}</p>
                        </div>
                    </div>

                    <CoreValues values={login.coreValues} className="hidden gap-4 border-l border-white/25 pl-8 text-xs tracking-[0.28em] min-[1560px]:flex" />

                    <div className="hidden shrink-0 items-center gap-3 sm:flex">
                        <ShieldCheck className="size-9 text-accent-300" aria-hidden="true" />
                        <div>
                            <p className="text-sm font-medium uppercase tracking-wider">Authorized access</p>
                            <p className="mt-0.5 text-[10px] uppercase tracking-wider text-primary-100">For official use only</p>
                        </div>
                    </div>
                </div>
            </header>

            <main ref={mainRef} id="main-content" className="relative isolate flex flex-1 items-center">
                {imageUrl !== null && (
                    <picture>
                        {app.loginCompactImageUrl !== null && <source media={WIDE_SCREEN} srcSet={imageUrl} />}
                        <img
                            src={app.loginCompactImageUrl ?? imageUrl}
                            alt=""
                            fetchPriority="high"
                            className="pointer-events-none absolute inset-0 -z-10 h-full w-full object-cover object-[38%_center] signin-wide:object-right"
                            onError={() => setImageFailed(true)}
                        />
                    </picture>
                )}
                <div className="mx-auto grid w-full max-w-[110rem] grid-cols-1 items-center gap-8 px-4 py-6 sm:px-8 sm:py-7 lg:px-10">
                    <div className="mx-auto w-full max-w-[31rem] rounded-2xl border border-white/70 bg-white/85 p-6 shadow-2xl backdrop-blur-md sm:px-10 sm:py-7 signin-wide:flex signin-wide:flex-col signin-wide:justify-center signin-wide:overflow-y-auto" style={cardStyle}>
                        {children}
                    </div>

                    {login.motto !== null && (
                        <p className="hidden self-end pb-6 text-center font-serif uppercase text-white [text-shadow:0_2px_12px_rgba(0,0,0,0.6)] xl:block">
                            {mottoLines(login.motto).map((line) => (
                                <span key={line} className={cn('block tracking-[0.34em]', line.length <= 5 ? 'my-1 text-base' : 'text-2xl leading-[1.5]')}>
                                    {line}
                                </span>
                            ))}
                            <span aria-hidden="true" className="mx-auto mt-4 block h-0.5 w-14 bg-accent-400" />
                        </p>
                    )}
                </div>
            </main>

            <footer className="relative border-t border-white/15 bg-auth-navy px-4 py-3 text-xs text-primary-100 sm:px-8 lg:px-10">
                <div className="mx-auto flex max-w-[110rem] flex-col items-center justify-between gap-2 sm:flex-row">
                    <p>
                        © {year} {app.organizationName}. All rights reserved. Sign-in activity is recorded.
                    </p>
                    {login.coreValues.length > 0 && (
                        <p aria-hidden="true" className="hidden font-serif uppercase tracking-[0.24em] md:block">
                            {login.coreValues.join('  •  ')}
                        </p>
                    )}
                </div>
            </footer>

            <Toaster position="top" />
        </div>
    );
}

/** The configured core values (INSTITUTION_CORE_VALUES), separated by gold dots; nothing when none are set. */
function CoreValues({ values, className }: { values: string[]; className: string }) {
    if (values.length === 0) {
        return null;
    }

    return (
        <ul aria-label="Core values" className={cn('items-center font-serif uppercase text-primary-100', className)}>
            {values.map((value, index) => (
                <Fragment key={value}>
                    {index > 0 && <li aria-hidden="true" className="size-1 shrink-0 rounded-full bg-accent-300" />}
                    <li>{value}</li>
                </Fragment>
            ))}
        </ul>
    );
}

/** One line per word, with short words ("for a") kept together on their own line, as on the approved design. */
function mottoLines(motto: string): string[] {
    const lines: string[] = [];
    for (const word of motto.split(/\s+/).filter(Boolean)) {
        const last = lines.at(-1);
        if (last !== undefined && word.length <= 3 && last.split(' ').every((part) => part.length <= 3)) {
            lines[lines.length - 1] = `${last} ${word}`;
        } else {
            lines.push(word);
        }
    }

    return lines;
}
