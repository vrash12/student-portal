<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>QR Cards · {{ $className }}</title>
    <style>
        @page { margin: 30pt 32pt 40pt; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 8pt; color: #17252d; }
        .heading { font-size: 9pt; margin-bottom: 6pt; }
        .heading strong { font-size: 11pt; }
        .heading span { color: #52615a; }
        table.sheet { width: 100%; border-collapse: collapse; table-layout: fixed; }
        table.sheet td { width: 50%; height: 188pt; border: 1pt dashed #9aa7a0; padding: 6pt 8pt; text-align: center; vertical-align: top; }
        .page { page-break-after: always; }
        .page:last-child { page-break-after: auto; }
        .top { height: 22pt; }
        .logo { width: 20pt; height: 20pt; vertical-align: middle; }
        .organization { display: inline-block; vertical-align: middle; font-size: 7pt; font-weight: bold; max-width: 190pt; text-align: left; margin-left: 4pt; }
        .label { margin-top: 3pt; background: #235842; color: #fff; font-size: 6pt; font-weight: bold; letter-spacing: 0.6pt; text-transform: uppercase; padding: 2pt 0; }
        .qr { width: 118pt; height: 118pt; margin-top: 4pt; }
        .name { font-size: 9.5pt; font-weight: bold; margin-top: 3pt; }
        .number { font-size: 8pt; margin-top: 1pt; }
        .class { font-size: 6.5pt; color: #52615a; margin-top: 1pt; }
        .empty { margin-top: 40pt; text-align: center; font-size: 10pt; color: #52615a; }
        .footer { font-size: 6pt; color: #64716b; margin-top: 6pt; }
    </style>
</head>
<body>
@forelse($cards as $page)
    <div class="page">
        <div class="heading">
            <strong>QR Attendance Cards · {{ $className }}</strong>
            <span>{{ implode(' · ', array_filter([$periodName, $campusName])) }} · {{ $count }} {{ $count === 1 ? 'candidate' : 'candidates' }} · page {{ $loop->iteration }} of {{ $loop->count }}</span>
        </div>
        <table class="sheet">
            @foreach($page as $row)
                <tr>
                    @foreach($row as $card)
                        <td>
                            <div class="top">
                                @if($logo)<img class="logo" src="{{ $logo }}" alt="">@endif
                                <span class="organization">{{ $organization }}</span>
                            </div>
                            <div class="label">Attendance QR Code</div>
                            <img class="qr" src="{{ $card['qr'] }}" alt="QR code">
                            <div class="name">{{ $card['name'] }}</div>
                            <div class="number">Candidate {{ $card['candidateNumber'] }}</div>
                            <div class="class">{{ implode(' · ', array_filter([$className, $campusName])) }}</div>
                        </td>
                    @endforeach
                    @if(count($row) === 1)<td></td>@endif
                </tr>
            @endforeach
        </table>
        <div class="footer">Cut along the dashed lines. For attendance only; a lost card can be replaced and the old code then stops working. System-generated from {{ $systemName }} on {{ $generatedAt }}.</div>
    </div>
@empty
    <div class="heading"><strong>QR Attendance Cards · {{ $className }}</strong></div>
    <p class="empty">This class has no candidates with a QR code yet.</p>
@endforelse
</body>
</html>
