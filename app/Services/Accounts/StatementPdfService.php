<?php

namespace App\Services\Accounts;

use App\Models\Candidate;
use App\Support\CandidatePresenter;
use App\Support\Money;
use App\Support\PdfDocument;

/**
 * The printable Statement of Account (letter, portrait). Voided entries are
 * left out of the printed statement; they stay visible in the system.
 */
final class StatementPdfService
{
    public function __construct(private readonly AccountLedger $ledger) {}

    public function render(Candidate $candidate, ?string $from, ?string $to): string
    {
        $statement = $this->ledger->statement($candidate, $from, $to);
        $generated = now()->timezone(config('institution.timezone'));
        $cents = fn (string $decimal): int => Money::toCents($decimal);
        $closing = $cents($statement['closing']);
        $standing = array_values(array_filter($statement['entries'], fn (array $entry): bool => $entry['voided'] === null));

        $html = view('pdf.statement-of-account', [
            'candidate' => CandidatePresenter::details($candidate),
            'organization' => config('institution.organization_name'),
            'systemName' => config('institution.system_name'),
            'logo' => PdfDocument::logo(),
            'generatedAt' => $generated->format('d M Y, h:i A T'),
            'reference' => 'SOA-'.$candidate->id.'-'.$generated->format('Ymd-His'),
            'from' => $from,
            'to' => $to,
            'opening' => Money::display($cents($statement['opening'])),
            'charges' => Money::display($cents($statement['charges'])),
            'credits' => Money::display($cents($statement['credits'])),
            'closingLabel' => $closing === 0 ? 'Balance' : $statement['status']['label'],
            'closingDisplay' => Money::display(abs($closing)),
            'entries' => array_map(fn (array $entry): array => [
                ...$entry,
                'amountDisplay' => Money::format($cents($entry['amount'])),
                'balanceDisplay' => Money::display($cents((string) $entry['balance'])),
            ], $standing),
            'voidedCount' => count($statement['entries']) - count($standing),
        ])->render();

        return PdfDocument::render($html, 'portrait');
    }
}
