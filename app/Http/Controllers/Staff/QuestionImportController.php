<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\QuestionBank\QuestionImportRequest;
use App\Models\Subject;
use App\Services\QuestionBank\QuestionImportService;
use App\Support\QueryFilters;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Importing questions into one taught subject from a CSV file.
 *
 * Authorization is on the routes (question_bank.manage and
 * QuestionPolicy::create); the subject is validated by QuestionImportRequest.
 * All rules, and the all-or-nothing behaviour, are in QuestionImportService.
 */
class QuestionImportController extends Controller
{
    public const TEMPLATE_FILENAME = 'question-import-template.csv';

    public function __construct(private readonly QuestionImportService $importer) {}

    public function create(Request $request): Response
    {
        $taughtIds = $request->user()->taughtSubjectIds();
        $requested = QueryFilters::id($request, 'subject');

        return Inertia::render('staff/question-bank/import', [
            'subjects' => Subject::query()
                ->whereKey($taughtIds)
                ->orderBy('name')
                ->orderBy('id')
                ->get(['id', 'code', 'name'])
                ->map(fn (Subject $subject): array => ['id' => $subject->id, 'code' => $subject->code, 'name' => $subject->name])
                ->values()
                ->all(),
            // The requested subject, or the only one the user teaches.
            'selectedSubjectId' => $requested !== '' && in_array((int) $requested, $taughtIds, true)
                ? (int) $requested
                : (count($taughtIds) === 1 ? $taughtIds[0] : null),
            'limits' => [
                'maxRows' => QuestionImportService::MAX_ROWS,
                'maxFileKilobytes' => QuestionImportService::MAX_FILE_KILOBYTES,
            ],
        ]);
    }

    public function store(QuestionImportRequest $request): RedirectResponse
    {
        $subject = $request->subject();
        $count = $this->importer->import($subject, $request->fileContents(), $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $count === 1 ? '1 question imported.' : number_format($count).' questions imported.',
        ]);

        return redirect()->route('question-bank.index', ['subject' => $subject->id]);
    }

    public function template(): HttpResponse
    {
        return response($this->importer->template(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.self::TEMPLATE_FILENAME.'"',
            'Cache-Control' => 'no-store',
        ]);
    }
}
