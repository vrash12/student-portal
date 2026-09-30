<?php

namespace Tests\Feature\Examinations\Grading;

use App\Models\Candidate;
use App\Models\ClassSubject;
use App\Models\Examination;
use App\Models\ExaminationAttempt;
use App\Models\ExaminationQuestion;
use App\Models\Question;
use App\Models\QuestionChoice;
use App\Services\Examinations\CandidateAttemptService;
use Tests\Feature\Teaching\BuildsTeachingFixtures;

/**
 * An examination in Instructor Alpha's Batch A / Subject 1 offering:
 *
 *   position 1  multiple choice   4.00 points
 *   position 2  essay             3.00 points
 *   position 3  essay             5.00 points
 *                                ------
 *                                12.00 points, passing score 60%
 *
 * Results are not released until a test releases them.
 */
trait BuildsEssayGradingFixtures
{
    use BuildsTeachingFixtures;

    protected ClassSubject $offeringA1;

    protected Examination $exam;

    protected ExaminationQuestion $objectiveItem;

    protected ExaminationQuestion $shortEssay;

    protected ExaminationQuestion $longEssay;

    protected Candidate $secondCandidateInA;

    protected function buildEssayGradingFixtures(): void
    {
        $this->travelTo(now()->setTimezone(config('institution.timezone'))->setDate(2026, 9, 14)->setTime(9, 0));
        $this->buildTeachingFixtures();
        $this->offeringA1 = $this->batchA->classSubjects()
            ->whereHas('instructorAssignments', fn ($query) => $query->where('instructor_id', $this->alpha->id))->firstOrFail();
        $this->secondCandidateInA = Candidate::query()->where('class_batch_id', $this->batchA->id)->where('last_name', 'A2')->firstOrFail();
        $this->exam = $this->createExamination($this->offeringA1, 'Synthetic essay examination');
        $this->objectiveItem = $this->addItem($this->exam, Question::factory()->multipleChoice()->create(['subject_id' => $this->offeringA1->subject_id]), 1, '4');
        $this->shortEssay = $this->addItem($this->exam, Question::factory()->essay()->create(['subject_id' => $this->offeringA1->subject_id]), 2, '3');
        $this->longEssay = $this->addItem($this->exam, Question::factory()->essay()->create(['subject_id' => $this->offeringA1->subject_id]), 3, '5');
    }

    protected function createExamination(ClassSubject $offering, string $title): Examination
    {
        $exam = new Examination;
        $exam->class_subject_id = $offering->id;
        $exam->created_by = $this->alpha->id;
        $exam->title = $title;
        $exam->status = 'published';
        $exam->duration_minutes = 30;
        $exam->attempt_limit = 1;
        $exam->passing_score = 60;
        $exam->opens_at = now()->subMinute();
        $exam->closes_at = now()->addHours(2);
        $exam->save();

        return $exam->fresh();
    }

    protected function addItem(Examination $exam, Question $question, int $position, string $points): ExaminationQuestion
    {
        $item = new ExaminationQuestion;
        $item->examination_id = $exam->id;
        $item->question_id = $question->id;
        $item->position = $position;
        $item->points = $points;
        $item->save();

        return $item;
    }

    /**
     * Starts, answers every item, and submits through the candidate service,
     * so the attempt carries a real delivery snapshot and scoring key.
     */
    protected function submitAttempt(Candidate $candidate, bool $objectiveCorrect = true, string $essayText = 'Synthetic essay response', ?Examination $exam = null): ExaminationAttempt
    {
        $service = app(CandidateAttemptService::class);
        $attempt = $service->start($candidate->user, $exam ?? $this->exam, null);
        $items = $attempt->delivery;
        $last = count($items) - 1;
        foreach ($items as $position => $item) {
            if ($item['question']['type']['value'] === 'essay') {
                $answer = $essayText;
            } else {
                $correct = QuestionChoice::query()->where('question_id', $item['question']['id'])->where('is_correct', true)->value('id');
                $answer = $objectiveCorrect ? $correct : collect($item['question']['choices'])->pluck('id')->first(fn (int $id) => $id !== $correct);
            }
            $data = ['revision' => $attempt->revision, 'position' => $position, 'answer' => $answer];
            if ($position < $last) {
                $data['next_position'] = $position + 1;
            }
            $attempt = $service->save($candidate->user, $attempt, $data, $position === $last);
        }

        return $attempt->fresh();
    }

    protected function gradeUrl(ExaminationAttempt $attempt): string
    {
        return '/examination-attempts/'.$attempt->id.'/essay-grade';
    }

    protected function releaseResults(bool $release = true): void
    {
        $this->exam->release_results = $release;
        $this->exam->save();
    }
}
