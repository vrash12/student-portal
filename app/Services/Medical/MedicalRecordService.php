<?php

namespace App\Services\Medical;

use App\Enums\AuditAction;
use App\Enums\MedicalFieldType;
use App\Models\Candidate;
use App\Models\CandidateMedicalRevision;
use App\Models\CandidateMedicalValue;
use App\Models\MedicalField;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Candidate medical records (owner request, 2026-10-02).
 *
 * Administrators define the fields; medical staff record each candidate's
 * values. Every change of a value is kept as a CandidateMedicalRevision
 * (previous value, new value, who, when). The general audit log records the
 * names of the changed fields only, never medical values. A field's type is
 * fixed once values exist, a choice still recorded cannot be removed, and a
 * field with history is deactivated rather than deleted.
 */
class MedicalRecordService
{
    public const OPTIONS_MAX = 30;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Validation rules for one value of the field (null or '' clears it).
     *
     * @return list<mixed>
     */
    public static function rulesFor(MedicalField $field): array
    {
        return match ($field->field_type) {
            MedicalFieldType::Text => ['nullable', 'string', 'max:'.MedicalFieldType::TEXT_MAX],
            MedicalFieldType::LongText => ['nullable', 'string', 'max:'.MedicalFieldType::LONG_TEXT_MAX],
            MedicalFieldType::Choice => ['nullable', 'string', Rule::in($field->choiceOptions())],
            MedicalFieldType::Date => ['nullable', 'date_format:Y-m-d'],
            MedicalFieldType::YesNo => ['nullable', 'in:yes,no'],
        };
    }

    /**
     * @param  array{name: string, field_type: string, options: list<string>|null, help_text: ?string, sort_order: int, visible_to_instructors: bool, visible_to_candidate: bool}  $data
     */
    public function createField(array $data, User $actor): MedicalField
    {
        return DB::transaction(function () use ($data, $actor): MedicalField {
            $field = new MedicalField;
            $field->fill($this->fieldAttributes($data));
            $field->is_active = true;
            $field->save();

            $this->audit->record(AuditAction::MedicalFieldCreated, $field, newValues: $this->fieldSnapshot($field), actor: $actor);

            return $field;
        });
    }

    /**
     * @param  array{name: string, field_type: string, options: list<string>|null, help_text: ?string, sort_order: int, visible_to_instructors: bool, visible_to_candidate: bool, is_active: bool}  $data
     *
     * @throws ValidationException
     */
    public function updateField(MedicalField $field, array $data, User $actor): MedicalField
    {
        return DB::transaction(function () use ($field, $data): MedicalField {
            $locked = MedicalField::query()->whereKey($field->getKey())->lockForUpdate()->firstOrFail();
            $before = $this->fieldSnapshot($locked);
            $attributes = $this->fieldAttributes($data);

            if ($attributes['field_type'] !== $locked->field_type->value && $locked->values()->exists()) {
                throw ValidationException::withMessages(['field_type' => 'The type cannot be changed because values are recorded. Add a new field instead.']);
            }

            if ($locked->field_type === MedicalFieldType::Choice && $attributes['field_type'] === MedicalFieldType::Choice->value) {
                $removed = array_values(array_diff($locked->choiceOptions(), $attributes['options'] ?? []));
                $inUse = $removed === [] ? [] : $locked->values()->whereIn('value', $removed)->distinct()->pluck('value')->all();
                if ($inUse !== []) {
                    throw ValidationException::withMessages(['options' => sprintf(
                        '%s cannot be removed because %s still recorded for candidates. Change those records first.',
                        implode(', ', array_map(fn (string $option): string => "“{$option}”", $inUse)),
                        count($inUse) === 1 ? 'it is' : 'they are',
                    )]);
                }
            }

            $locked->fill($attributes);
            $locked->is_active = $data['is_active'];
            $locked->save();

            $this->audit->recordChanges(AuditAction::MedicalFieldUpdated, $locked, $before, $this->fieldSnapshot($locked->refresh()));

            return $locked;
        });
    }

    /**
     * Deletes a field that has never held a value.
     *
     * @throws ValidationException
     */
    public function deleteField(MedicalField $field, User $actor): void
    {
        DB::transaction(function () use ($field, $actor): void {
            $locked = MedicalField::query()->whereKey($field->getKey())->lockForUpdate()->firstOrFail();
            $used = $locked->values()->exists()
                || CandidateMedicalRevision::query()->where('medical_field_id', $locked->id)->exists();
            if ($used) {
                throw ValidationException::withMessages(['field' => 'This field has recorded values or history. Deactivate it instead.']);
            }

            $snapshot = $this->fieldSnapshot($locked);
            $locked->delete();
            $this->audit->record(AuditAction::MedicalFieldDeleted, $locked, oldValues: $snapshot, actor: $actor);
        });
    }

    /**
     * Saves a candidate's values for the active fields given (field id =>
     * value; null or '' clears). Returns the number of fields changed.
     *
     * @param  array<int|string, mixed>  $values
     *
     * @throws ValidationException
     */
    public function saveRecord(Candidate $candidate, array $values, User $actor): int
    {
        return DB::transaction(function () use ($candidate, $values, $actor): int {
            $fields = MedicalField::query()->active()->whereIn('id', array_map('intval', array_keys($values)))->get()->keyBy('id');
            $existing = CandidateMedicalValue::query()
                ->where('candidate_id', $candidate->id)
                ->whereIn('medical_field_id', $fields->keys())
                ->lockForUpdate()
                ->get()
                ->keyBy('medical_field_id');

            $changed = [];
            foreach ($fields as $id => $field) {
                $new = $this->normalize($field, $values[$id] ?? null);
                $error = Validator::make(['value' => $new], ['value' => self::rulesFor($field)])->errors()->first('value');
                if ($error !== null && $error !== '') {
                    throw ValidationException::withMessages(["values.{$id}" => $error]);
                }

                $current = $existing->get($id);
                $previous = $current?->value;
                if ($previous === $new) {
                    continue;
                }

                if ($new === null) {
                    $current?->delete();
                } else {
                    $record = $current ?? new CandidateMedicalValue;
                    $record->candidate_id = $candidate->id;
                    $record->medical_field_id = $field->id;
                    $record->value = $new;
                    $record->updated_by = $actor->id;
                    $record->save();
                }

                $revision = new CandidateMedicalRevision;
                $revision->candidate_id = $candidate->id;
                $revision->medical_field_id = $field->id;
                $revision->previous_value = $previous;
                $revision->new_value = $new;
                $revision->changed_by = $actor->id;
                $revision->save();

                $changed[] = $field->name;
            }

            if ($changed !== []) {
                // Field names only: medical values stay in the restricted medical history.
                $this->audit->record(AuditAction::MedicalRecordUpdated, $candidate, newValues: ['candidate' => $candidate->candidate_number, 'fields' => $changed], actor: $actor);
            }

            return count($changed);
        });
    }

    private function normalize(MedicalField $field, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        return $field->field_type === MedicalFieldType::YesNo ? strtolower($text) : $text;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{name: string, field_type: string, options: list<string>|null, help_text: ?string, sort_order: int, visible_to_instructors: bool, visible_to_candidate: bool}
     */
    private function fieldAttributes(array $data): array
    {
        $type = (string) $data['field_type'];

        return [
            'name' => (string) $data['name'],
            'field_type' => $type,
            // Only choice fields keep a list of answers.
            'options' => $type === MedicalFieldType::Choice->value ? array_values($data['options'] ?? []) : null,
            'help_text' => $data['help_text'] ?? null,
            'sort_order' => (int) $data['sort_order'],
            'visible_to_instructors' => (bool) $data['visible_to_instructors'],
            'visible_to_candidate' => (bool) $data['visible_to_candidate'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fieldSnapshot(MedicalField $field): array
    {
        return [
            ...Arr::only($field->getAttributes(), ['name', 'help_text', 'sort_order']),
            'field_type' => $field->field_type->value,
            'options' => $field->field_type === MedicalFieldType::Choice ? implode(', ', $field->choiceOptions()) : null,
            'visible_to_instructors' => $field->visible_to_instructors,
            'visible_to_candidate' => $field->visible_to_candidate,
            'is_active' => $field->is_active,
        ];
    }
}
