<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 36pt 38pt 54pt; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 9pt; color: #17252d; line-height: 1.45; }
        .masthead { position: relative; text-align: center; min-height: 65pt; padding: 0 66pt 12pt; border-bottom: 2pt solid #235842; }
        .logo { position: absolute; left: 0; top: 0; width: 54pt; height: 54pt; }
        .organization { font-size: 14pt; font-weight: bold; margin: 0 0 3pt; }
        .system { font-size: 8pt; color: #58676d; }
        h1 { font-size: 16pt; letter-spacing: 1pt; text-transform: uppercase; margin: 10pt 0 0; }
        .meta { width: 100%; margin: 10pt 0 14pt; font-size: 7.5pt; color: #58676d; }
        .meta td { padding: 0; }
        .right { text-align: right; }
        h2 { font-size: 9pt; text-transform: uppercase; letter-spacing: 0.6pt; background: #edf3ef; border: 1pt solid #cad8cf; padding: 6pt 8pt; margin: 16pt 0 0; page-break-after: avoid; }
        .information { width: 100%; table-layout: fixed; border-collapse: collapse; border: 1pt solid #d6ded9; }
        .information td { width: 50%; padding: 7pt 9pt; vertical-align: top; border-bottom: 0.5pt solid #e5eae7; }
        .label { color: #64716b; font-size: 7.5pt; }
        .value { font-size: 9pt; font-weight: bold; overflow-wrap: break-word; }
        .records { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .records th { text-align: left; font-size: 7.5pt; padding: 6pt; background: #f3f5f4; border-bottom: 1pt solid #aebdb3; }
        .records td { padding: 7pt 6pt; vertical-align: top; border-bottom: 0.5pt solid #d6ded9; font-size: 8pt; overflow-wrap: break-word; }
        .records .numeric { text-align: right; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        .muted { color: #64716b; font-size: 7.5pt; }
        .note { font-size: 8pt; color: #52615a; margin: 8pt 0 12pt; }
        .summary { border: 1pt solid #d6ded9; padding: 10pt; margin-top: 14pt; page-break-inside: avoid; }
        .summary strong { font-size: 11pt; }
        .endnote { margin-top: 18pt; padding-top: 8pt; border-top: 1pt solid #aebdb3; font-size: 7.5pt; color: #52615a; page-break-inside: avoid; }
        .footer { position: fixed; bottom: -31pt; left: 0; right: 0; border-top: 0.5pt solid #cad4cd; padding-top: 5pt; font-size: 7pt; color: #64716b; }
    </style>
</head>
<body>
@php
    $date = fn ($value) => ! $value ? '-' : (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)
        ? \Carbon\Carbon::parse($value)->format('d M Y')
        : \Carbon\Carbon::parse($value)->timezone(config('institution.timezone'))->format('d M Y'));
    $number = fn ($value) => $value === null ? '-' : number_format((float) $value, 2);
@endphp
<div class="footer">{{ $title }} | Candidate {{ $candidate['candidateNumber'] }} | Confidential student record</div>
<div class="masthead">
    @if($logo)<img class="logo" src="{{ $logo }}" alt="Organization logo">@endif
    <p class="organization">{{ $organization }}</p>
    <div class="system">{{ $systemName }}</div>
    <h1>{{ $title }}</h1>
</div>
<table class="meta"><tr><td>Reference: {{ $reference }}</td><td class="right">Generated: {{ $generatedAt }}</td></tr></table>

<h2>Student General Information</h2>
<table class="information">
    <tr><td><div class="label">Candidate Number / ID</div><div class="value">{{ $candidate['candidateNumber'] }}</div></td><td><div class="label">Candidate Status</div><div class="value">{{ $candidate['status']['label'] }}</div></td></tr>
    <tr><td colspan="2" style="width:100%"><div class="label">Full Name</div><div class="value">{{ $candidate['name'] }}</div></td></tr>
    <tr><td><div class="label">Class / Batch</div><div class="value">{{ $candidate['classBatch']['name'] ?? 'Not assigned' }}</div></td><td><div class="label">Academic Period</div><div class="value">{{ $candidate['classBatch']['period'] ?? 'Not assigned' }}</div></td></tr>
    <tr><td><div class="label">Training Group / Section / Platoon</div><div class="value">{{ $candidate['trainingGroup'] ?? 'Not assigned' }}</div></td><td><div class="label">Record Last Updated</div><div class="value">{{ $date($candidate['updatedAt']) }}</div></td></tr>
</table>

@if($type === 'registration')
    <h2>Enrolled Subjects</h2>
    <table class="records">
        <thead><tr><th style="width:19%">Code</th><th style="width:43%">Subject Title</th><th style="width:38%">Assigned Instructor(s)</th></tr></thead>
        <tbody>
        @forelse($subjects as $subject)
            <tr><td>{{ $subject['code'] }}</td><td>{{ $subject['name'] }}</td><td>{{ implode(', ', $subject['instructors']) ?: 'Not assigned' }}</td></tr>
        @empty
            <tr><td colspan="3">No subjects are assigned to the current class.</td></tr>
        @endforelse
        </tbody>
    </table>
    <div class="summary">Total assigned subjects: <strong>{{ count($subjects) }}</strong></div>
    <p class="note">Subjects and instructors follow the candidate's current class assignment. This registration record does not represent payment, fee clearance or completion of training.</p>
@else
    <div class="summary"><div class="label">Current Overall Academic Standing</div><strong>{{ $academics['overall']['standing']['label'] ?? 'Not available' }}</strong>
        @if($academics['overall']['isProvisional'])<div class="muted">Provisional: some subject grades are still in progress.</div>@endif
    </div>
    <h2>Current Subjects and Grades</h2>
    <p class="note">Grades use finalized assessments and the configured grading rules. Current grades may change as more assessments are finalized.</p>
    <table class="records"><thead><tr><th style="width:47%">Subject / Instructor(s)</th><th style="width:17%" class="numeric">Grade</th><th style="width:36%">Standing / Grade Status</th></tr></thead><tbody>
        @forelse($academics['subjects'] as $subject)
            <tr><td><strong>{{ $subject['code'] }} - {{ $subject['name'] }}</strong><div class="muted">{{ implode(', ', $subject['instructors']) ?: 'No instructor assigned' }}</div></td><td class="numeric">{{ $number($subject['result']['grade']) }}</td><td>{{ $subject['result']['standing']['label'] ?? 'Not available' }}<div class="muted">{{ $subject['result']['status']['label'] }}@if($subject['result']['isProvisional']) - Provisional @endif</div></td></tr>
        @empty
            <tr><td colspan="3">No subjects assigned.</td></tr>
        @endforelse
    </tbody></table>

    <h2>Quiz and Examination Results</h2>
    <p class="note">Only released, fully graded results show a score. All attempts are listed separately; exam scores affect subject grades only when posted to the gradebook.</p>
    <table class="records"><thead><tr><th style="width:41%">Assessment</th><th style="width:17%">Submitted</th><th style="width:19%" class="numeric">Score</th><th style="width:23%">Result</th></tr></thead><tbody>
        @forelse($examinations as $exam)
            <tr><td><strong>{{ $exam['title'] }}</strong><div class="muted">{{ $exam['subject'] }} / {{ $exam['className'] }}</div><div class="muted">{{ $exam['kind'] }} - Attempt {{ $exam['attemptNumber'] }} - {{ $exam['status'] }}</div></td><td>{{ $date($exam['submittedAt']) }}</td><td class="numeric">@if($exam['score'] !== null){{ $number($exam['score']) }} / {{ $number($exam['maxScore']) }}<div class="muted">{{ $number($exam['percentage']) }}%</div>@else - @endif</td><td>{{ $exam['passed'] === null ? $exam['resultLabel'] : ($exam['passed'] ? 'Passed' : 'Failed') }}</td></tr>
        @empty
            <tr><td colspan="4">No examination attempts recorded.</td></tr>
        @endforelse
    </tbody></table>

    <h2>Assessment History</h2>
    <p class="note">Finalized assessments in the current class and recorded results from previous classes. Missing scores remain blank and are not treated as zero.</p>
    <table class="records"><thead><tr><th style="width:43%">Assessment / Subject</th><th style="width:18%">Date</th><th style="width:23%" class="numeric">Score</th><th style="width:16%" class="numeric">Percent</th></tr></thead><tbody>
        @forelse($assessments as $assessment)
            <tr><td><strong>{{ $assessment['title'] }}</strong><div class="muted">{{ $assessment['subject'] }} / {{ $assessment['category'] }}</div><div class="muted">{{ $assessment['className'] }}</div></td><td>{{ $date($assessment['date']) }}</td><td class="numeric">@if($assessment['score'] !== null){{ $number($assessment['score']) }} / {{ $number($assessment['maxScore']) }}@else Missing @endif</td><td class="numeric">{{ $assessment['percentage'] === null ? '-' : $number($assessment['percentage']).'%' }}</td></tr>
        @empty
            <tr><td colspan="4">No finalized assessment records.</td></tr>
        @endforelse
    </tbody></table>
@endif

<div class="endnote"><strong>System-generated record.</strong> This document reflects stored information at the time shown above. It is not a signed certification or a final transcript. For corrections or verification, contact the academic office.</div>
</body>
</html>

