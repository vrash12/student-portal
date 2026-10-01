interface CandidateUnitProps {
    company: string | null;
    platoon: string | null;
}

/**
 * "Alpha Company / 1st Platoon" on one line, or null when neither is
 * assigned. For places with room for a single line of text.
 */
export function candidateUnitLabel(company: string | null, platoon: string | null): string | null {
    const parts = [company, platoon].filter((part): part is string => part !== null && part !== '');

    return parts.length === 0 ? null : parts.join(' / ');
}

/**
 * Company above platoon, for table cells. Each line says what is missing in
 * words, since the names are free text and could otherwise be mistaken for
 * one another.
 */
export function CandidateUnit({ company, platoon }: CandidateUnitProps) {
    if (company === null && platoon === null) {
        return <span className="text-ink-muted">Not assigned</span>;
    }

    return (
        <span className="flex flex-col">
            <span className={company === null ? 'text-ink-muted' : 'text-ink'}>{company ?? 'No company'}</span>
            <span className="text-sm text-ink-muted">{platoon ?? 'No platoon'}</span>
        </span>
    );
}
