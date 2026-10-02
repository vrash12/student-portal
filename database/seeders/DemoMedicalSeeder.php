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
 * Demo medical record (owner requests, 2026-10-02): a full set of placeholder
 * fields in sections, as an administrator would set them up, and fictional
 * values for the demo candidates student01–student20. The values are
 * invented for the demo; they describe no real person.
 *
 * Safe to run again: fields that exist (same name) are kept (a demo field
 * without a section gets its section and position), and only empty values
 * are filled. Never runs in production.
 *
 * php artisan db:seed --class=DemoMedicalSeeder
 */
class DemoMedicalSeeder extends Seeder
{
    private const RESULT = ['Normal', 'Abnormal', 'Pending'];

    /**
     * Section => [name => [type, choices, unit, help text, candidate sees it]].
     *
     * @var array<string, array<string, array{0: MedicalFieldType, 1: list<string>|null, 2: ?string, 3: ?string, 4: bool}>>
     */
    public const SECTIONS = [
        'General Information' => [
            'Blood Type' => [MedicalFieldType::Choice, ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'], null, null, true],
            'Height' => [MedicalFieldType::Number, null, 'cm', null, true],
            'Weight' => [MedicalFieldType::Number, null, 'kg', null, true],
            'Vision, Left Eye' => [MedicalFieldType::Text, null, null, 'For example 20/20.', true],
            'Vision, Right Eye' => [MedicalFieldType::Text, null, null, 'For example 20/20.', true],
            'Color Vision' => [MedicalFieldType::Choice, ['Normal', 'Deficient'], null, null, true],
            'Hearing' => [MedicalFieldType::Choice, ['Normal', 'Impaired'], null, null, true],
        ],
        'Medical History' => [
            'Allergies' => [MedicalFieldType::LongText, null, null, 'Medicines, foods, insect stings. Write "None known" if none.', true],
            'Existing Medical Conditions' => [MedicalFieldType::LongText, null, null, null, true],
            'Past Surgeries and Hospitalizations' => [MedicalFieldType::LongText, null, null, 'With the year.', true],
            'Current Medications' => [MedicalFieldType::LongText, null, null, 'Name and dose.', true],
            'Family Medical History' => [MedicalFieldType::LongText, null, null, 'Conditions of parents and siblings, such as diabetes or heart disease.', true],
            'Smoking' => [MedicalFieldType::Choice, ['Never', 'Former', 'Current'], null, null, true],
            'Alcohol Use' => [MedicalFieldType::Choice, ['None', 'Occasional', 'Regular'], null, null, true],
        ],
        'Immunizations' => [
            'Tetanus-Diphtheria, Last Dose' => [MedicalFieldType::Date, null, null, null, true],
            'Hepatitis B' => [MedicalFieldType::Choice, ['Complete', 'Incomplete', 'None'], null, null, true],
            'Measles, Mumps, Rubella' => [MedicalFieldType::Choice, ['Complete', 'Incomplete', 'None'], null, null, true],
            'COVID-19' => [MedicalFieldType::Choice, ['Complete with booster', 'Complete', 'Incomplete', 'None'], null, null, true],
            'Influenza, Last Dose' => [MedicalFieldType::Date, null, null, null, true],
        ],
        'Examinations and Tests' => [
            'Last Physical Examination' => [MedicalFieldType::Date, null, null, null, true],
            'Blood Pressure' => [MedicalFieldType::Text, null, null, 'Systolic/diastolic in mmHg, for example 120/80.', true],
            'Pulse Rate' => [MedicalFieldType::Number, null, 'bpm', 'At rest.', true],
            'Chest X-Ray' => [MedicalFieldType::Choice, self::RESULT, null, null, true],
            'Complete Blood Count' => [MedicalFieldType::Choice, self::RESULT, null, null, true],
            'Urinalysis' => [MedicalFieldType::Choice, self::RESULT, null, null, true],
            'Electrocardiogram' => [MedicalFieldType::Choice, ['Normal', 'Abnormal', 'Not required'], null, null, true],
            'Dental Examination' => [MedicalFieldType::Choice, ['Fit', 'Needs treatment', 'Pending'], null, null, true],
            'Drug Test' => [MedicalFieldType::Choice, ['Negative', 'Positive', 'Pending'], null, null, false],
        ],
        'Fitness for Training' => [
            'Medical Classification' => [MedicalFieldType::Choice, ['Fit for full duty', 'Fit with restrictions', 'Temporarily unfit', 'Unfit'], null, null, true],
            'Cleared for Strenuous Training' => [MedicalFieldType::YesNo, null, null, null, true],
            'Training Restrictions' => [MedicalFieldType::LongText, null, null, 'What the candidate must not do, and what is allowed.', true],
            'Restrictions Until' => [MedicalFieldType::Date, null, null, null, true],
        ],
        'Emergency' => [
            'Emergency Medical Notes' => [MedicalFieldType::LongText, null, null, 'What the training staff must know in an emergency.', true],
            'Emergency Contact Name' => [MedicalFieldType::Text, null, null, null, true],
            'Emergency Contact Relationship' => [MedicalFieldType::Text, null, null, null, true],
            'Emergency Contact Number' => [MedicalFieldType::Text, null, null, null, true],
        ],
        'Medical Staff Notes' => [
            'Attending Physician' => [MedicalFieldType::Text, null, null, null, true],
            'Psychological Evaluation' => [MedicalFieldType::Choice, ['Cleared', 'For follow-up', 'Pending'], null, null, false],
            "Physician's Remarks" => [MedicalFieldType::LongText, null, null, 'Medical staff only.', false],
        ],
    ];

    private const BLOOD_TYPES = ['O+', 'A+', 'B+', 'O+', 'AB+', 'O-', 'A+', 'B+', 'O+', 'A-'];

    private const PARENTS = ['Rosalinda', 'Eduardo', 'Marites', 'Ernesto', 'Leonora', 'Rogelio', 'Corazon', 'Danilo', 'Erlinda', 'Renato'];

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
            'Medical Classification' => 'Fit with restrictions',
            'Training Restrictions' => 'Keep the inhaler at hand during runs; allow rest if wheezing.',
        ],
        'student08' => ['Allergies' => 'Shellfish.'],
        'student11' => [
            'Existing Medical Conditions' => 'Left ankle sprain (September 2026), healing.',
            'Medical Classification' => 'Temporarily unfit',
            'Cleared for Strenuous Training' => 'no',
            'Training Restrictions' => 'No running or marching drills. Upper-body training allowed.',
            'Restrictions Until' => '2026-10-15',
            "Physician's Remarks" => 'No running drills until 2026-10-15; re-check then.',
        ],
        'student14' => ['Allergies' => 'Dust mites (sneezing).'],
        'student17' => [
            'Past Surgeries and Hospitalizations' => 'Appendectomy (2021).',
            'Dental Examination' => 'Needs treatment',
        ],
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

        $fields = $this->fields($admin);

        $candidates = Candidate::query()
            ->whereIn('candidate_number', array_map(fn (int $index): string => sprintf('student%02d', $index), range(1, 20)))
            ->with('medicalValues:id,candidate_id,medical_field_id')
            ->orderBy('candidate_number')
            ->get();

        $service = app(MedicalRecordService::class);
        foreach ($candidates as $candidate) {
            $recorded = $candidate->medicalValues->pluck('medical_field_id')->all();
            $missing = [];
            foreach ($this->valuesFor($candidate) as $name => $value) {
                $field = $fields[$name] ?? null;
                if ($field !== null && $field->is_active && ! in_array($field->id, $recorded, true)) {
                    $missing[$field->id] = $value;
                }
            }
            if ($missing !== []) {
                $service->saveRecord($candidate, $missing, $admin);
            }
        }
    }

    /**
     * @return array<string, MedicalField>
     */
    private function fields(User $admin): array
    {
        $service = app(MedicalRecordService::class);
        $fields = [];
        $order = 0;
        foreach (self::SECTIONS as $section => $definitions) {
            foreach ($definitions as $name => [$type, $options, $unit, $help, $candidate]) {
                $order++;
                $field = MedicalField::query()->where('name', $name)->first();
                if ($field === null) {
                    $field = $service->createField([
                        'name' => $name,
                        'section' => $section,
                        'field_type' => $type->value,
                        'options' => $options,
                        'unit' => $unit,
                        'help_text' => $help,
                        'sort_order' => $order,
                        'visible_to_candidate' => $candidate,
                    ], $admin);
                } elseif ($field->section === null) {
                    // A demo field from before sections existed: place it in its section.
                    $field->forceFill(['section' => $section, 'sort_order' => $order])->save();
                }
                $fields[$name] = $field;
            }
        }

        return $fields;
    }

    /**
     * The demo values of one candidate (field name => value).
     *
     * @return array<string, string>
     */
    private function valuesFor(Candidate $candidate): array
    {
        $index = (int) substr($candidate->candidate_number, -2);
        $female = DemoPeopleSeeder::CANDIDATES[$candidate->candidate_number][4] ?? false;
        $lastName = $candidate->last_name;
        $vision = ['20/20', '20/20', '20/25', '20/20', '20/30'][$index % 5];

        return [
            'Blood Type' => self::BLOOD_TYPES[$index % count(self::BLOOD_TYPES)],
            'Height' => (string) (($female ? 156 : 166) + ($index * 7) % 14),
            'Weight' => (string) (($female ? 52 : 62) + ($index * 5) % 16),
            'Vision, Left Eye' => $vision,
            'Vision, Right Eye' => $index % 7 === 0 ? '20/30' : $vision,
            'Color Vision' => 'Normal',
            'Hearing' => 'Normal',
            'Allergies' => 'None known.',
            'Existing Medical Conditions' => 'None.',
            'Past Surgeries and Hospitalizations' => 'None.',
            'Current Medications' => 'None.',
            'Family Medical History' => ['Hypertension (father).', 'Diabetes (mother).', 'None known.', 'Asthma (sibling).'][$index % 4],
            'Smoking' => $index % 9 === 0 ? 'Former' : 'Never',
            'Alcohol Use' => $index % 3 === 0 ? 'Occasional' : 'None',
            'Tetanus-Diphtheria, Last Dose' => sprintf('2025-%02d-15', 1 + $index % 12),
            'Hepatitis B' => 'Complete',
            'Measles, Mumps, Rubella' => 'Complete',
            'COVID-19' => $index % 4 === 0 ? 'Complete' : 'Complete with booster',
            'Influenza, Last Dose' => sprintf('2026-%02d-10', 3 + $index % 5),
            'Last Physical Examination' => sprintf('2026-08-%02d', 3 + ($index % 20)),
            'Blood Pressure' => ['110/70', '120/80', '118/76', '122/78', '115/75'][$index % 5],
            'Pulse Rate' => (string) (62 + ($index * 3) % 18),
            'Chest X-Ray' => 'Normal',
            'Complete Blood Count' => 'Normal',
            'Urinalysis' => 'Normal',
            'Electrocardiogram' => 'Normal',
            'Dental Examination' => 'Fit',
            'Drug Test' => 'Negative',
            'Medical Classification' => 'Fit for full duty',
            'Cleared for Strenuous Training' => 'yes',
            'Training Restrictions' => 'None.',
            'Emergency Contact Name' => self::PARENTS[$index % count(self::PARENTS)].' '.$lastName,
            'Emergency Contact Relationship' => $index % 2 === 0 ? 'Mother' : 'Father',
            'Emergency Contact Number' => sprintf('0917 555 %04d', $index),
            'Attending Physician' => 'Dr. Placeholder (camp clinic)',
            'Psychological Evaluation' => 'Cleared',
            ...(self::NOTES[$candidate->candidate_number] ?? []),
        ];
    }
}
