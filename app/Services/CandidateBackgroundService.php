<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Models\Candidate;
use App\Models\CandidateBackground;
use App\Models\CandidateEducation;
use Illuminate\Support\Facades\DB;

/**
 * Saves a candidate's background record and education entries together
 * (owner request, 2026-10-03), in one transaction with the candidate's row
 * locked, and audits what changed. Contact details (address, phone numbers,
 * personal email) are audited as a short fingerprint only, so the audit log
 * shows that they changed without holding them.
 */
final class CandidateBackgroundService
{
    /** Fields audited as a fingerprint instead of their value. */
    private const FINGERPRINTED = ['home_address', 'mobile_number', 'personal_email', 'emergency_contact_phone'];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $background  CandidateBackground fillable values (null clears one)
     * @param  list<array{level: string, degree: string, school: string, year_graduated: ?int, honors: ?string}>  $education
     */
    public function save(Candidate $candidate, array $background, array $education): void
    {
        DB::transaction(function () use ($candidate, $background, $education): void {
            $locked = Candidate::query()->lockForUpdate()->findOrFail($candidate->id);
            $before = $this->snapshot($locked);

            $record = $locked->background()->firstOrNew();
            $record->fill(array_intersect_key($background, array_flip([...CandidateBackground::PERSONAL_FIELDS, ...CandidateBackground::SERVICE_FIELDS])));
            $record->candidate()->associate($locked);
            $record->save();

            $locked->education()->delete();
            foreach (array_values($education) as $position => $entry) {
                $row = new CandidateEducation([...$entry, 'position' => $position + 1]);
                $row->candidate()->associate($locked);
                $row->save();
            }

            $this->audit->recordChanges(AuditAction::CandidateBackgroundUpdated, $locked, $before, $this->snapshot($locked));
        });
    }

    /** @return array<string, mixed> */
    private function snapshot(Candidate $candidate): array
    {
        $background = $candidate->background()->first();
        $values = [];
        foreach ([...CandidateBackground::PERSONAL_FIELDS, ...CandidateBackground::SERVICE_FIELDS] as $field) {
            $value = $background?->getRawOriginal($field);
            $values[$field] = in_array($field, self::FINGERPRINTED, true) && $value !== null
                ? 'recorded #'.substr(hash('sha256', (string) $value), 0, 8)
                : $value;
        }
        $values['education'] = $candidate->education()->get()
            ->map(fn (CandidateEducation $entry): string => "{$entry->level->label()}: {$entry->degree}, {$entry->school}".($entry->year_graduated === null ? '' : " ({$entry->year_graduated})"))
            ->implode('; ') ?: null;

        return $values;
    }
}
