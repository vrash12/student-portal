<?php

namespace App\Http\Controllers\Staff;

use App\Enums\MedicalFieldType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Medical\MedicalFieldRequest;
use App\Models\MedicalField;
use App\Services\Medical\MedicalRecordService;
use App\Support\MedicalRecordPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Medical record fields (route middleware: medical.configure). Fields in use
 * are deactivated, never deleted.
 */
class MedicalFieldController extends Controller
{
    public function __construct(private readonly MedicalRecordService $medical) {}

    public function index(): Response
    {
        return Inertia::render('staff/medical/fields/index', [
            'fields' => MedicalField::query()->withCount('values')->ordered()->get()
                ->map(fn (MedicalField $field): array => [
                    ...MedicalRecordPresenter::field($field),
                    'valueCount' => (int) $field->values_count,
                ])
                ->values()
                ->all(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('staff/medical/fields/create', [
            'types' => MedicalFieldType::options(),
            'nextSortOrder' => min(999, (int) MedicalField::query()->max('sort_order') + 1),
        ]);
    }

    public function store(MedicalFieldRequest $request): RedirectResponse
    {
        $data = $request->fieldData();
        unset($data['is_active']);
        $field = $this->medical->createField($data, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Field {$field->name} added to the medical record."]);

        return redirect()->route('medical.fields.index');
    }

    public function edit(MedicalField $medicalField): Response
    {
        return Inertia::render('staff/medical/fields/edit', [
            'field' => [...MedicalRecordPresenter::field($medicalField), 'valueCount' => $medicalField->values()->count()],
            'types' => MedicalFieldType::options(),
        ]);
    }

    public function update(MedicalFieldRequest $request, MedicalField $medicalField): RedirectResponse
    {
        $field = $this->medical->updateField($medicalField, $request->fieldData(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Field {$field->name} updated."]);

        return redirect()->route('medical.fields.index');
    }

    public function destroy(Request $request, MedicalField $medicalField): RedirectResponse
    {
        $name = $medicalField->name;
        $this->medical->deleteField($medicalField, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Field {$name} deleted."]);

        return redirect()->route('medical.fields.index');
    }
}
