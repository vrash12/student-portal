{{-- Current subject grades (current academic period only). --}}
<div class="section">Subjects and Current Grades</div>
@if($page['isCurrent'])
    <table class="grid">
        <thead><tr><th style="width:17%">Code</th><th style="width:37%">Subject</th><th style="width:13%" class="num">Grade</th><th style="width:33%">Standing</th></tr></thead>
        <tbody>
        @forelse($academics['subjects'] as $subject)
            <tr><td>{{ $subject['code'] }}</td><td>{{ $subject['name'] }}</td><td class="num">{{ $number($subject['result']['grade']) }}</td><td>{{ $subject['result']['standing']['label'] ?? 'Not available' }}@if($subject['result']['isProvisional']) <span class="muted">(in progress)</span>@endif</td></tr>
        @empty
            <tr><td colspan="4">No subjects assigned.</td></tr>
        @endforelse
        <tr class="total"><td colspan="2">Overall standing</td><td colspan="2">{{ $academics['overall']['standing']['label'] ?? 'Not available' }}@if($academics['overall']['isProvisional']) (provisional)@endif</td></tr>
        </tbody>
    </table>
    <p class="note">Grades use finalized assessments and the configured grading rules; in-progress grades may change.</p>
@else
    <p class="note">Subject grades and standing are calculated for the current class only. The results recorded during this period are listed on this page.</p>
@endif
