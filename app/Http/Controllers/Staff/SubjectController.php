<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academic\SubjectRequest;
use App\Models\Subject;
use App\Services\SubjectService;
use App\Support\QueryFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SubjectController extends Controller
{
    private const PER_PAGE = 20;

    public function __construct(private readonly SubjectService $subjects) {}

    public function index(Request $request): Response
    {
        $filters = [
            'search' => QueryFilters::search($request),
            'status' => QueryFilters::oneOf($request, 'status', ['active', 'inactive']),
        ];

        $subjects = Subject::query()
            ->withCount('classSubjects')
            ->when($filters['search'] !== '', fn (Builder $query) => $query->where(function (Builder $match) use ($filters): void {
                $term = QueryFilters::likeTerm($filters['search']);
                $match->where('code', 'like', $term)->orWhere('name', 'like', $term);
            }))
            ->when($filters['status'] !== '', fn (Builder $query) => $query->where('is_active', $filters['status'] === 'active'))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Subject $subject): array => [
                'id' => $subject->id,
                'code' => $subject->code,
                'name' => $subject->name,
                'isActive' => $subject->is_active,
                'classCount' => (int) $subject->class_subjects_count,
            ]);

        return Inertia::render('staff/subjects/index', [
            'subjects' => $subjects,
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('staff/subjects/create');
    }

    public function store(SubjectRequest $request): RedirectResponse
    {
        $data = $request->subjectData();
        $subject = $this->subjects->create([
            'code' => $data['code'],
            'name' => $data['name'],
            'description' => $data['description'],
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Subject {$subject->name} created."]);

        return redirect()->route('subjects.index');
    }

    public function edit(Subject $subject): Response
    {
        return Inertia::render('staff/subjects/edit', [
            'subject' => [
                'id' => $subject->id,
                'code' => $subject->code,
                'name' => $subject->name,
                'description' => $subject->description,
                'isActive' => $subject->is_active,
                'classCount' => $subject->classSubjects()->count(),
            ],
        ]);
    }

    public function update(SubjectRequest $request, Subject $subject): RedirectResponse
    {
        $this->subjects->update($subject, $request->subjectData());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Subject {$subject->name} updated."]);

        return redirect()->route('subjects.index');
    }
}
