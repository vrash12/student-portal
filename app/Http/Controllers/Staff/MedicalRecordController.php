<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Medical\MedicalRecordRequest;
use App\Models\Candidate;
use App\Models\CandidateMedicalRevision;
use App\Models\ClassBatch;
use App\Models\MedicalField;
use App\Services\Medical\MedicalRecordService;
use App\Support\MedicalRecordPresenter;
use App\Support\QueryFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Candidate medical records (owner request, 2026-10-02): the list of
 * candidates with how much of their record is filled (medical.view), and the
 * record form with its change history (CandidatePolicy::manageMedical).
 */
class MedicalRecordController extends Controller
{
    private const PER_PAGE = 10;

    private const HISTORY_LIMIT = 50;

    public function __construct(private readonly MedicalRecordService $medical) {}

    public function index(Request $request): Response
    {
        $filters = [
            'search' => QueryFilters::search($request),
            'class' => QueryFilters::id($request, 'class'),
        ];
        $activeFieldIds = MedicalField::query()->active()->pluck('id')->all();

        $candidates = Candidate::query()
            ->with('classBatch:id,name')
            ->when($filters['search'] !== '', fn (Builder $query) => $query->matching($filters['search']))
            ->when($filters['class'] !== '', fn (Builder $query) => $query->where('class_batch_id', (int) $filters['class']))
            ->withCount(['medicalValues as recorded_count' => fn (Builder $values) => $values->whereIn('medical_field_id', $activeFieldIds)])
            ->withMax('medicalValues as medical_updated_at', 'updated_at')
            ->orderBy('candidate_number')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('staff/medical/index', [
            'candidates' => $candidates->through(fn (Candidate $candidate): array => [
                'id' => $candidate->id,
                'number' => $candidate->candidate_number,
                'name' => $candidate->full_name,
                'className' => $candidate->classBatch?->name,
                'recorded' => (int) $candidate->recorded_count,
                'updatedAt' => $candidate->medical_updated_at === null ? null : (string) $candidate->medical_updated_at,
            ]),
            'fieldCount' => count($activeFieldIds),
            'filters' => $filters,
            'classes' => ClassBatch::query()->orderBy('name')->get(['id', 'name'])->map(fn (ClassBatch $class): array => ['id' => $class->id, 'name' => $class->name])->all(),
            'can' => [
                'configure' => $request->user()->can('medical.configure'),
                'manage' => $request->user()->can('medical.manage'),
            ],
        ]);
    }

    public function edit(Candidate $candidate): Response
    {
        $candidate->loadMissing('classBatch:id,name');
        $fields = MedicalField::query()->active()->ordered()->get();
        $values = MedicalRecordPresenter::values($candidate, $fields);

        $history = CandidateMedicalRevision::query()
            ->where('candidate_id', $candidate->id)
            ->with(['field:id,name,field_type', 'changer:id,name'])
            ->latest('id')
            ->limit(self::HISTORY_LIMIT)
            ->get();

        return Inertia::render('staff/medical/edit', [
            'candidate' => [
                'id' => $candidate->id,
                'number' => $candidate->candidate_number,
                'name' => $candidate->full_name,
                'className' => $candidate->classBatch?->name,
            ],
            'fields' => $fields->map(fn (MedicalField $field): array => [
                ...MedicalRecordPresenter::field($field),
                'value' => $values->get($field->id)?->value,
            ])->values()->all(),
            'history' => $history->map(fn (CandidateMedicalRevision $revision): array => [
                'id' => $revision->id,
                'field' => $revision->field->name,
                'type' => $revision->field->field_type->value,
                'previousValue' => $revision->previous_value,
                'newValue' => $revision->new_value,
                'changedBy' => $revision->changer->name,
                'changedAt' => $revision->created_at?->toIso8601String(),
            ])->all(),
        ]);
    }

    public function update(MedicalRecordRequest $request, Candidate $candidate): RedirectResponse
    {
        $changed = $this->medical->saveRecord($candidate, $request->values(), $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $changed === 0 ? 'No changes to save.' : ($changed === 1 ? 'Medical record saved: 1 field changed.' : "Medical record saved: {$changed} fields changed."),
        ]);

        return redirect()->route('medical.records.edit', $candidate);
    }
}
