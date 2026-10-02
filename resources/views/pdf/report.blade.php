<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 24pt 28pt 32pt; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: {{ $orientation === 'landscape' ? '7.5pt' : '8pt' }}; color: #17252d; line-height: 1.3; }
        .masthead { position: relative; text-align: center; min-height: 46pt; padding-bottom: 2pt; }
        .logo { position: absolute; left: 0; top: 0; width: 42pt; height: 42pt; }
        .organization { font-size: 11.5pt; font-weight: bold; margin: 2pt 0 0; }
        .system { font-size: 6.5pt; color: #58676d; margin-top: 1pt; }
        .title { font-size: 10.5pt; font-weight: bold; letter-spacing: 0.8pt; text-transform: uppercase; margin-top: 4pt; line-height: 1.2; }
        .subtitle { font-size: 7.5pt; color: #3d4b45; margin-top: 2pt; }
        .ref { position: absolute; right: 0; top: 4pt; text-align: right; font-size: 6.5pt; color: #3d4b45; }
        .ref strong { font-size: 7.5pt; color: #17252d; }
        .band { background: #235842; color: #fff; height: 2pt; margin: 8pt 0 6pt; }
        .meta { width: 100%; border-collapse: collapse; margin-bottom: 4pt; }
        .meta td { padding: 1pt 6pt 1pt 0; vertical-align: top; }
        .meta .k { color: #52615a; white-space: nowrap; width: 1%; }
        .meta .v { font-weight: bold; }
        .section { font-weight: bold; font-size: 7.5pt; text-transform: uppercase; letter-spacing: 0.5pt; border-bottom: 0.75pt solid #235842; padding: 8pt 0 1.5pt; margin: 0 0 3pt; color: #235842; }
        .grid { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .grid th { background: #edf3ef; font-size: 6.5pt; text-transform: uppercase; text-align: left; padding: 2.5pt 3pt; border: 0.5pt solid #aebdb3; vertical-align: bottom; }
        .grid th .rule { display: block; font-weight: normal; text-transform: none; color: #52615a; }
        .grid td { padding: 2pt 3pt; border: 0.5pt solid #cfd9d2; vertical-align: top; overflow-wrap: break-word; word-wrap: break-word; }
        .grid .num { text-align: right; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        .fields { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .fields td { vertical-align: top; padding: 2pt 8pt 4pt 0; }
        .fields .k { display: block; color: #52615a; font-size: 6.5pt; text-transform: uppercase; letter-spacing: 0.3pt; }
        .fields .v { display: block; font-weight: bold; }
        .text { margin: 2pt 0 4pt; }
        .note { color: #52615a; font-size: 6.5pt; margin: 3pt 0 0; }
        .alert { border: 0.75pt solid #b9862b; background: #fbf4e4; padding: 4pt 6pt; margin: 4pt 0; }
        .keep { page-break-inside: avoid; }
        .page-break { page-break-before: always; }
        .muted { color: #64716b; }
        .footer { position: fixed; bottom: -22pt; left: 0; right: 110pt; font-size: 6pt; color: #64716b; }
    </style>
</head>
<body>
<div class="footer">{{ $title }} · System-generated from {{ $systemName }} on {{ $generatedAt }} by {{ $generatedBy }} · Confidential</div>

<div class="masthead">
    @if($logo)<img class="logo" src="{{ $logo }}" alt="Organization logo">@endif
    @if($reference)<div class="ref">Reference<br><strong>{{ $reference }}</strong></div>@endif
    <p class="organization">{{ $organization }}</p>
    <div class="system">{{ $systemName }}</div>
    <div class="title">{{ $title }}</div>
    @if($subtitle)<div class="subtitle">{{ $subtitle }}</div>@endif
</div>
<div class="band"></div>

@if($meta !== [])
    <table class="meta">
        @foreach(array_chunk($meta, 2) as $pair)
            <tr>
                @foreach($pair as [$label, $value])
                    <td class="k">{{ $label }}:</td><td class="v">{{ $value }}</td>
                @endforeach
            </tr>
        @endforeach
    </table>
@endif

@foreach($sections as $section)
    <div class="{{ ($section['breakBefore'] ?? false) ? 'page-break' : '' }} {{ ($section['keep'] ?? false) ? 'keep' : '' }}">
        @if(($section['heading'] ?? null) !== null)<div class="section">{{ $section['heading'] }}</div>@endif

        @switch($section['type'])
            @case('table')
                <table class="grid">
                    <thead><tr>
                        @foreach($section['columns'] as $column)
                            <th @if(isset($column['width'])) style="width: {{ $column['width'] }}" @endif class="{{ ($column['numeric'] ?? false) ? 'num' : '' }}">{{ $column['label'] }}@if(($column['rule'] ?? null) !== null)<span class="rule">{{ $column['rule'] }}</span>@endif</th>
                        @endforeach
                    </tr></thead>
                    <tbody>
                    @forelse($section['rows'] as $row)
                        <tr>
                            @foreach($section['columns'] as $index => $column)
                                <td class="{{ ($column['numeric'] ?? false) ? 'num' : '' }}">{!! nl2br(e((string) ($row[$index] ?? ''))) !!}</td>
                            @endforeach
                        </tr>
                    @empty
                        <tr><td colspan="{{ count($section['columns']) }}">{{ $section['empty'] ?? 'No records.' }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
                @break
            @case('fields')
                @php($perRow = $section['perRow'] ?? 2)
                <table class="fields">
                    @foreach(array_chunk($section['fields'], $perRow) as $fieldRow)
                        <tr>
                            @foreach($fieldRow as [$label, $value])
                                <td style="width: {{ round(100 / $perRow, 2) }}%"><span class="k">{{ $label }}</span><span class="v">{!! nl2br(e((string) $value)) !!}</span></td>
                            @endforeach
                            @for($filler = count($fieldRow); $filler < $perRow; $filler++)<td></td>@endfor
                        </tr>
                    @endforeach
                </table>
                @break
            @case('text')
                <p class="text">{!! nl2br(e($section['text'])) !!}</p>
                @break
            @case('alert')
                <div class="alert">{{ $section['text'] }}</div>
                @break
        @endswitch

        @if(($section['note'] ?? null) !== null)<p class="note">{{ $section['note'] }}</p>@endif
    </div>
@endforeach
</body>
</html>
