<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
    <style>
        /*
         * One side per page at the standard ID size (CR80, 153 x 243 pt, portrait).
         * Army look (owner request 2026-10-05): camouflage bands, a topographic
         * background, olive drab, black and brass. Same positions as
         * resources/js/components/candidates/candidate-id-card.tsx.
         */
        @page { margin: 0; }
        html, body { margin: 0; padding: 0; }
        body { font-family: "DejaVu Sans", sans-serif; color: #1b1f14; }
        .side { position: relative; width: 153pt; height: 243pt; overflow: hidden; page-break-after: always; background: #efe9d6; }
        .side.last { page-break-after: auto; }
        .abs { position: absolute; left: 0; width: 153pt; text-align: center; }
        /* The SVG artwork is not clipped by the PDF renderer: the box clips it. */
        .art { position: absolute; left: 0; width: 153pt; overflow: hidden; }
        .brass { background: #c9a13b; }
        .black { background: #15180f; }
        .logo { position: absolute; top: 6pt; left: 60.5pt; width: 32pt; height: 32pt; }
        .organization { top: 41pt; left: 6pt; width: 141pt; color: #ffffff; font-size: 5.8pt; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5pt; line-height: 7pt; }
        .photo { position: absolute; top: 75pt; left: 40pt; width: 70pt; height: 82pt; border: 1.5pt solid #2b3320; background: #dcd5bd; }
        .photo img { width: 70pt; height: 82pt; }
        .photo .none { padding-top: 36pt; font-size: 5.5pt; color: #5d6347; text-transform: uppercase; letter-spacing: 0.6pt; }
        .bracket { position: absolute; width: 8pt; height: 8pt; border: 0 solid #c9a13b; }
        .bracket.dark { width: 8pt; height: 8pt; border-color: #2b3320; }
        .last-name { font-weight: bold; text-transform: uppercase; letter-spacing: 0.6pt; color: #1b1f14; }
        .given { color: #3a4030; }
        .rank-band { position: absolute; left: 0; top: 190pt; width: 153pt; height: 10.4pt; background: #15180f; border-top: 0.8pt solid #c9a13b; border-bottom: 0.8pt solid #c9a13b; }
        .rank { top: 192.7pt; color: #e2bd4f; font-size: 5.8pt; font-weight: bold; text-transform: uppercase; letter-spacing: 1.6pt; line-height: 7pt; }
        .label { font-size: 4.4pt; color: #5d6347; text-transform: uppercase; letter-spacing: 1pt; font-weight: bold; }
        .serial { font-family: "DejaVu Sans Mono", monospace; font-weight: bold; letter-spacing: 1pt; color: #1b1f14; }
        .meta { color: #3a4030; }
        .valid { color: #e2bd4f; font-size: 5.6pt; font-weight: bold; letter-spacing: 1pt; text-transform: uppercase; }
        .back-title { color: #ffffff; font-size: 5.4pt; font-weight: bold; text-transform: uppercase; letter-spacing: 0.8pt; line-height: 6.6pt; }
        .values { color: #e2bd4f; font-size: 4.4pt; font-weight: bold; text-transform: uppercase; letter-spacing: 1pt; line-height: 5.4pt; }
        .heading { font-size: 5.2pt; font-weight: bold; color: #4b5320; text-transform: uppercase; letter-spacing: 1.4pt; }
        .qr-frame { position: absolute; top: 47pt; left: 35.5pt; width: 80pt; height: 80pt; border: 1pt solid #2b3320; background: #ffffff; }
        .qr { position: absolute; top: 51pt; left: 39.5pt; width: 74pt; height: 74pt; }
        .emergency-band { position: absolute; left: 0; top: 146pt; width: 153pt; height: 10pt; background: #15180f; }
        .emergency { top: 148.2pt; color: #e2bd4f; font-size: 5pt; font-weight: bold; text-transform: uppercase; letter-spacing: 1.4pt; line-height: 6pt; }
        .emergency-value { font-size: 6.2pt; color: #1b1f14; line-height: 8pt; }
        .blank { display: inline-block; width: 100pt; border-bottom: 0.5pt solid #5d6347; height: 7pt; }
        .notice { left: 9pt; width: 135pt; font-size: 4.8pt; color: #3a4030; line-height: 6.2pt; }
        .signature { position: absolute; top: 212pt; left: 31.5pt; width: 90pt; border-top: 0.6pt solid #1b1f14; padding-top: 1.5pt; font-size: 4.6pt; color: #5d6347; text-align: center; text-transform: uppercase; letter-spacing: 0.8pt; font-weight: bold; }
        .printed { color: #d8cfae; font-size: 4.2pt; letter-spacing: 0.3pt; }
    </style>
</head>
<body>
@foreach($cards as $card)
    {{-- Front --}}
    <div class="side">
        <div class="art" style="top: 67.5pt; height: 159pt;"><img src="{{ $artwork['frontTerrain'] }}" alt="" style="width: 153pt; height: 159pt;"></div>
        <div class="art" style="top: 0; height: 64pt;"><img src="{{ $artwork['frontHeader'] }}" alt="" style="width: 153pt; height: 64pt;"></div>
        @if($logo)<img class="logo" src="{{ $logo }}" alt="Logo">@endif
        <div class="abs organization">{{ $card['organization'] }}</div>
        <div class="abs brass" style="top: 64pt; height: 2.5pt;"></div>
        <div class="abs black" style="top: 66.5pt; height: 1pt;"></div>

        <div class="bracket" style="top: 72pt; left: 37pt; border-top-width: 1.5pt; border-left-width: 1.5pt;"></div>
        <div class="bracket" style="top: 72pt; left: 106.5pt; border-top-width: 1.5pt; border-right-width: 1.5pt;"></div>
        <div class="bracket" style="top: 153.5pt; left: 37pt; border-bottom-width: 1.5pt; border-left-width: 1.5pt;"></div>
        <div class="bracket" style="top: 153.5pt; left: 106.5pt; border-bottom-width: 1.5pt; border-right-width: 1.5pt;"></div>
        <div class="photo">
            @if($card['photo'])<img src="{{ $card['photo'] }}" alt="Picture">@else<div class="none">No picture</div>@endif
        </div>

        <div class="abs last-name" style="top: 165pt; left: 4pt; width: 145pt; font-size: {{ $card['textSizes']['lastName'] }}pt;">{{ $card['lastName'] }}</div>
        <div class="abs given" style="top: 178pt; left: 4pt; width: 145pt; font-size: {{ $card['textSizes']['givenNames'] }}pt;">{{ $card['givenNames'] }}</div>
        <div class="rank-band"></div>
        <div class="abs rank">&#9733; {{ $card['roleLabel'] }} &#9733;</div>

        <div class="abs label" style="top: 202.5pt;">Serial No.</div>
        <div class="abs serial" style="top: 207pt; font-size: 9pt;">{{ $card['number'] }}</div>
        <div class="abs meta" style="top: 218pt; left: 4pt; width: 145pt; font-size: {{ $card['textSizes']['meta'] }}pt;">{{ implode(' · ', array_filter([$card['className'], $card['campusName']])) }}</div>

        <div class="abs brass" style="top: 226.5pt; height: 1pt;"></div>
        <div class="art" style="top: 227.5pt; height: 15.5pt;"><img src="{{ $artwork['frontFooter'] }}" alt="" style="width: 153pt; height: 15.5pt;"></div>
        <div class="abs valid" style="top: 232.5pt;">{{ $card['validUntil'] ? 'Valid until '.$card['validUntil'] : 'Validity not set' }}</div>
    </div>

    {{-- Back --}}
    <div class="side {{ $loop->last ? 'last' : '' }}">
        <div class="art" style="top: 32.8pt; height: 194pt;"><img src="{{ $artwork['backTerrain'] }}" alt="" style="width: 153pt; height: 194pt;"></div>
        <div class="art" style="top: 0; height: 30pt;"><img src="{{ $artwork['backHeader'] }}" alt="" style="width: 153pt; height: 30pt;"></div>
        {{-- The organization is named on the front; the back says what the card is. --}}
        <div class="abs back-title" style="top: {{ $card['coreValues'] ? 8 : 11.7 }}pt;">Official Identification Card</div>
        @if($card['coreValues'])<div class="abs values" style="top: 18pt;">{{ implode(' · ', $card['coreValues']) }}</div>@endif
        <div class="abs brass" style="top: 30pt; height: 2pt;"></div>
        <div class="abs black" style="top: 32pt; height: 0.8pt;"></div>

        <div class="abs heading" style="top: 36.3pt;">Attendance QR Code</div>
        <div class="bracket dark" style="top: 44pt; left: 32.5pt; border-top-width: 1.2pt; border-left-width: 1.2pt;"></div>
        <div class="bracket dark" style="top: 44pt; left: 111.3pt; border-top-width: 1.2pt; border-right-width: 1.2pt;"></div>
        <div class="bracket dark" style="top: 122.8pt; left: 32.5pt; border-bottom-width: 1.2pt; border-left-width: 1.2pt;"></div>
        <div class="bracket dark" style="top: 122.8pt; left: 111.3pt; border-bottom-width: 1.2pt; border-right-width: 1.2pt;"></div>
        <div class="qr-frame"></div>
        <img class="qr" src="{{ $card['qr'] }}" alt="QR code">
        <div class="abs serial" style="top: 134pt; font-size: 7.5pt;">{{ $card['number'] }}</div>

        <div class="emergency-band"></div>
        <div class="abs emergency">In Case of Emergency</div>
        @if($card['emergencyContact'])
            <div class="abs emergency-value" style="top: 159pt; left: 6pt; width: 141pt;">
                {{ $card['emergencyContact']['name'] }}@if($card['emergencyContact']['relationship']) ({{ $card['emergencyContact']['relationship'] }})@endif
                @if($card['emergencyContact']['phone'])<br><strong>{{ $card['emergencyContact']['phone'] }}</strong>@endif
            </div>
        @else
            <div class="abs emergency-value" style="top: 160pt;"><span class="blank"></span><br><span class="blank"></span></div>
        @endif

        <div class="abs notice" style="top: 179pt;">
            Property of the school. Carry at all times and present on request.
            If found, please return it to {{ $card['campusName'] ?: $card['organization'] }}{{ $card['campusAddress'] ? ', '.$card['campusAddress'] : '' }}.
        </div>

        <div class="signature">Authorized Signature</div>
        <div class="abs brass" style="top: 226.5pt; height: 1pt;"></div>
        <div class="art" style="top: 227.5pt; height: 15.5pt;"><img src="{{ $artwork['backFooter'] }}" alt="" style="width: 153pt; height: 15.5pt;"></div>
        <div class="abs printed" style="top: 233.5pt;">Printed {{ $printedOn }} · {{ $card['systemName'] }}</div>
    </div>
@endforeach
</body>
</html>
