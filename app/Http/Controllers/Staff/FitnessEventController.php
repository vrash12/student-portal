<?php

namespace App\Http\Controllers\Staff;

use App\Enums\FitnessScoringMethod;
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
 * Fitness events, their points tables or standards, and passing points
 * (route middleware: fitness.configure).
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
            ...$this->formOptions(),
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
            ...$this->formOptions(),
        ]);
    }

    public function update(FitnessEventRequest $request, FitnessEvent $fitnessEvent): RedirectResponse
    {
        $this->standards->update($fitnessEvent, $request->eventData());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Fitness event {$fitnessEvent->name} updated."]);

        return redirect()->route('fitness.standards.index');
    }

    /**
     * @return array{unitOptions: list<array{value: string, label: string}>, methodOptions: list<array{value: string, label: string, description: string}>, maximumRows: int}
     */
    private function formOptions(): array
    {
        return [
            'unitOptions' => FitnessUnit::options(),
            'methodOptions' => FitnessScoringMethod::options(),
            'maximumRows' => FitnessEventRequest::MAXIMUM_ROWS,
        ];
    }

    /**
     * The event and its standard; points and displays come from
     * FitnessStandard.
     *
     * @return array<string, mixed>
     */
    private function present(FitnessEvent $event): array
    {
        $standard = $event->standard();

        return [
            'id' => $event->id,
            'name' => $event->name,
            'description' => $event->description,
            'unit' => ['value' => $event->unit->value, 'label' => $event->unit->label()],
            'higherIsBetter' => $event->higher_is_better,
            'method' => ['value' => $standard->method->value, 'label' => $standard->method->label()],
            'passingPoints' => $standard->passingPoints,
            'maximumPoints' => $standard->maximumPoints(),
            'passingDisplay' => FitnessValue::format($standard->passingValue, $event->unit),
            'maximumDisplay' => FitnessValue::format($standard->maximumValue, $event->unit),
            'table' => $standard->toArray()['table'],
            'sortOrder' => $event->sort_order,
            'isActive' => $event->is_active,
        ];
    }
}
