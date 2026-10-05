import { Head } from '@inertiajs/react';
import { FileDown } from 'lucide-react';
import { IdCardBack, IdCardFront, type CandidateIdCardData, type IdCardArtwork } from '@/components/candidates/candidate-id-card';
import { Alert } from '@/components/ui/alert';
import { buttonClasses } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';
import { routes } from '@/lib/routes';

interface CandidateIdCardProps {
    candidateId: number;
    card: CandidateIdCardData;
    /** The card's camouflage and terrain artwork (same as the PDF). */
    artwork: IdCardArtwork;
    /** What the record is missing for a complete card. */
    missing: string[];
    printedOn: string;
    pdfUrl: string;
}

/** The candidate's ID card, front and back, at the standard ID size. Administrators only for now. */
export default function CandidateIdCard({ candidateId, card, artwork, missing, printedOn, pdfUrl }: CandidateIdCardProps) {
    return (
        <>
            <Head title={`ID Card · ${card.name}`} />

            <PageHeader
                title="ID Card"
                description={`${card.name} · Candidate ${card.number}. Standard ID size (CR80, 54 × 85.6 mm). Print the PDF at actual size (100%).`}
                breadcrumbs={[
                    { label: 'Candidates', href: routes.candidates.index() },
                    { label: card.name, href: routes.candidates.show(candidateId) },
                    { label: 'ID Card' },
                ]}
                actions={
                    <a href={pdfUrl} className={buttonClasses('primary')}>
                        <FileDown className="size-4" aria-hidden="true" />
                        Save as PDF
                    </a>
                }
            />

            <div className="flex flex-col gap-6">
                {missing.length > 0 && (
                    <Alert tone="warning" title="The record is missing something for the card">
                        <ul className="list-disc pl-5">
                            {missing.map((item) => (
                                <li key={item}>{item}</li>
                            ))}
                        </ul>
                    </Alert>
                )}

                <div className="flex flex-wrap items-start justify-center gap-10 rounded-xl border border-line-box bg-surface-muted px-4 py-8">
                    <IdCardFront card={card} artwork={artwork} />
                    <IdCardBack card={card} artwork={artwork} printedOn={printedOn} />
                </div>

                <p className="text-center text-sm text-ink-muted">
                    The QR code on the back is the candidate's attendance code. Issuing a new QR code on the profile makes printed cards stop working for attendance.
                </p>
            </div>
        </>
    );
}
