<?php

namespace App\Support;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Response;

/**
 * Staff report PDFs (Reports, Qualification, Incident Report, Item Analysis):
 * the shared masthead with the school logo, labelled details, then sections
 * rendered by `pdf/report.blade.php`. Callers pass plain, already formatted
 * text; the template escapes everything.
 *
 * Sections:
 * - ['type' => 'table', 'heading' => ?, 'columns' => [['label', 'width'?, 'numeric'?, 'rule'?]], 'rows' => [[cell, ...]], 'empty' => ?, 'note' => ?]
 * - ['type' => 'fields', 'heading' => ?, 'fields' => [[label, value]], 'perRow' => ?]
 * - ['type' => 'text' | 'alert', 'heading' => ?, 'text' => string]
 * Any section may set 'breakBefore' (start a new page) or 'keep' (avoid splitting it).
 */
final class PdfReport
{
    /**
     * @param  list<array{0: string, 1: string}>  $meta
     * @param  list<array<string, mixed>>  $sections
     */
    public static function download(
        User $actor,
        string $title,
        ?string $subtitle,
        array $meta,
        array $sections,
        string $filename,
        string $orientation = 'portrait',
        ?string $reference = null,
    ): Response {
        $html = view('pdf.report', [
            'title' => $title,
            'subtitle' => $subtitle,
            'meta' => $meta,
            'sections' => $sections,
            'reference' => $reference,
            'orientation' => $orientation,
            'organization' => config('institution.organization_name'),
            'systemName' => config('institution.system_name'),
            'generatedAt' => now()->timezone(config('institution.timezone'))->format('d M Y, h:i A T'),
            'generatedBy' => $actor->name,
            'logo' => PdfDocument::logo(),
        ])->render();

        return PdfDocument::download($html, $orientation, $filename);
    }

    /** "—" for an empty value. */
    public static function value(mixed $value): string
    {
        return $value === null || $value === '' ? '—' : (string) $value;
    }

    /** 72.5 → "72.50"; null → "—". */
    public static function number(int|float|string|null $value, int $decimals = 2): string
    {
        return $value === null || $value === '' ? '—' : number_format((float) $value, $decimals);
    }

    /** 72.5 → "72.5%"; null → "—". */
    public static function percent(int|float|null $value): string
    {
        return $value === null ? '—' : rtrim(rtrim(number_format((float) $value, 1), '0'), '.').'%';
    }

    /** An ISO date-time in the institution's timezone; null → "—". */
    public static function dateTime(?string $value): string
    {
        return $value === null ? '—' : CarbonImmutable::parse($value)->timezone(config('institution.timezone'))->format('d M Y, h:i A');
    }
}
