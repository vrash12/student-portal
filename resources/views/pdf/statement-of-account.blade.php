<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Statement of Account</title>
    <style>
        /* Letter portrait (612 x 792 pt). */
        @page { margin: 26pt 30pt 34pt; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 7.5pt; color: #17252d; line-height: 1.3; }
        .masthead { position: relative; text-align: center; min-height: 46pt; padding-bottom: 2pt; }
        .logo { position: absolute; left: 0; top: 0; width: 42pt; height: 42pt; }
        .organization { font-size: 11.5pt; font-weight: bold; margin: 2pt 0 0; }
        .system { font-size: 6.5pt; color: #58676d; margin-top: 1pt; }
        .title { font-size: 10.5pt; font-weight: bold; letter-spacing: 0.8pt; text-transform: uppercase; margin-top: 4pt; }
        .regno { position: absolute; right: 0; top: 4pt; text-align: right; font-size: 6.5pt; color: #3d4b45; }
        .regno strong { font-size: 7.5pt; color: #17252d; }
        .band { background: #235842; color: #fff; font-weight: bold; font-size: 7pt; letter-spacing: 0.6pt; text-transform: uppercase; text-align: center; padding: 2.5pt 0; margin-top: 9pt; }
        .info { width: 100%; border-collapse: collapse; table-layout: fixed; border: 0.75pt solid #aebdb3; border-top: 0; }
        .info td { vertical-align: top; padding: 3pt 5pt; }
        .info table { width: 100%; border-collapse: collapse; }
        .info .k { width: 38%; text-align: right; color: #52615a; padding: 1pt 4pt 1pt 0; white-space: nowrap; }
        .info .v { font-weight: bold; padding: 1pt 0; }
        .section { font-weight: bold; font-size: 7pt; text-transform: uppercase; letter-spacing: 0.5pt; border-bottom: 0.75pt solid #235842; padding: 8pt 0 1.5pt; margin: 0 0 3pt; color: #235842; }
        .grid { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .grid th { background: #edf3ef; font-size: 6.5pt; text-transform: uppercase; text-align: left; padding: 3pt; border: 0.5pt solid #aebdb3; }
        .grid td { padding: 2.5pt 3pt; border: 0.5pt solid #cfd9d2; vertical-align: top; overflow-wrap: break-word; }
        .grid .num { text-align: right; white-space: nowrap; }
        .grid .total td { font-weight: bold; background: #f6f8f7; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        .summary { width: 60%; margin-left: 40%; border-collapse: collapse; margin-top: 6pt; }
        .summary td { padding: 2.5pt 4pt; border-bottom: 0.5pt solid #cfd9d2; }
        .summary .num { text-align: right; }
        .summary .closing td { font-weight: bold; font-size: 9pt; border-bottom: 1pt solid #17252d; border-top: 1pt solid #17252d; }
        .note { color: #52615a; font-size: 6.5pt; margin: 4pt 0 0; }
        .signatures { width: 100%; margin-top: 28pt; }
        .signature { border-top: 0.75pt solid #17252d; width: 80%; text-align: center; padding-top: 2pt; font-size: 6.5pt; }
        .footer { position: fixed; bottom: -22pt; left: 0; right: 90pt; font-size: 6pt; color: #64716b; }
    </style>
</head>
<body>
@php
    $date = fn (?string $value) => $value === null ? null : \Carbon\Carbon::parse($value)->format('d M Y');
    $period = match (true) {
        $from !== null && $to !== null => $date($from).' – '.$date($to),
        $from !== null => 'From '.$date($from),
        $to !== null => 'Through '.$date($to),
        default => 'All entries',
    };
@endphp
<div class="footer">Statement of Account · Candidate {{ $candidate['candidateNumber'] }} · System-generated from {{ $systemName }} on {{ $generatedAt }} · Confidential</div>

<div class="masthead">
    @if($logo)<img class="logo" src="{{ $logo }}" alt="Organization logo">@endif
    <div class="regno">Statement No.<br><strong>{{ $reference }}</strong></div>
    <p class="organization">{{ $organization }}</p>
    <div class="system">{{ $systemName }}</div>
    <div class="title">Statement of Account</div>
</div>

<div class="band">Candidate Information</div>
<table class="info">
    <tr>
        <td><table>
            <tr><td class="k">Candidate No.:</td><td class="v">{{ $candidate['candidateNumber'] }}</td></tr>
            <tr><td class="k">Name:</td><td class="v">{{ $candidate['name'] }}</td></tr>
            <tr><td class="k">Status:</td><td class="v">{{ $candidate['status']['label'] }}</td></tr>
        </table></td>
        <td><table>
            <tr><td class="k">Class / Section:</td><td class="v">{{ $candidate['classBatch']['name'] ?? 'Not assigned' }}</td></tr>
            <tr><td class="k">Academic Period:</td><td class="v">{{ $candidate['classBatch']['period'] ?? 'Not assigned' }}</td></tr>
            <tr><td class="k">Statement Period:</td><td class="v">{{ $period }}</td></tr>
        </table></td>
    </tr>
</table>

<div class="section">Account Activity</div>
<table class="grid">
    <thead><tr>
        <th style="width:12%">Date</th><th style="width:16%">Category</th><th style="width:26%">Description</th><th style="width:12%">Reference</th>
        <th style="width:11%" class="num">Charges</th><th style="width:11%" class="num">Credits</th><th style="width:12%" class="num">Balance</th>
    </tr></thead>
    <tbody>
    @if($from !== null)
        <tr><td>{{ $date($from) }}</td><td colspan="5">Balance brought forward</td><td class="num">{{ $opening }}</td></tr>
    @endif
    @forelse($entries as $entry)
        <tr>
            <td>{{ $date($entry['postedOn']) }}</td>
            <td>{{ $entry['category'] }}</td>
            <td>{{ $entry['description'] }}</td>
            <td>{{ $entry['reference'] ?? '' }}</td>
            <td class="num">{{ $entry['type']['value'] === 'charge' ? $entry['amountDisplay'] : '' }}</td>
            <td class="num">{{ $entry['type']['value'] === 'credit' ? $entry['amountDisplay'] : '' }}</td>
            <td class="num">{{ $entry['balanceDisplay'] }}</td>
        </tr>
    @empty
        <tr><td colspan="7">No entries in this period.</td></tr>
    @endforelse
    </tbody>
</table>

<table class="summary">
    @if($from !== null)<tr><td>Balance brought forward</td><td class="num">{{ $opening }}</td></tr>@endif
    <tr><td>Total charges</td><td class="num">{{ $charges }}</td></tr>
    <tr><td>Total credits (allowances and payments)</td><td class="num">{{ $credits }}</td></tr>
    <tr class="closing">
        <td>{{ $closingLabel }}</td>
        <td class="num">{{ $closingDisplay }}</td>
    </tr>
</table>
<p class="note">Charges increase the balance; credits (allowances, payments and deposits) reduce it. A credit balance is an amount in the candidate's favor and is shown in parentheses.@if($voidedCount > 0) {{ $voidedCount }} voided {{ $voidedCount === 1 ? 'entry is' : 'entries are' }} not included.@endif This statement is generated from the institution's records and is not an official receipt.</p>

<table class="signatures"><tr>
    <td style="width:50%"><div class="signature">Prepared by: Finance Office</div></td>
    <td style="width:50%"><div class="signature">Received by: Candidate</div></td>
</tr></table>
</body>
</html>
