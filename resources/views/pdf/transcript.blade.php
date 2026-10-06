<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
    <style>
        /* Letter portrait (612 x 792 pt). */
        @page { margin: 30pt 34pt 40pt; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 7.6pt; color: #17252d; line-height: 1.3; }
        .masthead { position: relative; text-align: center; min-height: 52pt; }
        .logo { position: absolute; left: 0; top: 0; width: 48pt; height: 48pt; }
        .organization { font-size: 12pt; font-weight: bold; margin: 3pt 0 0; }
        .system { font-size: 7pt; color: #58676d; margin-top: 1pt; }
        .title { font-size: 12pt; font-weight: bold; letter-spacing: 1.2pt; text-transform: uppercase; margin-top: 6pt; }
        .regno { position: absolute; right: 0; top: 4pt; text-align: right; font-size: 6.5pt; color: #3d4b45; }
        .regno strong { font-size: 7.5pt; color: #17252d; }
        .provisional { margin-top: 7pt; border: 1pt solid #8a6d00; background: #fff8dc; color: #66520a; text-align: center; font-weight: bold; font-size: 7.5pt; padding: 3pt; letter-spacing: 0.5pt; }
        .band { background: #235842; color: #fff; font-weight: bold; font-size: 7.4pt; letter-spacing: 0.6pt; text-transform: uppercase; padding: 3pt 6pt; margin-top: 10pt; }
        .info { width: 100%; border-collapse: collapse; table-layout: fixed; border: 0.75pt solid #aebdb3; border-top: 0; }
        .info td { padding: 2.5pt 6pt; vertical-align: top; }
        .info .k { width: 17%; color: #52615a; white-space: nowrap; }
        .info .v { width: 33%; font-weight: bold; }
        .grid { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .grid th { background: #edf3ef; font-size: 6.6pt; text-transform: uppercase; text-align: left; padding: 3pt 4pt; border: 0.5pt solid #aebdb3; }
        .grid td { padding: 2.5pt 4pt; border: 0.5pt solid #cfd9d2; vertical-align: top; overflow-wrap: break-word; }
        .grid .num { text-align: right; white-space: nowrap; }
        .grid .center { text-align: center; }
        .grid .phase td { font-weight: bold; color: #235842; background: #edf3ef; text-transform: uppercase; letter-spacing: 0.4pt; font-size: 6.8pt; }
        .grid .average td { font-weight: bold; background: #f6f8f7; }
        .grid .total td { font-weight: bold; background: #e3ede6; font-size: 8pt; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        .failed { color: #9b1c1c; font-weight: bold; }
        .summary { width: 100%; border-collapse: collapse; table-layout: fixed; margin-top: 6pt; }
        .summary td { border: 0.75pt solid #aebdb3; padding: 5pt 6pt; text-align: center; vertical-align: top; }
        .summary .label { font-size: 6.4pt; text-transform: uppercase; letter-spacing: 0.5pt; color: #52615a; }
        .summary .value { font-size: 13pt; font-weight: bold; margin-top: 2pt; }
        .summary .hint { font-size: 6.4pt; color: #64716b; margin-top: 1pt; }
        .note { color: #52615a; font-size: 6.6pt; margin: 4pt 0 0; }
        .certify { margin-top: 14pt; font-size: 7.4pt; }
        .signatures { width: 100%; border-collapse: collapse; table-layout: fixed; margin-top: 26pt; page-break-inside: avoid; }
        .signatures td { text-align: center; vertical-align: bottom; padding: 0 14pt; }
        .signatures .line { border-top: 0.75pt solid #17252d; padding-top: 2pt; font-weight: bold; min-height: 10pt; }
        .signatures .role { font-size: 6.6pt; color: #52615a; }
        .footer { position: fixed; bottom: -26pt; left: 0; right: 120pt; font-size: 6pt; color: #64716b; }
    </style>
</head>
<body>
@php
    $number = fn ($value) => $value === null ? '—' : number_format((float) $value, 2);
    $ordinal = function (int $value): string {
        $suffix = in_array($value % 100, [11, 12, 13], true) ? 'th' : (['th', 'st', 'nd', 'rd'][$value % 10] ?? 'th');

        return $value.$suffix;
    };
    $phased = array_filter($phases, fn (array $phase): bool => $phase['phase'] !== null) !== [];
    // "Phase 1 — Basic Training", or just the name when it already says which phase it is.
    $phaseLabel = fn (array $phase): string => str_starts_with(strtolower($phase['name']), 'phase') ? $phase['name'] : 'Phase '.$phase['number'].' — '.$phase['name'];
@endphp
<div class="footer">{{ $title }} · Candidate {{ $candidate['candidateNumber'] }} · Ref. {{ $reference }} · Issued {{ $generatedAt }} from {{ $systemName }} · Not valid without an authorized signature</div>

<div class="masthead">
    @if($logo)<img class="logo" src="{{ $logo }}" alt="Organization logo">@endif
    <div class="regno">Reference No.<br><strong>{{ $reference }}</strong></div>
    <p class="organization">{{ $organization }}</p>
    <div class="system">{{ $courseName }}</div>
    <div class="title">{{ $title }}</div>
</div>

@unless($summary['transcriptFinal'])
    <div class="provisional">PROVISIONAL — THE COURSE IS NOT YET COMPLETE FOR THIS CANDIDATE. GRADES MAY STILL CHANGE.</div>
@endunless

<div class="band">Candidate</div>
<table class="info">
    <tr>
        <td class="k">Name</td><td class="v">{{ $candidate['name'] }}</td>
        <td class="k">Candidate No.</td><td class="v">{{ $candidate['candidateNumber'] }}</td>
    </tr>
    <tr>
        <td class="k">Class</td><td class="v">{{ $candidate['classBatch']['name'] ?? '—' }}</td>
        <td class="k">Campus</td><td class="v">{{ $candidate['campus']['name'] ?? '—' }}</td>
    </tr>
    <tr>
        <td class="k">Academic year</td><td class="v">{{ $period['name'] ?? '—' }}</td>
        <td class="k">Course dates</td><td class="v">{{ $period === null ? '—' : $period['startsOn'].' – '.$period['endsOn'] }}</td>
    </tr>
    <tr>
        <td class="k">Company / Platoon</td><td class="v">{{ trim(($candidate['company'] ?? '').(($candidate['company'] ?? null) && ($candidate['platoon'] ?? null) ? ' / ' : '').($candidate['platoon'] ?? '')) ?: '—' }}</td>
        <td class="k">Status</td><td class="v">{{ $candidate['status']['label'] }}</td>
    </tr>
</table>

<div class="band">Academic Record</div>
<table class="grid">
    <thead>
        <tr>
            <th style="width: 14%">Code</th>
            <th>Subject</th>
            <th class="num" style="width: 9%">Units</th>
            <th class="num" style="width: 13%">Final Grade</th>
            <th style="width: 15%">Remarks</th>
        </tr>
    </thead>
    <tbody>
        @forelse($phases as $phase)
            @if($phased)
                <tr class="phase"><td colspan="5">{{ $phase['phase'] === null ? 'Other subjects' : $phaseLabel($phase['phase']) }}</td></tr>
            @endif
            @foreach($phase['subjects'] as $subject)
                <tr>
                    <td>{{ $subject['code'] }}</td>
                    <td>{{ $subject['name'] }}</td>
                    <td class="num">{{ $subject['units'] }}</td>
                    <td class="num">{{ $number($subject['grade']) }}</td>
                    <td class="{{ $subject['remark'] === 'Failed' ? 'failed' : '' }}">{{ $subject['remark'] }}</td>
                </tr>
            @endforeach
            @if($phased)
                <tr class="average">
                    <td colspan="2">{{ $phase['phase'] === null ? 'Average' : $phaseLabel($phase['phase']).' average' }}{{ $phase['complete'] ? '' : ' (in progress)' }}</td>
                    <td class="num">{{ $phase['units'] }}</td>
                    <td class="num">{{ $number($phase['average']) }}</td>
                    <td></td>
                </tr>
            @endif
        @empty
            <tr><td colspan="5">No subjects recorded for this candidate's class.</td></tr>
        @endforelse
        <tr class="total">
            <td colspan="2">Cumulative General Point Average (CGPA){{ $summary['cgpa']['complete'] ? '' : ' — in progress' }}</td>
            <td class="num">{{ rtrim(rtrim(number_format($totalUnits, 2), '0'), '.') }}</td>
            <td class="num">{{ $number($summary['cgpa']['grade']) }}</td>
            <td></td>
        </tr>
    </tbody>
</table>
<p class="note">Grades are on a 0–100 scale{{ $passingGrade === null ? '' : '; the passing grade is '.number_format($passingGrade, 2) }}. Phase averages and the CGPA are weighted by subject units.</p>

@if($areas !== [])
    <div class="band">Performance Areas</div>
    <table class="grid">
        <thead>
            <tr>
                <th>Area</th>
                <th class="num" style="width: 14%">Share of Grade</th>
                <th class="num" style="width: 13%">Grade</th>
                <th style="width: 15%">Result</th>
            </tr>
        </thead>
        <tbody>
            @foreach($areas as $area)
                <tr>
                    <td>{{ $area['name'] }}{{ $area['mustPass'] ? ' (must pass)' : '' }}</td>
                    <td class="num">{{ $area['share'] === null ? '—' : number_format($area['share'], 2).'%' }}</td>
                    <td class="num">{{ $number($area['grade']) }}</td>
                    <td class="{{ $area['status'] === 'Not met' ? 'failed' : '' }}">{{ $area['status'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

<table class="summary">
    <tr>
        <td>
            <div class="label">CGPA</div>
            <div class="value">{{ $number($summary['cgpa']['grade']) }}</div>
            <div class="hint">{{ $summary['cgpa']['gradedSubjects'] }} of {{ $summary['cgpa']['totalSubjects'] }} subjects graded</div>
        </td>
        <td>
            <div class="label">Final Course Grade</div>
            <div class="value">{{ $number($summary['finalGrade']['score']) }}</div>
            <div class="hint">{{ $summary['finalGrade']['complete'] ? 'Weighted performance areas' : 'Partial: some areas not yet graded' }}</div>
        </td>
        <td>
            <div class="label">Qualification</div>
            <div class="value" style="font-size: 10pt; margin-top: 4pt;">{{ $summary['qualification']['status']['label'] ?? '—' }}</div>
        </td>
        <td>
            <div class="label">Class Rank</div>
            <div class="value">{{ $summary['rank'] === null ? '—' : $ordinal($summary['rank']) }}</div>
            <div class="hint">{{ $summary['rank'] === null ? 'Not ranked' : 'of '.$summary['classSize'].' in the class' }}</div>
        </td>
    </tr>
</table>

<p class="certify">This is to certify that the above is a true and correct record of the grades of {{ $candidate['name'] }} in the {{ $courseName }} as kept by the {{ $organization }}. Issued on {{ $issuedOn }}.</p>

@if($signatories !== [])
    <table class="signatures">
        <tr>
            @foreach($signatories as $signatory)
                <td>
                    <div class="line">{{ $signatory['name'] ?? '' }}&nbsp;</div>
                    <div class="role">{{ $signatory['title'] }}</div>
                </td>
            @endforeach
        </tr>
    </table>
@endif
</body>
</html>
