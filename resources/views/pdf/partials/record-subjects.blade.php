{{-- Current subject grades by training phase, with each phase's average and the CGPA (current academic period only). --}}
<div class="section">Subjects and Current Grades</div>
@if($page['isCurrent'])
    @php
        $course = $academics['course'];
        // Phase headings and averages only once subjects are placed in phases.
        $phased = collect($course['phases'])->contains(fn (array $group): bool => $group['phase'] !== null);
        $bySubject = collect($academics['subjects'])->keyBy('id');
        $inProgress = fn (?float $grade, bool $complete): string => $grade !== null && ! $complete ? 'in progress' : '';
    @endphp
    <table class="grid">
        <thead><tr><th style="width:15%">Code</th><th style="width:35%">Subject</th><th style="width:10%" class="num">Units</th><th style="width:12%" class="num">Grade</th><th style="width:28%">Standing</th></tr></thead>
        <tbody>
        @forelse($course['phases'] as $group)
            @if($phased)
                <tr class="phase"><td colspan="5">{{ $group['phase']['name'] ?? 'Not in a phase' }}</td></tr>
            @endif
            @foreach($group['subjects'] as $item)
                @php($subject = $bySubject[$item['classSubjectId']])
                <tr><td>{{ $subject['code'] }}</td><td>{{ $subject['name'] }}</td><td class="num">{{ $subject['units'] }}</td><td class="num">{{ $number($subject['result']['grade']) }}</td><td>{{ $subject['result']['standing']['label'] ?? 'Not available' }}@if($subject['result']['isProvisional']) <span class="muted">(in progress)</span>@endif</td></tr>
            @endforeach
            @if($phased)
                <tr class="total"><td colspan="3">{{ $group['phase']['name'] ?? 'Not in a phase' }} average</td><td class="num">{{ $number($group['average']) }}</td><td><span class="muted">{{ $inProgress($group['average'], $group['complete']) }}</span></td></tr>
            @endif
        @empty
            <tr><td colspan="5">No subjects assigned.</td></tr>
        @endforelse
        <tr class="total"><td colspan="3">Cumulative General Point Average (CGPA)</td><td class="num">{{ $number($course['cgpa']['grade']) }}</td><td><span class="muted">{{ $inProgress($course['cgpa']['grade'], $course['cgpa']['complete']) }}</span></td></tr>
        <tr class="total"><td colspan="3">Overall standing</td><td colspan="2">{{ $academics['overall']['standing']['label'] ?? 'Not available' }}@if($academics['overall']['isProvisional']) (provisional)@endif</td></tr>
        </tbody>
    </table>
    <p class="note">Grades use finalized assessments and the configured grading rules; in-progress grades may change. Phase averages and the CGPA are weighted by units; subjects without a grade are left out.</p>
@else
    <p class="note">Subject grades and standing are calculated for the current class only. The results recorded during this period are listed on this page.</p>
@endif
