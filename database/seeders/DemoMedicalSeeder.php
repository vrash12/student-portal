<?php

namespace Database\Seeders;

use App\Enums\MedicalFieldType;
use App\Enums\SystemRole;
use App\Models\Candidate;
use App\Models\MedicalField;
use App\Models\User;
use App\Services\Medical\MedicalRecordService;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Demo medical record (owner request, 2026-10-02): placeholder fields, as an
 * administrator would set them up, and fictional values for the demo
 * candidates student01–student20. The values are invented for the demo; they
 * describe no real person.
 *
 * Safe to run again: fields that exist (same name) are kept, and candidates
 * who already have any medical value are left alone. Never runs in production.
 *
 * php artisan db:seed --class=DemoMedicalSeeder
 */
class DemoMedicalSeeder extends Seeder
{
    /**
     * Name => [type, choices, help text, instructors see it, candidate sees it].
     *
     * @var array<string, array{0: MedicalFieldType, 1: list<string>|null, 2: ?string, 3: bool, 4: bool}>
     */
    public const FIELDS = [
        'Blood Type' => [MedicalFieldType::Choice, ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'], null, true, true],
        'Allergies' => [MedicalFieldType::LongText, null, 'Medicines, foods, insect stings. Write "None known" if none.', true, true],
        'Existing Medical Conditions' => [MedicalFieldType::LongText, null, null, false, true],
        'Current Medications' => [MedicalFieldType::LongText, null, null, false, true],
        'Emergency Medical Notes' => [MedicalFieldType::LongText, null, 'What instructors must know in training.', true, true],
        'Last Physical Examination' => [MedicalFieldType::Date, null, null, false, true],
        'Cleared for Strenuous Training' => [MedicalFieldType::YesNo, null, null, true, true],
        "Physician's Remarks" => [MedicalFieldType::LongText, null, 'Medical staff only.', false, false],
    ];

    private const BLOOD_TYPES = ['O+', 'A+', 'B+', 'O+', 'AB+', 'O-', 'A+', 'B+', 'O+', 'A-'];

    /**
     * Candidates with something to note, by candidate number (field name => value).
     *
     * @var array<string, array<string, string>>
     */
    private const NOTES = [
        'student03' => [
            'Allergies' => 'Penicillin (rash).',
            'Emergency Medical Notes' => 'Allergic to penicillin: tell the medics.',
        ],
        'student06' => [
            'Existing Medical Conditions' => 'Mild asthma, controlled.',
            'Current Medications' => 'Salbutamol inhaler as needed.',
            'Emergency Medical Notes' => 'Carries an inhaler. Allow rest if wheezing during runs.',
        ],
        'student08' => ['Allergies' => 'Shellfish.'],
        'student11' => [
            'Existing Medical Conditions' => 'Left ankle sprain (September 2026), healing.',
            'Cleared for Strenuous Training' => 'no',
            "Physician's Remarks" => 'No running drills until 2026-10-15; re-check then.',
        ],
        'student14' => ['Allergies' => 'Dust mites (sneezing).'],
    ];

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Demo medical records must not be seeded in production.');
        }

        $admin = User::query()->where('username', 'admin')->first()
            ?? User::query()->whereHas('role', fn ($role) => $role->where('code', SystemRole::SuperAdministrator->value))->orderBy('id')->first();
        if ($admin === null) {
            $this->command?->warn('No administrator account: medical records not seeded.');

            return;
        }

        $service = app(MedicalRecordService::class);
        $order = 0;
        $fields = [];
        foreach (self::FIELDS as $name => [$type, $options, $help, $instructors, $candidate]) {
            $order++;
            $fields[$name] = MedicalField::query()->where('name', $name)->first() ?? $service->createField([
                'name' => $name,
                'field_type' => $type->value,
                'options' => $options,
                'help_text' => $help,
                'sort_order' => $order,
                'visible_to_instructors' => $instructors,
                'visible_to_candidate' => $candidate,
            ], $admin);
        }

        $candidates = Candidate::query()
            ->whereIn('candidate_number', array_map(fn (int $index): string => sprintf('student%02d', $index), range(1, 20)))
            ->whereDoesntHave('medicalValues')
            ->orderBy('candidate_number')
            ->get();

        foreach ($candidates as $candidate) {
            $index = (int) substr($candidate->candidate_number, -2);
            $values = [
                'Blood Type' => self::BLOOD_TYPES[$index % count(self::BLOOD_TYPES)],
                'Allergies' => 'None known.',
                'Existing Medical Conditions' => 'None.',
                'Current Medications' => 'None.',
                'Last Physical Examination' => sprintf('2026-08-%02d', 3 + ($index % 20)),
                'Cleared for Strenuous Training' => 'yes',
                ...(self::NOTES[$candidate->candidate_number] ?? []),
            ];

            $byId = [];
            foreach ($values as $name => $value) {
                if (isset($fields[$name]) && $fields[$name]->is_active) {
                    $byId[$fields[$name]->id] = $value;
                }
            }
            $service->saveRecord($candidate, $byId, $admin);
        }
    }
}
