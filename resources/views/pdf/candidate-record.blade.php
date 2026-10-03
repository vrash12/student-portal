<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
    <style>
        /* Letter landscape (792 x 612 pt), compact like the printed registration form. */
        @page { margin: 22pt 26pt 30pt; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 7pt; color: #17252d; line-height: 1.25; }
        .page-break { page-break-before: always; }
        .masthead { position: relative; text-align: center; min-height: 46pt; padding-bottom: 2pt; }
        .logo { position: absolute; left: 0; top: 0; width: 42pt; height: 42pt; }
        .organization { font-size: 11.5pt; font-weight: bold; margin: 2pt 0 0; }
        .system { font-size: 6.5pt; color: #58676d; margin-top: 1pt; }
        .title { font-size: 10.5pt; font-weight: bold; letter-spacing: 0.8pt; text-transform: uppercase; margin-top: 4pt; line-height: 1.2; }
        .regno { position: absolute; right: 0; top: 4pt; text-align: right; font-size: 6.5pt; color: #3d4b45; }
        .regno strong { font-size: 7.5pt; color: #17252d; }
        .band { background: #235842; color: #fff; font-weight: bold; font-size: 7pt; letter-spacing: 0.6pt; text-transform: uppercase; text-align: center; padding: 2.5pt 0; margin-top: 9pt; }
        .info { width: 100%; border-collapse: collapse; table-layout: fixed; border: 0.75pt solid #aebdb3; border-top: 0; }
        .info td { vertical-align: top; padding: 3pt 5pt; }
        .info table { width: 100%; border-collapse: collapse; }
        .info .k { width: 32%; text-align: right; color: #52615a; padding: 1pt 4pt 1pt 0; white-space: nowrap; }
        .info .v { font-weight: bold; padding: 1pt 0; }
        .section { font-weight: bold; font-size: 7pt; text-transform: uppercase; letter-spacing: 0.5pt; border-bottom: 0.75pt solid #235842; padding: 5pt 0 1.5pt; margin: 0 0 2pt; color: #235842; }
        .grid { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .grid th { background: #edf3ef; font-size: 6.3pt; text-transform: uppercase; text-align: left; padding: 2.5pt 3pt; border: 0.5pt solid #aebdb3; }
        .grid td { padding: 2pt 3pt; border: 0.5pt solid #cfd9d2; vertical-align: top; overflow-wrap: break-word; }
        .grid .num { text-align: right; white-space: nowrap; }
        .grid .total td { font-weight: bold; background: #f6f8f7; }
        .grid .phase td { font-weight: bold; color: #235842; background: #edf3ef; text-transform: uppercase; letter-spacing: 0.4pt; font-size: 6.3pt; }
        .grid .sign { height: 13pt; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        .cols { width: 100%; border-collapse: collapse; table-layout: fixed; margin-top: 2pt; }
        .cols > tbody > tr > td { vertical-align: top; padding: 0; }
        .gap { width: 12pt; }
        .muted { color: #64716b; }
        .note { color: #52615a; font-size: 6.3pt; margin: 2pt 0 0; }
        .box { border: 0.75pt solid #aebdb3; padding: 5pt 6pt; margin-top: 6pt; }
        .box h3 { font-size: 7pt; text-transform: uppercase; letter-spacing: 0.5pt; margin: 0 0 3pt; }
        .signature { margin-top: 18pt; border-top: 0.75pt solid #17252d; width: 70%; text-align: center; padding-top: 2pt; font-size: 6.5pt; }
        .standing { font-size: 8pt; font-weight: bold; }
        .footer { position: fixed; bottom: -20pt; left: 0; right: 110pt; font-size: 6pt; color: #64716b; }
    </style>
</head>
<body>
@php
    $date = fn ($value) => ! $value ? '-' : (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)
        ? \Carbon\Carbon::parse($value)->format('d M Y')
        : \Carbon\Carbon::parse($value)->timezone(config('institution.timezone'))->format('d M Y'));
    $number = fn ($value) => $value === null ? '-' : number_format((float) $value, 2);
    $range = fn (?array $period) => $period === null || ! $period['startsOn'] ? '-' : $date($period['startsOn']).' – '.$date($period['endsOn']);
@endphp
<div class="footer">{{ $title }} · Candidate {{ $candidate['candidateNumber'] }} · System-generated from {{ $systemName }} on {{ $generatedAt }} · Confidential student record</div>

@php
    // One page per semester for the academic record; the registration form is one page.
    $pages = $type === 'registration' ? [['period' => $period, 'isCurrent' => true]] : $periods;
    if ($pages === []) {
        $pages = [['period' => null, 'isCurrent' => false, 'assessments' => [], 'examinations' => [], 'twoColumns' => true]];
    }
@endphp

@foreach($pages as $index => $page)
<div class="{{ $index > 0 ? 'page-break' : '' }}">
    <div class="masthead">
        @if($logo)<img class="logo" src="{{ $logo }}" alt="Organization logo">@endif
        <div class="regno">{{ $type === 'registration' ? 'Registration No.' : 'Record No.' }}<br><strong>{{ $reference }}</strong></div>
        <p class="organization">{{ $organization }}</p>
        <div class="system">{{ $systemName }}</div>
        <div class="title">{{ $title }}</div>
    </div>

    <div class="band">Student General Information</div>
    <table class="info">
        <tr>
            <td><table>
                <tr><td class="k">Candidate No.:</td><td class="v">{{ $candidate['candidateNumber'] }}</td></tr>
                <tr><td class="k">Name:</td><td class="v">{{ $candidate['name'] }}</td></tr>
                <tr><td class="k">Status:</td><td class="v">{{ $candidate['status']['label'] }}</td></tr>
            </table></td>
            <td><table>
                <tr><td class="k">Class / Section:</td><td class="v">{{ $candidate['classBatch']['name'] ?? 'Not assigned' }}</td></tr>
                <tr><td class="k">Training Group:</td><td class="v">{{ $candidate['trainingGroup'] ?? 'Not assigned' }}</td></tr>
                @php($unit = implode(' / ', array_filter([$candidate['company'] ?? null, $candidate['platoon'] ?? null])))
                <tr><td class="k">Company / Platoon:</td><td class="v">{{ $unit !== '' ? $unit : 'Not assigned' }}</td></tr>
                <tr><td class="k">Record Updated:</td><td class="v">{{ $date($candidate['updatedAt']) }}</td></tr>
            </table></td>
            <td><table>
                <tr><td class="k">Academic Period:</td><td class="v">{{ $page['period']['name'] ?? 'Not assigned' }}@if($type === 'academic' && ! $page['isCurrent'] && $page['period'] !== null) <span class="muted">(previous)</span>@endif</td></tr>
                <tr><td class="k">Period Dates:</td><td class="v">{{ $range($page['period']) }}</td></tr>
                <tr><td class="k">Generated:</td><td class="v">{{ $generatedAt }}</td></tr>
            </table></td>
        </tr>
    </table>

@if($type === 'registration')
    @php($personal = $background['personal'])
    @php($service = $background['service'])
    @php($shown = fn ($value) => $value === null || $value === '' ? '-' : $value)
    <div class="band">Personal Background</div>
    <table class="info">
        <tr>
            <td><table>
                <tr><td class="k">Date of Birth:</td><td class="v">{{ $date($personal['dateOfBirth']) }}@if($personal['age'] !== null) <span class="muted">({{ $personal['age'] }} yrs)</span>@endif</td></tr>
                <tr><td class="k">Place of Birth:</td><td class="v">{{ $shown($personal['placeOfBirth']) }}</td></tr>
                <tr><td class="k">Sex / Civil Status:</td><td class="v">{{ $shown($personal['sex']) }} / {{ $shown($personal['civilStatus']) }}</td></tr>
                <tr><td class="k">Home Address:</td><td class="v">{{ $shown($personal['homeAddress']) }}</td></tr>
            </table></td>
            <td><table>
                <tr><td class="k">Mobile Number:</td><td class="v">{{ $shown($personal['mobileNumber']) }}</td></tr>
                <tr><td class="k">Personal Email:</td><td class="v">{{ $shown($personal['personalEmail']) }}</td></tr>
                <tr><td class="k">Emergency Contact:</td><td class="v">{{ $shown($personal['emergencyContact']['name']) }}@if($personal['emergencyContact']['relationship']) <span class="muted">({{ $personal['emergencyContact']['relationship'] }})</span>@endif</td></tr>
                <tr><td class="k">Emergency Phone:</td><td class="v">{{ $shown($personal['emergencyContact']['phone']) }}</td></tr>
            </table></td>
            <td><table>
                <tr><td class="k">Eligibility:</td><td class="v">{{ $shown($service['eligibility']) }}</td></tr>
                <tr><td class="k">Prior Service:</td><td class="v">{{ $shown($service['priorService']) }}</td></tr>
                <tr><td class="k">Previous Occupation:</td><td class="v">{{ $shown($service['previousOccupation']) }}</td></tr>
            </table></td>
        </tr>
    </table>

    <div class="section">Educational Background</div>
    <table class="grid">
        <thead><tr><th style="width:18%">Level</th><th style="width:30%">Degree / Course</th><th style="width:30%">School</th><th style="width:8%">Year</th><th style="width:14%">Honors</th></tr></thead>
        <tbody>
        @forelse($background['education'] as $entry)
            <tr><td>{{ $entry['levelLabel'] }}</td><td>{{ $entry['degree'] }}</td><td>{{ $entry['school'] }}</td><td class="num">{{ $entry['yearGraduated'] ?? '-' }}</td><td>{{ $entry['honors'] ?? '-' }}</td></tr>
        @empty
            <tr><td colspan="5">No education recorded.</td></tr>
        @endforelse
        </tbody>
    </table>

    <div class="section">Enrolled Subjects</div>
    <table class="grid">
        <thead><tr><th style="width:4%">No.</th><th style="width:12%">Code</th><th style="width:30%">Subject Title</th><th style="width:15%">Class / Section</th><th style="width:21%">Instructor(s)</th><th style="width:18%">Instructor's Signature</th></tr></thead>
        <tbody>
        @forelse($subjects as $i => $subject)
            <tr><td class="num">{{ $i + 1 }}</td><td>{{ $subject['code'] }}</td><td>{{ $subject['name'] }}</td><td>{{ $candidate['classBatch']['name'] ?? '-' }}</td><td>{{ implode(', ', $subject['instructors']) ?: 'Not assigned' }}</td><td class="sign"></td></tr>
        @empty
            <tr><td colspan="6">No subjects are assigned to the current class.</td></tr>
        @endforelse
        <tr class="total"><td colspan="2">Total</td><td colspan="4">{{ count($subjects) }} {{ count($subjects) === 1 ? 'subject' : 'subjects' }}</td></tr>
        </tbody>
    </table>
    <p class="note">Subjects and instructors follow the candidate's current class assignment for the academic period shown. This record does not represent payment, fee clearance, or completion of training.</p>

    <table class="cols"><tr>
        <td style="width:50%">
            <div class="box">
                <h3>Registration Summary</h3>
                <table class="grid">
                    <tr><td style="width:45%">Academic period</td><td>{{ $period['name'] ?? 'Not assigned' }}</td></tr>
                    <tr><td>Class / section</td><td>{{ $candidate['classBatch']['name'] ?? 'Not assigned' }}</td></tr>
                    <tr><td>Enrolled subjects</td><td>{{ count($subjects) }}</td></tr>
                    <tr><td>Candidate status</td><td>{{ $candidate['status']['label'] }}</td></tr>
                </table>
            </div>
        </td>
        <td class="gap"></td>
        <td style="width:50%">
            <div class="box">
                <h3>Candidate's Acknowledgement</h3>
                <p class="note">I acknowledge the subjects and class assignment listed above for the academic period shown, and I agree to abide by the rules and regulations of {{ $organization }}.</p>
                <table style="width:100%"><tr>
                    <td style="width:50%"><div class="signature">Candidate's Signature</div></td>
                    <td style="width:50%"><div class="signature">Approved by: Academic Office</div></td>
                </tr></table>
            </div>
        </td>
    </tr></table>
@else
    @if($page['twoColumns'])
        <table class="cols"><tr>
            <td style="width:52%">@include('pdf.partials.record-subjects')@include('pdf.partials.record-examinations')</td>
            <td class="gap"></td>
            <td style="width:48%">@include('pdf.partials.record-assessments')</td>
        </tr></table>
    @else
        @include('pdf.partials.record-subjects')
        @include('pdf.partials.record-examinations')
        @include('pdf.partials.record-assessments')
    @endif
@endif
</div>
@endforeach
</body>
</html>
