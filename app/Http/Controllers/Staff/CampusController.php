<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academic\CampusRequest;
use App\Models\Campus;
use App\Services\CampusAnalytics;
use App\Services\CampusService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Academics → Campuses: the institution's four fixed campuses (owner
 * decisions 2026-10-03 and 2026-10-04). They are listed and edited (address,
 * on/off), never added or removed. The list compares the campuses and each
 * campus has an Analytics page (owner request 2026-10-05; CampusAnalytics). Route middleware: `can:campuses.manage`
 * and `institution` (accounts not limited to a campus).
 */
class CampusController extends Controller
{
    public function __construct(
        private readonly CampusService $campuses,
        private readonly CampusAnalytics $analytics,
    ) {}

    public function index(): Response
    {
        $period = $this->analytics->activePeriod();
        $campuses = Campus::query()
            ->withCount([
                'classBatches',
                'candidates',
                'users as staff_count' => fn (Builder $users) => $users->where('is_active', true),
            ])
            ->get()
            ->sortBy(fn (Campus $campus): int => $campus->position())
            ->map(fn (Campus $campus): array => [
                ...$this->present($campus),
                'classCount' => (int) $campus->class_batches_count,
                'candidateCount' => (int) $campus->candidates_count,
                'staffCount' => (int) $campus->staff_count,
                // Key figures of the active academic year, compared on the page.
                'figures' => $this->analytics->summary($campus, $period),
            ])
            ->values()
            ->all();

        return Inertia::render('staff/campuses/index', [
            'campuses' => $campuses,
            'period' => $period === null ? null : ['id' => $period->id, 'name' => $period->name],
        ]);
    }

    /** A campus's Analytics page: the active academic year at that campus. */
    public function show(Campus $campus): Response
    {
        $period = $this->analytics->activePeriod();

        return Inertia::render('staff/campuses/show', [
            'campus' => $this->present($campus),
            'period' => $period === null ? null : ['id' => $period->id, 'name' => $period->name],
            'analytics' => $this->analytics->details($campus, $period),
        ]);
    }

    public function edit(Campus $campus): Response
    {
        return Inertia::render('staff/campuses/edit', ['campus' => $this->present($campus)]);
    }

    public function update(CampusRequest $request, Campus $campus): RedirectResponse
    {
        $updated = $this->campuses->update($campus, $request->campusData());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Campus {$updated->name} updated."]);

        return redirect()->route('campuses.index');
    }

    /**
     * @return array{id: int, name: string, code: string, address: ?string, isActive: bool}
     */
    private function present(Campus $campus): array
    {
        return [
            'id' => $campus->id,
            'name' => $campus->name,
            'code' => $campus->code,
            'address' => $campus->address,
            'isActive' => $campus->is_active,
        ];
    }
}
