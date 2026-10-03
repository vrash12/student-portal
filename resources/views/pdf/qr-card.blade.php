<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>QR Code · {{ $candidateNumber }}</title>
    <style>
        @page { margin: 36pt; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 9pt; color: #17252d; }
        .card { width: 270pt; margin: 0 auto; border: 1pt solid #235842; padding: 14pt 16pt 16pt; text-align: center; }
        .logo { width: 44pt; height: 44pt; }
        .organization { font-size: 10pt; font-weight: bold; margin: 4pt 0 0; }
        .system { font-size: 6.5pt; color: #58676d; margin-top: 1pt; }
        .label { margin-top: 8pt; background: #235842; color: #fff; font-size: 7pt; font-weight: bold; letter-spacing: 0.8pt; text-transform: uppercase; padding: 3pt 0; }
        .qr { width: 210pt; height: 210pt; margin-top: 10pt; }
        .name { font-size: 12pt; font-weight: bold; margin-top: 6pt; }
        .number { font-size: 10pt; margin-top: 2pt; }
        .class { font-size: 8pt; color: #52615a; margin-top: 2pt; }
        .note { font-size: 6.5pt; color: #52615a; margin-top: 10pt; }
        .footer { text-align: center; font-size: 6pt; color: #64716b; margin-top: 18pt; }
    </style>
</head>
<body>
<div class="card">
    @if($logo)<img class="logo" src="{{ $logo }}" alt="Organization logo">@endif
    <p class="organization">{{ $organization }}</p>
    <div class="system">{{ $systemName }}</div>
    <div class="label">Attendance QR Code</div>
    <img class="qr" src="{{ $qr }}" alt="QR code">
    <div class="name">{{ $name }}</div>
    <div class="number">Candidate {{ $candidateNumber }}</div>
    @if($className || $campusName)<div class="class">{{ implode(' · ', array_filter([$className, $campusName])) }}</div>@endif
    <div class="note">For attendance only. Do not share a picture of this code. A lost card can be replaced; the old code then stops working.</div>
</div>
<div class="footer">System-generated from {{ $systemName }} on {{ $generatedAt }}</div>
</body>
</html>
