<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academic\CampusRequest;
use App\Models\Campus;
use App\Services\CampusService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Academics → Campuses: the institution's four fixed campuses (owner
 * decisions 2026-10-03 and 2026-10-04). They are listed and edited (address,
 * on/off), never added or removed. Route middleware: `can:campuses.manage`
 * and `institution` (accounts not limited to a campus).
 */
class CampusController extends Controller
{
    public function __construct(private readonly CampusService $campuses) {}

    public function index(): Response
    {
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
            ])
            ->values()
            ->all();

        return Inertia::render('staff/campuses/index', ['campuses' => $campuses]);
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
