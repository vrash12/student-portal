<?php

namespace App\Http\Controllers\Staff;

use App\Enums\ActivityLevel;
use App\Enums\NutritionGoal;
use App\Enums\NutritionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Nutrition\DietaryProfileRequest;
use App\Http\Requests\Nutrition\NutritionAssessmentRequest;
use App\Models\Candidate;
use App\Models\ClassBatch;
use App\Models\NutritionAssessment;
use App\Models\NutritionStandards;
use App\Services\Medical\MedicalRecordService;
use App\Services\Nutrition\NutritionMonitoring;
use App\Services\Nutrition\NutritionService;
use App\Support\ListCharts;
use App\Support\MedicalRecordPresenter;
use App\Support\NutritionPresenter;
use App\Support\QueryFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Nutrition monitoring (owner request, 2026-10-05): the candidates of the
 * viewer's campus with their latest assessment and who needs attention
 * (nutrition.view), each candidate's nutrition record, and the dietitian's
 * assessments and dietary profile (CandidatePolicy::manageNutrition).
 */
class NutritionController extends Controller
{
    private const PER_PAGE = 10;

    /** List filters beside the BMI categories. */
    private const SHOW_FILTERS = ['attention', 'not_assessed', 'review_due', 'waist_risk'];

    public function __construct(private readonly NutritionService $nutrition) {}

    public function index(Request $request): Response
    {
        $campus = $request->user()->campusScope()->filteredBy($request);
        $filters = [
            'search' => QueryFilters::search($request),
            'campus' => $campus->filterValue(),
            'class' => QueryFilters::id($request, 'class'),
            'show' => QueryFilters::oneOf($request, 'show', [...self::SHOW_FILTERS, ...array_column(NutritionStatus::options(), 'value')]),
        ];

        $monitoring = new NutritionMonitoring;
        $rows = $monitoring->rows(
            $campus->constrain(Candidate::query(), 'candidates.campus_id')
                ->when($filters['search'] !== '', fn (Builder $query) => $query->matching($filters['search']))
                ->when($filters['class'] !== '', fn (Builder $query) => $query->where('class_batch_id', (int) $filters['class'])),
        );
        $counts = $monitoring->counts($rows);
        $shown = $rows->filter(fn (array $row): bool => match ($filters['show']) {
            '' => true,
            'attention' => $row['needsAttention'],
            'not_assessed' => $row['assessedOn'] === null,
            'review_due' => $row['reviewDue'],
            'waist_risk' => $row['waistAtRisk'],
            default => ($row['status']['value'] ?? null) === $filters['show'],
        })->values();

        $page = LengthAwarePaginator::resolveCurrentPage();
        $paginator = (new LengthAwarePaginator($shown->forPage($page, self::PER_PAGE)->values(), $shown->count(), self::PER_PAGE, $page, [
            'path' => $request->url(),
            'query' => $request->query(),
        ]));

        return Inertia::render('staff/nutrition/index', [
            'candidates' => $paginator,
            'counts' => $counts,
            'charts' => $counts['total'] === 0 ? [] : [
                ListCharts::pie('BMI Categories', 'Latest assessment of each candidate.', [
                    ...array_map(fn (NutritionStatus $status): array => [
                        'label' => $status->label(), 'value' => $counts['byStatus'][$status->value], 'tone' => self::chartTone($status),
                    ], NutritionStatus::cases()),
                    ['label' => 'Not assessed', 'value' => $counts['notAssessed'], 'tone' => 'incomplete'],
                ], 'candidate', 'candidates'),
            ],
            'standards' => $monitoring->standards()->toSummary(),
            'filters' => $filters,
            'campusOptions' => $campus->filterOptions(),
            'classes' => $campus->constrain(ClassBatch::query(), 'campus_id')->orderBy('name')->get(['id', 'name'])
                ->map(fn (ClassBatch $class): array => ['id' => $class->id, 'name' => $class->name])->all(),
            'statusOptions' => NutritionStatus::options(),
            'can' => ['configure' => $request->user()->can('nutrition.configure')],
        ]);
    }

    public function show(Request $request, Candidate $candidate): Response
    {
        $viewer = $request->user();
        $candidate->loadMissing(['classBatch:id,name', 'campus', 'dietaryProfile.updater:id,name']);
        $standards = NutritionStandards::current();
        $history = NutritionPresenter::history($candidate, $standards, forStaff: true);

        // Dietitians see the medical record view only, like instructors of the class (each view audited).
        $medical = null;
        if ($viewer->can('viewMedicalAsDietitian', $candidate)) {
            $medical = MedicalRecordPresenter::forStaff($candidate, $viewer);
            if ($medical !== null) {
                app(MedicalRecordService::class)->recordInstructorView($candidate, $viewer);
            }
        }

        return Inertia::render('staff/nutrition/show', [
            'candidate' => $this->candidateSummary($candidate),
            'assessments' => $history,
            'dietaryProfile' => NutritionPresenter::dietaryProfile($candidate->dietaryProfile, forStaff: true),
            'standards' => $standards->toSummary(),
            'medical' => $medical,
            'medicalRecordUrl' => $viewer->can('manageMedical', $candidate) ? route('medical.records.edit', $candidate, false) : null,
            'profileUrl' => $viewer->can('view', $candidate) ? route('candidates.show', $candidate, false) : null,
            'can' => ['manage' => $viewer->can('manageNutrition', $candidate)],
        ]);
    }

    public function create(Candidate $candidate): Response
    {
        $candidate->loadMissing(['classBatch:id,name', 'campus']);
        $latest = $candidate->nutritionAssessments()->newestFirst()->first();

        return Inertia::render('staff/nutrition/assessment-form', [
            'candidate' => $this->candidateSummary($candidate),
            'assessment' => null,
            // Height rarely changes: start from the last one measured.
            'defaults' => ['heightCm' => $latest === null ? null : (float) $latest->height_cm, 'assessedOn' => now()->toDateString()],
            ...$this->formOptions(),
        ]);
    }

    public function store(NutritionAssessmentRequest $request, Candidate $candidate): RedirectResponse
    {
        $this->nutrition->recordAssessment($candidate, $request->assessmentData(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Assessment recorded for {$candidate->full_name}."]);

        return redirect()->route('nutrition.show', $candidate);
    }

    public function edit(NutritionAssessment $nutritionAssessment): Response
    {
        $candidate = $nutritionAssessment->candidate()->with(['classBatch:id,name', 'campus'])->firstOrFail();

        return Inertia::render('staff/nutrition/assessment-form', [
            'candidate' => $this->candidateSummary($candidate),
            'assessment' => NutritionPresenter::assessment($nutritionAssessment, NutritionStandards::current(), forStaff: true),
            'defaults' => null,
            ...$this->formOptions(),
        ]);
    }

    public function update(NutritionAssessmentRequest $request, NutritionAssessment $nutritionAssessment): RedirectResponse
    {
        $changed = $this->nutrition->correctAssessment($nutritionAssessment, $request->assessmentData());

        Inertia::flash('toast', ['type' => 'success', 'message' => $changed === [] ? 'No changes to save.' : 'Assessment corrected.']);

        return redirect()->route('nutrition.show', $nutritionAssessment->candidate_id);
    }

    public function destroy(Request $request, NutritionAssessment $nutritionAssessment): RedirectResponse
    {
        $reason = Validator::make($request->only('reason'), ['reason' => ['required', 'string', 'min:5', 'max:500']], [
            'reason.required' => 'Give the reason for deleting this assessment.',
            'reason.min' => 'Give the reason for deleting this assessment.',
        ])->validate()['reason'];

        $candidateId = $nutritionAssessment->candidate_id;
        $this->nutrition->deleteAssessment($nutritionAssessment, trim($reason));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Assessment deleted.']);

        return redirect()->route('nutrition.show', $candidateId);
    }

    public function updateDietaryProfile(DietaryProfileRequest $request, Candidate $candidate): RedirectResponse
    {
        $changed = $this->nutrition->saveDietaryProfile($candidate, $request->profileData(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => $changed === [] ? 'No changes to save.' : 'Dietary profile saved.']);

        return redirect()->route('nutrition.show', $candidate);
    }

    /** Chart colors: underweight and overweight share a badge tone but not a slice color. */
    private static function chartTone(NutritionStatus $status): string
    {
        return match ($status) {
            NutritionStatus::Normal => 'passing',
            NutritionStatus::Underweight => 'atRisk',
            NutritionStatus::Overweight => 'c4',
            NutritionStatus::Obese => 'failing',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function candidateSummary(Candidate $candidate): array
    {
        return [
            'id' => $candidate->id,
            'number' => $candidate->candidate_number,
            'name' => $candidate->full_name,
            'className' => $candidate->classBatch?->name,
            'campus' => $candidate->campus?->name,
            'status' => $candidate->status->label(),
            'photoUrl' => $candidate->profile_photo_path === null ? null : route('candidates.photo', $candidate, false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'activityLevels' => ActivityLevel::options(),
            'goals' => NutritionGoal::options(),
            'standards' => NutritionStandards::current()->toSummary(),
        ];
    }
}
