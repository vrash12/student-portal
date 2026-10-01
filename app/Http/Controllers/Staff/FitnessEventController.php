<?php

namespace App\Http\Controllers\Staff;

use App\Enums\FitnessUnit;
use App\Http\Controllers\Controller;
use App\Http\Requests\Fitness\FitnessEventRequest;
use App\Models\FitnessEvent;
use App\Services\Fitness\FitnessStandardService;
use App\Services\Fitness\FitnessValue;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Fitness events and their standards (route middleware: fitness.manage).
 */
class FitnessEventController extends Controller
{
    public function __construct(private readonly FitnessStandardService $standards) {}

    public function index(): Response
    {
        $events = FitnessEvent::query()
            ->withCount('testEvents')
            ->ordered()
            ->get()
            ->map(fn (FitnessEvent $event): array => [
                ...$this->present($event),
                'testCount' => (int) $event->test_events_count,
            ])
            ->all();

        return Inertia::render('staff/fitness/standards/index', ['events' => $events]);
    }

    public function create(): Response
    {
        return Inertia::render('staff/fitness/standards/create', [
            'unitOptions' => FitnessUnit::options(),
            'nextSortOrder' => (int) FitnessEvent::query()->max('sort_order') + 1,
        ]);
    }

    public function store(FitnessEventRequest $request): RedirectResponse
    {
        $data = $request->eventData();
        unset($data['is_active']);
        $event = $this->standards->create($data);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Fitness event {$event->name} created."]);

        return redirect()->route('fitness.standards.index');
    }

    public function edit(FitnessEvent $fitnessEvent): Response
    {
        return Inertia::render('staff/fitness/standards/edit', [
            'event' => [
                ...$this->present($fitnessEvent),
                'testCount' => $fitnessEvent->testEvents()->count(),
            ],
            'unitOptions' => FitnessUnit::options(),
        ]);
    }

    public function update(FitnessEventRequest $request, FitnessEvent $fitnessEvent): RedirectResponse
    {
        $this->standards->update($fitnessEvent, $request->eventData());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Fitness event {$fitnessEvent->name} updated."]);

        return redirect()->route('fitness.standards.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(FitnessEvent $event): array
    {
        $passing = (float) $event->passing_value;
        $maximum = (float) $event->maximum_value;

        return [
            'id' => $event->id,
            'name' => $event->name,
            'description' => $event->description,
            'unit' => ['value' => $event->unit->value, 'label' => $event->unit->label()],
            'higherIsBetter' => $event->higher_is_better,
            'passingDisplay' => FitnessValue::format($passing, $event->unit),
            'maximumDisplay' => FitnessValue::format($maximum, $event->unit),
            'sortOrder' => $event->sort_order,
            'isActive' => $event->is_active,
        ];
    }
}
