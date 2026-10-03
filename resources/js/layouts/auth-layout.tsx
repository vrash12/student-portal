import { usePage } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import { Fragment, useLayoutEffect, useRef, useState, type CSSProperties, type ReactNode } from 'react';
import { BrandMark } from '@/components/brand-mark';
import { Toaster } from '@/components/ui/toaster';
import { cn } from '@/lib/cn';

/**
 * Where the school's main building (the front with its name) is in the
 * sign-in photograph, login-background.png (1672 x 941 px). The layout keeps
 * it in view and never puts the card over it.
 */
const PICTURE = { width: 1672, height: 941, buildingLeft: 535, buildingRight: 915, buildingTop: 165, buildingBottom: 470 };

/** The card's width beside the picture (31rem), the space kept around the building, and the narrowest screen for side by side. */
const CARD_WIDTH = 496;
const BUILDING_MARGIN = 24;
const SIDE_BY_SIDE_FROM = 768;

type Placement = { layout: 'side'; image: CSSProperties } | { layout: 'stacked' };

/**
 * Side by side when the whole building fits left of the card: the picture
 * covers the area and is shifted so the building is centered in the free
 * space. Otherwise (narrow or portrait screens) the picture is a banner
 * above the card.
 */
function placementFor(box: { width: number; height: number }, padding: number): Placement {
    if (box.width < SIDE_BY_SIDE_FROM) {
        return { layout: 'stacked' };
    }

    const scale = Math.max(box.width / PICTURE.width, box.height / PICTURE.height);
    const freeWidth = box.width - padding - CARD_WIDTH - BUILDING_MARGIN;
    const buildingWidth = (PICTURE.buildingRight - PICTURE.buildingLeft) * scale;
    const buildingCenter = ((PICTURE.buildingLeft + PICTURE.buildingRight) / 2) * scale;
    const offsetX = Math.min(0, Math.max(box.width - PICTURE.width * scale, freeWidth / 2 - buildingCenter));
    const buildingCenterY = ((PICTURE.buildingTop + PICTURE.buildingBottom) / 2) * scale;
    const offsetY = Math.min(0, Math.max(box.height - PICTURE.height * scale, box.height * 0.45 - buildingCenterY));

    if (buildingWidth > freeWidth || offsetX + PICTURE.buildingRight * scale > freeWidth || offsetX + PICTURE.buildingLeft * scale < 0) {
        return { layout: 'stacked' };
    }

    return { layout: 'side', image: { objectPosition: `${Math.round(offsetX)}px ${Math.round(offsetY)}px` } };
}

/**
 * Sign-in shell: navy header with the school's name and core values, the
 * locally served photograph of the school, the sign-in card, and a footer.
 * Where the screen allows, the photograph fills the page with the card on the
 * right and the school's building in full view beside it; on narrow or
 * portrait screens the photograph is a banner above the card, so the card
 * never covers the school. Texts come from configuration (institution.login);
 * a missing or disabled image falls back to the institutional navy surface.
 */
export default function AuthLayout({ children }: { children: ReactNode }) {
    const { app } = usePage().props;
    const { login } = app;
    const [imageFailed, setImageFailed] = useState(false);
    // The full-quality photograph (LOGIN_COMPACT_IMAGE_URL), else the main image setting.
    const pictureUrl = app.loginCompactImageUrl ?? app.loginImageUrl;
    const imageUrl = pictureUrl && !imageFailed ? pictureUrl : null;
    const mainRef = useRef<HTMLElement>(null);
    const cardRef = useRef<HTMLDivElement>(null);
    const footerRef = useRef<HTMLElement>(null);
    const [placement, setPlacement] = useState<Placement>({ layout: 'stacked' });

    // Measured before the first paint and on every resize.
    useLayoutEffect(() => {
        const main = mainRef.current;
        if (main === null || imageUrl === null) {
            return;
        }
        const update = () => {
            const box = main.getBoundingClientRect();
            // The height side by side: the window below the header and above the footer, or the card's if taller
            // (never the height of the stacked layout, which would grow with the banner).
            const available = window.innerHeight - (box.top + window.scrollY) - (footerRef.current?.offsetHeight ?? 0);
            const height = Math.max(available, (cardRef.current?.offsetHeight ?? 0) + 56);
            setPlacement(placementFor({ width: box.width, height }, box.width >= 1024 ? 40 : 32));
        };
        update();
        const observer = new ResizeObserver(update);
        observer.observe(main);
        window.addEventListener('resize', update);

        return () => {
            observer.disconnect();
            window.removeEventListener('resize', update);
        };
    }, [imageUrl]);
    const side = imageUrl !== null && placement.layout === 'side';
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

            <main ref={mainRef} id="main-content" className={cn('relative isolate flex flex-1', side ? 'items-center' : 'flex-col')}>
                {imageUrl !== null && (
                    <div aria-hidden="true" className={side ? 'absolute inset-0 -z-10' : 'relative h-[clamp(11rem,32vh,19rem)] w-full shrink-0 overflow-hidden border-b border-accent-400/60'}>
                        <img
                            src={imageUrl}
                            alt=""
                            fetchPriority="high"
                            className="pointer-events-none h-full w-full object-cover"
                            // Stacked: the building is near 43% across and a third down the picture.
                            style={placement.layout === 'side' ? placement.image : { objectPosition: '43% 34%' }}
                            onError={() => setImageFailed(true)}
                        />
                    </div>
                )}
                <div className={cn('mx-auto flex w-full max-w-[110rem] flex-1 items-center px-4 py-6 sm:px-8 sm:py-7 lg:px-10', side ? 'justify-end' : 'justify-center')}>
                    <div ref={cardRef} className={cn('w-full max-w-[31rem] rounded-2xl border border-white/70 p-6 shadow-2xl sm:px-10 sm:py-7', side ? 'bg-white/90 backdrop-blur-md' : 'bg-white')}>
                        {children}
                    </div>
                </div>

                {login.motto !== null && side && (
                    <p className="pointer-events-none absolute bottom-6 left-0 hidden w-1/3 text-center font-serif uppercase text-white [text-shadow:0_2px_12px_rgba(0,0,0,0.6)] xl:block">
                        {mottoLines(login.motto).map((line) => (
                            <span key={line} className={cn('block tracking-[0.34em]', line.length <= 5 ? 'my-1 text-base' : 'text-2xl leading-[1.5]')}>
                                {line}
                            </span>
                        ))}
                        <span aria-hidden="true" className="mx-auto mt-4 block h-0.5 w-14 bg-accent-400" />
                    </p>
                )}
            </main>

            <footer ref={footerRef} className="relative border-t border-white/15 bg-auth-navy px-4 py-3 text-xs text-primary-100 sm:px-8 lg:px-10">
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
