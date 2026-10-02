<?php

namespace App\Services\Grading;

use App\Enums\AuditAction;
use App\Models\AssessmentCategory;
use App\Models\ClassSubject;
use App\Services\AuditLogger;
use App\Support\DecimalValue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The grading scheme of a class subject: its categories and their weights.
 *
 * Rules enforced here (the Form Request only checks input shape):
 * - the weights add up to exactly 100;
 * - category names are unique within the subject;
 * - a category that has assessments cannot be removed;
 * - once assessments are finalized, any change needs a reason, because it
 *   changes grades that are already official.
 */
final class GradingSchemeService
{
    public const TOTAL_WEIGHT = 100;

    private const DUPLICATE_NAME_MESSAGE = 'Each component needs a different name. Capital letters and accents do not make names different.';

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Gives subjects that have no weights yet the same categories and
     * weights as the source subject (the Grading Setup page's "Copy
     * Weights"). Only empty setups are filled, so nothing that already
     * counts toward a grade changes, and no reason is needed; each copy is
     * audited with its source. All or nothing: if one target already has
     * weights, nothing is copied.
     *
     * @param  iterable<ClassSubject>  $targets
     * @return int the number of subjects that received the weights
     *
     * @throws ValidationException
     */
    public function copy(ClassSubject $source, iterable $targets): int
    {
        $source->loadMissing(['classBatch.academicPeriod', 'subject']);
        $categories = $source->assessmentCategories()
            ->get()
            ->map(fn (AssessmentCategory $category): array => [
                'id' => null,
                'name' => $category->name,
                'weight' => DecimalValue::normalize($category->weight),
            ])
            ->values()
            ->all();

        if ($categories === []) {
            throw ValidationException::withMessages(['source' => 'The chosen subject has no weights to copy. Choose one that is set up.']);
        }

        // Class names repeat across periods, so the note names the period too.
        $note = "Copied from {$source->classBatch->name} · {$source->subject->name} ({$source->classBatch->academicPeriod->name})";
        $ids = collect($targets)
            ->map(fn (ClassSubject $target): int => (int) $target->getKey())
            ->reject(fn (int $id): bool => $id === (int) $source->getKey())
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($ids === []) {
            return 0;
        }

        return DB::transaction(function () use ($ids, $categories, $note): int {
            // Lock every target first, in id order (as every grading change
            // does), before any plain read. InnoDB takes the transaction's
            // read snapshot at its first plain read; taking it only once all
            // locks are held means a save by someone else cannot be committed
            // unseen and then overwritten by the copy.
            $locked = ClassSubject::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get();

            $filled = AssessmentCategory::query()->whereIn('class_subject_id', $ids)->orderBy('class_subject_id')->value('class_subject_id');
            if ($filled !== null) {
                $target = $locked->firstWhere('id', (int) $filled);
                $target?->loadMissing(['classBatch', 'subject']);

                throw ValidationException::withMessages([
                    'targets' => "{$target?->classBatch->name} · {$target?->subject->name} already has weights. Change it on its own page instead. Nothing was copied.",
                ]);
            }

            foreach ($locked as $target) {
                $this->save($target, $categories, $note);
            }

            return $locked->count();
        });
    }

    /**
     * Replaces the categories of the class subject with the given list, in
     * display order. Existing categories are identified by id.
     *
     * @param  list<array{id: int|null, name: string, weight: string}>  $categories
     *
     * @throws ValidationException
     */
    public function save(ClassSubject $offering, array $categories, ?string $reason): void
    {
        try {
            DB::transaction(function () use ($offering, $categories, $reason): void {
                // Serializes concurrent edits of the same scheme with assessment
                // changes and subject removal, which lock the same row.
                ClassSubject::query()->whereKey($offering->getKey())->lockForUpdate()->firstOrFail();

                /** @var Collection<int, AssessmentCategory> $existing */
                $existing = $offering->assessmentCategories()->withCount('assessments')->get()->keyBy('id');

                $errors = $this->validationErrors($categories, $existing);

                // Categories are compared by identity, name, weight, and order,
                // so moving a weight from one category to another is a change.
                $before = $this->snapshot($existing->values());
                $after = array_map(fn (array $category): array => [
                    'id' => $category['id'],
                    'name' => $category['name'],
                    'weight' => DecimalValue::normalize($category['weight']),
                ], $categories);

                if ($errors === [] && $before === $after) {
                    return;
                }

                $reason = $reason === null ? '' : trim($reason);
                if ($reason === '' && $offering->assessments()->finalized()->exists()) {
                    $errors['reason'] = 'Explain why the grading setup is changing. Finalized grades of this subject will be recalculated.';
                }

                if ($errors !== []) {
                    throw ValidationException::withMessages($errors);
                }

                $this->apply($offering, $categories, $existing);

                $this->audit->record(
                    AuditAction::GradingSchemeUpdated,
                    $offering,
                    oldValues: ['categories' => $before],
                    newValues: ['categories' => $this->snapshot($offering->assessmentCategories()->get())],
                    reason: $reason === '' ? null : $reason,
                );
            });
        } catch (UniqueConstraintViolationException) {
            // The database collation may treat names as equal that the check
            // below does not (rare Unicode equivalences).
            throw ValidationException::withMessages(['categories' => self::DUPLICATE_NAME_MESSAGE]);
        }
    }

    /**
     * @param  list<array{id: int|null, name: string, weight: string}>  $categories
     * @param  Collection<int, AssessmentCategory>  $existing
     * @return array<string, string>
     */
    private function validationErrors(array $categories, Collection $existing): array
    {
        $errors = [];

        if ($categories === []) {
            return ['categories' => 'Add at least one component, for example Quizzes.'];
        }

        $seenNames = [];
        $keptIds = [];
        foreach ($categories as $index => $category) {
            if ($category['id'] !== null) {
                if (! $existing->has($category['id'])) {
                    $errors["categories.{$index}.name"] = 'This component no longer exists. Reload the page and try again.';
                }
                $keptIds[] = $category['id'];
            }

            // Mirrors the accent- and case-insensitive database collation.
            // Names without a Latin transliteration are compared as written.
            $key = Str::lower(Str::ascii($category['name'])) ?: Str::lower($category['name']);
            if (isset($seenNames[$key])) {
                $errors["categories.{$index}.name"] = self::DUPLICATE_NAME_MESSAGE;
            }
            $seenNames[$key] = true;
        }

        $total = array_sum(array_map(fn (array $category): int => DecimalValue::toHundredths($category['weight']), $categories));
        if ($total !== self::TOTAL_WEIGHT * 100) {
            $errors['categories'] = sprintf(
                'The weights must add up to exactly %d%%. They currently add up to %s%%.',
                self::TOTAL_WEIGHT,
                DecimalValue::display($total / 100),
            );
        }

        foreach ($existing as $category) {
            if (! in_array($category->id, $keptIds, true) && $category->assessments_count > 0) {
                $errors['categories'] = "{$category->name} has assessments and cannot be removed. Rename it or change its weight instead.";
            }
        }

        return $errors;
    }

    /**
     * @param  list<array{id: int|null, name: string, weight: string}>  $categories
     * @param  Collection<int, AssessmentCategory>  $existing
     */
    private function apply(ClassSubject $offering, array $categories, Collection $existing): void
    {
        $keptIds = array_values(array_filter(array_column($categories, 'id')));

        // Removed categories go first so their names can be reused.
        $offering->assessmentCategories()->whereNotIn('id', $keptIds)->delete();

        // Renamed categories take a unique placeholder first: names are
        // unique per subject and MySQL checks that row by row, so swapping
        // two names would otherwise fail halfway.
        foreach ($categories as $category) {
            $current = $category['id'] === null ? null : $existing->get($category['id']);
            if ($current !== null && $current->name !== $category['name']) {
                $current->forceFill(['name' => '~'.Str::uuid()->toString()])->save();
            }
        }

        foreach ($categories as $position => $category) {
            $model = $category['id'] === null ? new AssessmentCategory : $existing->get($category['id']);
            $model->fill([
                'name' => $category['name'],
                'weight' => DecimalValue::normalize($category['weight']),
                'position' => $position,
            ]);
            $model->classSubject()->associate($offering);
            $model->save();
        }
    }

    /**
     * The id shows which category (and so which assessments) each weight
     * belongs to.
     *
     * @param  Collection<int, AssessmentCategory>  $categories
     * @return list<array{id: int, name: string, weight: string}>
     */
    private function snapshot(Collection $categories): array
    {
        return $categories
            ->map(fn (AssessmentCategory $category): array => [
                'id' => $category->id,
                'name' => $category->name,
                'weight' => DecimalValue::normalize($category->weight),
            ])
            ->values()
            ->all();
    }
}
