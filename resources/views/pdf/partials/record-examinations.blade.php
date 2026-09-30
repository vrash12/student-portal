{{-- Quiz and examination attempts of one academic period. --}}
<div class="section">Quiz and Examination Results</div>
<table class="grid">
    <thead><tr><th style="width:40%">Assessment / Subject</th><th style="width:10%" class="num">Att.</th><th style="width:16%">Submitted</th><th style="width:18%" class="num">Score</th><th style="width:16%">Result</th></tr></thead>
    <tbody>
    @forelse($page['examinations'] as $exam)
        <tr><td>{{ $exam['title'] }} <span class="muted">· {{ $exam['subject'] }} · {{ $exam['kind'] }}</span></td><td class="num">{{ $exam['attemptNumber'] }}</td><td>{{ $date($exam['submittedAt']) }}</td><td class="num">@if($exam['score'] !== null){{ $number($exam['score']) }} / {{ $number($exam['maxScore']) }}@else - @endif</td><td>{{ $exam['passed'] === null ? $exam['resultLabel'] : ($exam['passed'] ? 'Passed' : 'Failed') }}</td></tr>
    @empty
        <tr><td colspan="5">No quiz or examination attempts in this period.</td></tr>
    @endforelse
    </tbody>
</table>
<p class="note">Only released, fully graded results show a score.</p>
