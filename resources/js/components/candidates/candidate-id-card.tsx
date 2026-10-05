import { usePage } from '@inertiajs/react';
import { UserRound } from 'lucide-react';
import type { CSSProperties, ReactNode } from 'react';
import { cn } from '@/lib/cn';
import { formatCalendarDate } from '@/lib/format';

/** The card's values from the server (CandidateIdCard::data). Sizes are in points. */
export interface CandidateIdCardData {
    number: string;
    lastName: string;
    givenNames: string;
    name: string;
    roleLabel: string;
    className: string | null;
    campusName: string | null;
    campusAddress: string | null;
    validUntil: string | null;
    emergencyContact: { name: string | null; relationship: string | null; phone: string | null } | null;
    organization: string;
    systemName: string;
    /** The institution's core values (shown on the back); may be empty. */
    coreValues: string[];
    textSizes: { lastName: number; givenNames: number; meta: number };
    photoUrl: string | null;
    qrUrl: string;
}

/** The camouflage bands and terrain backgrounds (IdCardArtwork::dataUris), as SVG data addresses. */
export interface IdCardArtwork {
    frontHeader: string;
    frontFooter: string;
    backHeader: string;
    backFooter: string;
    frontTerrain: string;
    backTerrain: string;
}

/**
 * The card at the standard ID size (CR80, 54 x 85.6 mm = 153 x 243 pt),
 * drawn at 2 px per point with the same positions as the PDF
 * (resources/views/pdf/id-cards.blade.php), so the screen shows what prints.
 * Army look (owner request 2026-10-05): camouflage, olive drab, black and brass.
 */
const PX = 2;
const at = (pt: number): string => `${pt * PX}px`;

const BRASS = 'bg-[#c9a13b]';
const BLACK = 'bg-[#15180f]';

function Side({ label, children }: { label: string; children: ReactNode }) {
    return (
        <figure className="flex flex-col items-center gap-2">
            <div
                role="img"
                aria-label={label}
                className="relative overflow-hidden rounded-[18px] bg-[#efe9d6] text-center text-[#1b1f14] shadow-lg ring-1 ring-black/20"
                style={{ width: at(153), height: at(243) }}
            >
                {children}
            </div>
            <figcaption className="text-xs font-medium uppercase tracking-wider text-ink-muted">{label}</figcaption>
        </figure>
    );
}

function Block({ top, height, className, style, children }: { top: number; height?: number; className?: string; style?: CSSProperties; children?: ReactNode }) {
    return (
        <div className={cn('absolute inset-x-0', className)} style={{ top: at(top), height: height === undefined ? undefined : at(height), ...style }}>
            {children}
        </div>
    );
}

/** A piece of the generated artwork, decorative. */
function Art({ src, top, height }: { src: string; top: number; height: number }) {
    return <img src={src} alt="" aria-hidden="true" className="absolute inset-x-0 w-full" style={{ top: at(top), height: at(height) }} />;
}

/** Tactical corner brackets around a frame: [top, left] of each corner box in points. */
function Brackets({ top, left, right, bottom, size, thickness, color }: { top: number; left: number; right: number; bottom: number; size: number; thickness: number; color: string }) {
    const corners: Array<[number, number, CSSProperties]> = [
        [top, left, { borderTopWidth: at(thickness), borderLeftWidth: at(thickness) }],
        [top, right - size, { borderTopWidth: at(thickness), borderRightWidth: at(thickness) }],
        [bottom - size, left, { borderBottomWidth: at(thickness), borderLeftWidth: at(thickness) }],
        [bottom - size, right - size, { borderBottomWidth: at(thickness), borderRightWidth: at(thickness) }],
    ];

    return corners.map(([cornerTop, cornerLeft, borders]) => (
        <span
            key={`${cornerTop}-${cornerLeft}`}
            aria-hidden="true"
            className="absolute border-0 border-solid"
            style={{ top: at(cornerTop), left: at(cornerLeft), width: at(size), height: at(size), borderColor: color, ...borders }}
        />
    ));
}

export function IdCardFront({ card, artwork }: { card: CandidateIdCardData; artwork: IdCardArtwork }) {
    const { app } = usePage().props;
    const meta = [card.className, card.campusName].filter(Boolean).join(' · ');

    return (
        <Side label="Front">
            <Art src={artwork.frontTerrain} top={67.5} height={159} />
            <Art src={artwork.frontHeader} top={0} height={64} />
            {app.logoUrl !== null && (
                <img
                    src={app.logoUrl}
                    alt=""
                    className="absolute rounded-full border-[3px] border-[#c9a13b] object-cover"
                    style={{ top: at(6), left: at(60.5), width: at(32), height: at(32) }}
                />
            )}
            <Block top={41} className="px-[12px] text-[11.6px] leading-[14px] font-bold uppercase tracking-[1px] text-white [text-shadow:0_1px_2px_rgb(0_0_0/0.6)]">
                {card.organization}
            </Block>
            <Block top={64} height={2.5} className={BRASS} />
            <Block top={66.5} height={1} className={BLACK} />

            <Brackets top={72} left={37} right={116} bottom={163} size={9.5} thickness={1.5} color="#c9a13b" />
            <div className="absolute border-[3px] border-[#2b3320] bg-[#dcd5bd]" style={{ top: at(75), left: at(40), width: at(73), height: at(85) }}>
                {card.photoUrl !== null ? (
                    <img src={card.photoUrl} alt={`Picture of ${card.name}`} className="size-full object-cover object-[50%_35%]" />
                ) : (
                    <span className="flex size-full flex-col items-center justify-center gap-1 text-[11px] uppercase tracking-[1.2px] text-[#5d6347]">
                        <UserRound className="size-10" aria-hidden="true" />
                        No picture
                    </span>
                )}
            </div>

            <Block top={165} className="px-2 font-bold uppercase tracking-[1.2px]" style={{ fontSize: at(card.textSizes.lastName) }}>
                {card.lastName}
            </Block>
            <Block top={178} className="px-2 text-[#3a4030]" style={{ fontSize: at(card.textSizes.givenNames) }}>
                {card.givenNames}
            </Block>
            <Block
                top={190}
                height={12}
                className={cn(BLACK, 'flex items-center justify-center border-y-[1.6px] border-[#c9a13b] text-[11.6px] font-bold uppercase tracking-[3.2px] text-[#e2bd4f]')}
            >
                <span aria-hidden="true">★</span>
                <span className="px-[6px]">{card.roleLabel}</span>
                <span aria-hidden="true">★</span>
            </Block>

            <Block top={202.5} className="text-[8.8px] font-bold uppercase tracking-[2px] text-[#5d6347]">
                Serial No.
            </Block>
            <Block top={207} className="font-mono text-[18px] font-bold tracking-[2px]">
                {card.number}
            </Block>
            <Block top={218} className="px-2 text-[#3a4030]" style={{ fontSize: at(card.textSizes.meta) }}>
                {meta}
            </Block>

            <Block top={226.5} height={1} className={BRASS} />
            <Art src={artwork.frontFooter} top={227.5} height={15.5} />
            <Block top={227.5} height={15.5} className="flex items-center justify-center text-[11.2px] font-bold uppercase tracking-[2px] text-[#e2bd4f]">
                {card.validUntil !== null ? `Valid until ${formatCalendarDate(card.validUntil)}` : 'Validity not set'}
            </Block>
        </Side>
    );
}

export function IdCardBack({ card, artwork, printedOn }: { card: CandidateIdCardData; artwork: IdCardArtwork; printedOn: string }) {
    const contact = card.emergencyContact;

    return (
        <Side label="Back">
            <Art src={artwork.backTerrain} top={32.8} height={194} />
            <Art src={artwork.backHeader} top={0} height={30} />
            {/* The organization is named on the front; the back says what the card is. */}
            <Block top={card.coreValues.length > 0 ? 8 : 11.7} className="text-[10.8px] leading-[13.2px] font-bold uppercase tracking-[1.6px] text-white [text-shadow:0_1px_2px_rgb(0_0_0/0.6)]">
                Official Identification Card
            </Block>
            {card.coreValues.length > 0 && (
                <Block top={18} className="text-[8.8px] leading-[10.8px] font-bold uppercase tracking-[2px] text-[#e2bd4f] [text-shadow:0_1px_2px_rgb(0_0_0/0.6)]">
                    {card.coreValues.join(' · ')}
                </Block>
            )}
            <Block top={30} height={2} className={BRASS} />
            <Block top={32} height={0.8} className={BLACK} />

            <Block top={36.3} className="text-[10.4px] font-bold uppercase tracking-[2.8px] text-[#4b5320]">
                Attendance QR Code
            </Block>
            <Brackets top={44} left={32.5} right={120.5} bottom={132} size={9.2} thickness={1.2} color="#2b3320" />
            <div className="absolute border-2 border-[#2b3320] bg-white" style={{ top: at(47), left: at(35.5), width: at(82), height: at(82) }} />
            <img src={card.qrUrl} alt={`Attendance QR code of ${card.name}`} className="absolute" style={{ top: at(51), left: at(39.5), width: at(74), height: at(74) }} />
            <Block top={134} className="font-mono text-[15px] font-bold tracking-[2px]">
                {card.number}
            </Block>

            <Block top={146} height={10} className={cn(BLACK, 'flex items-center justify-center text-[10px] font-bold uppercase tracking-[2.8px] text-[#e2bd4f]')}>
                In Case of Emergency
            </Block>
            {contact !== null ? (
                <Block top={159} className="px-3 text-[12.4px] leading-[16px]">
                    {contact.name}
                    {contact.relationship && ` (${contact.relationship})`}
                    {contact.phone && <strong className="block">{contact.phone}</strong>}
                </Block>
            ) : (
                <Block top={162} className="flex flex-col items-center gap-[14px]">
                    <span className="block h-px w-[200px] bg-[#5d6347]" />
                    <span className="block h-px w-[200px] bg-[#5d6347]" />
                </Block>
            )}

            <div className="absolute text-[9.6px] leading-[12.4px] text-[#3a4030]" style={{ top: at(179), left: at(9), width: at(135) }}>
                Property of the school. Carry at all times and present on request. If found, please return it to{' '}
                {card.campusName ?? card.organization}
                {card.campusAddress ? `, ${card.campusAddress}` : ''}.
            </div>

            <div
                className="absolute border-t border-[#1b1f14] pt-[3px] text-[9.2px] font-bold uppercase tracking-[1.6px] text-[#5d6347]"
                style={{ top: at(212), left: at(31.5), width: at(90) }}
            >
                Authorized Signature
            </div>
            <Block top={226.5} height={1} className={BRASS} />
            <Art src={artwork.backFooter} top={227.5} height={15.5} />
            <Block top={227.5} height={15.5} className="flex items-center justify-center text-[8.4px] tracking-[0.6px] text-[#d8cfae]">
                Printed {formatCalendarDate(printedOn)} · {card.systemName}
            </Block>
        </Side>
    );
}
