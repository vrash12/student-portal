<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
    <style>
        /* Letter landscape (792 x 612 pt): one certificate per page. */
        @page { margin: 24pt; }
        body { font-family: "DejaVu Sans", sans-serif; color: #17252d; }
        .sheet { border: 4pt solid #235842; padding: 5pt; height: 542pt; page-break-after: always; }
        .sheet.last { page-break-after: auto; }
        .inner { position: relative; border: 1pt solid #b08d2e; height: 496pt; padding: 22pt 46pt; text-align: center; }
        .logo { width: 62pt; height: 62pt; }
        .organization { font-size: 13pt; font-weight: bold; letter-spacing: 1.4pt; text-transform: uppercase; margin-top: 6pt; }
        .course { font-size: 8.5pt; color: #52615a; letter-spacing: 0.8pt; text-transform: uppercase; margin-top: 2pt; }
        .title { font-family: "DejaVu Serif", serif; font-size: 27pt; font-weight: bold; color: #235842; letter-spacing: 2pt; text-transform: uppercase; margin-top: 16pt; }
        .rule { width: 120pt; margin: 6pt auto 0; border-top: 1.5pt solid #b08d2e; }
        .lead { font-size: 10pt; color: #3d4b45; margin-top: 14pt; font-style: italic; }
        .name { font-family: "DejaVu Serif", serif; font-size: 25pt; font-weight: bold; margin-top: 8pt; }
        .name-rule { width: 360pt; margin: 3pt auto 0; border-top: 0.75pt solid #17252d; }
        .meta { font-size: 8.5pt; color: #52615a; margin-top: 4pt; }
        .statement { font-size: 10.5pt; line-height: 1.55; margin: 12pt auto 0; width: 610pt; }
        .statement strong { color: #235842; }
        .given { font-size: 9.5pt; margin-top: 10pt; }
        .signatures { position: absolute; left: 46pt; right: 46pt; bottom: 54pt; width: 608pt; border-collapse: collapse; table-layout: fixed; }
        .signatures td { text-align: center; vertical-align: bottom; padding: 0 22pt; }
        .signatures .line { border-top: 0.75pt solid #17252d; padding-top: 2pt; font-size: 9pt; font-weight: bold; }
        .signatures .role { font-size: 7.5pt; color: #52615a; }
        .reference { position: absolute; left: 12pt; bottom: 8pt; font-size: 6.5pt; color: #64716b; text-align: left; }
    </style>
</head>
<body>
@php
    $number = fn ($value) => number_format((float) $value, 2);
    $ordinal = function (int $value): string {
        $suffix = in_array($value % 100, [11, 12, 13], true) ? 'th' : (['th', 'st', 'nd', 'rd'][$value % 10] ?? 'th');

        return $value.$suffix;
    };
@endphp
@foreach($certificates as $index => $certificate)
<div class="sheet{{ $loop->last ? ' last' : '' }}">
    <div class="inner">
        @if($logo)<img class="logo" src="{{ $logo }}" alt="Organization logo">@endif
        <div class="organization">{{ $organization }}</div>
        <div class="course">{{ $courseName }}</div>

        <div class="title">{{ $title }}</div>
        <div class="rule"></div>

        <div class="lead">This certifies that</div>
        <div class="name">{{ $certificate['name'] }}</div>
        <div class="name-rule"></div>
        <div class="meta">
            Candidate No. {{ $certificate['candidateNumber'] }}
            @if($certificate['className']) · {{ $certificate['className'] }}@endif
            @if($certificate['campus']) · {{ $certificate['campus'] }}@endif
        </div>

        <div class="statement">
            has satisfactorily completed the <strong>{{ $courseName }}</strong>@if($certificate['period']) for the Academic Year {{ $certificate['period'] }}@endif,
            with a Final Course Grade of <strong>{{ $number($certificate['finalGrade']) }}</strong>
            and a Cumulative General Point Average of <strong>{{ $number($certificate['cgpa']) }}</strong>@if($certificate['rank'] !== null),
            ranking <strong>{{ $ordinal($certificate['rank']) }}</strong> in a class of {{ $certificate['classSize'] }}@endif.
        </div>

        <div class="given">Given this {{ $ordinal($issuedDay) }} day of {{ $issuedMonthYear }}.</div>

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

        <div class="reference">Ref. {{ $certificate['reference'] }} · {{ $systemName }}</div>
    </div>
</div>
@endforeach
</body>
</html>
