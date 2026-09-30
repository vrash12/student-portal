{{-- Finalized assessments of one academic period. --}}
<div class="section">Assessment Results</div>
<table class="grid">
    <thead><tr><th style="width:15%">Date</th><th style="width:42%">Assessment / Subject</th><th style="width:17%">Category</th><th style="width:15%" class="num">Score</th><th style="width:11%" class="num">%</th></tr></thead>
    <tbody>
    @forelse($page['assessments'] as $assessment)
        <tr><td>{{ $date($assessment['date']) }}</td><td>{{ $assessment['title'] }} <span class="muted">· {{ $assessment['subject'] }}</span></td><td>{{ $assessment['category'] }}</td><td class="num">@if($assessment['score'] !== null){{ $number($assessment['score']) }} / {{ $number($assessment['maxScore']) }}@else Missing @endif</td><td class="num">{{ $assessment['percentage'] === null ? '-' : $number($assessment['percentage']) }}</td></tr>
    @empty
        <tr><td colspan="5">No finalized assessments in this period.</td></tr>
    @endforelse
    </tbody>
</table>
<p class="note">Missing scores are left blank and never counted as zero.</p>
