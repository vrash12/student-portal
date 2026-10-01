<?php

namespace App\Http\Controllers\Staff;

use App\Enums\ConductKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Conduct\ConductTypeRequest;
use App\Models\ConductType;
use App\Services\Conduct\ConductService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Merit and demerit types (route middleware: performance.configure). Types
 * in use are deactivated, never deleted.
 */
class ConductTypeController extends Controller
{
    public function __construct(private readonly ConductService $conduct) {}

    public function index(): Response
    {
        return Inertia::render('staff/conduct/types/index', [
            'types' => ConductType::query()->withCount('entries')->ordered()->get()
                ->map(fn (ConductType $type): array => [
                    ...$this->present($type),
                    'entryCount' => (int) $type->entries_count,
                ])
                ->values()
                ->all(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('staff/conduct/types/create', [
            'kinds' => ConductKind::options(),
            'nextSortOrder' => min(999, (int) ConductType::query()->max('sort_order') + 1),
        ]);
    }

    public function store(ConductTypeRequest $request): RedirectResponse
    {
        $data = $request->typeData();
        unset($data['is_active']);
        $type = $this->conduct->createType($data);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$type->kind->label()} type {$type->name} created."]);

        return redirect()->route('conduct.types.index');
    }

    public function edit(ConductType $conductType): Response
    {
        return Inertia::render('staff/conduct/types/edit', [
            'type' => [...$this->present($conductType), 'entryCount' => $conductType->entries()->count()],
            'kinds' => ConductKind::options(),
        ]);
    }

    public function update(ConductTypeRequest $request, ConductType $conductType): RedirectResponse
    {
        $type = $this->conduct->updateType($conductType, $request->typeData());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Type {$type->name} updated."]);

        return redirect()->route('conduct.types.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ConductType $type): array
    {
        return [
            'id' => $type->id,
            'name' => $type->name,
            'kind' => $type->kind->toArray(),
            'defaultPoints' => $type->default_points,
            'description' => $type->description,
            'sortOrder' => $type->sort_order,
            'isActive' => $type->is_active,
        ];
    }
}
