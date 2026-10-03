<?php

namespace Database\Seeders;

use App\Models\Candidate;
use App\Models\CandidateBackground;
use App\Models\CandidateEducation;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Synthetic background records for the demo candidates (owner request,
 * 2026-10-03): birth details, contacts with reserved test numbers and
 * example.org addresses, a bachelor's degree from generic schools, and
 * service background. Only candidates without a background record get one,
 * so records entered in the application are kept. Never in production.
 *
 * php artisan db:seed --class=DemoBackgroundsSeeder
 */
class DemoBackgroundsSeeder extends Seeder
{
    private const DEGREES = [
        ['BS Criminology', 'State University of the North'],
        ['BS Information Technology', 'City Polytechnic College'],
        ['BS Civil Engineering', 'Provincial State University'],
        ['BS Psychology', 'Central Luzon College'],
        ['BS Accountancy', 'Metro Business College'],
        ['AB Political Science', 'State University of the North'],
        ['BS Nursing', 'Regional Health Sciences College'],
        ['BS Mechanical Engineering', 'City Polytechnic College'],
    ];

    private const PLACES = ['Tarlac City, Tarlac', 'Baguio City, Benguet', 'Angeles City, Pampanga', 'Dagupan City, Pangasinan', 'Cabanatuan City, Nueva Ecija', 'Quezon City, Metro Manila'];

    private const RELATIONS = ['Mother', 'Father', 'Spouse', 'Sister', 'Brother'];

    private const SERVICE = ['ROTC Advance Course graduate', 'Army reservist (2 years)', 'None', 'Philippine Navy enlisted personnel (3 years)', 'None'];

    private const OCCUPATIONS = ['Police officer', 'IT support specialist', 'Site engineer', 'Guidance associate', 'Bookkeeper', 'Call center agent', 'Staff nurse', 'Maintenance technician'];

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('Demo backgrounds must not be seeded in production.');
        }

        Candidate::query()->whereDoesntHave('background')->orderBy('candidate_number')->get()->values()->each(function (Candidate $candidate, int $index): void {
            $background = new CandidateBackground([
                'date_of_birth' => sprintf('%d-%02d-%02d', 1998 + $index % 6, 1 + ($index * 5) % 12, 1 + ($index * 7) % 28),
                'place_of_birth' => self::PLACES[$index % count(self::PLACES)],
                'sex' => $index % 4 === 1 ? 'female' : 'male',
                'civil_status' => $index % 5 === 3 ? 'married' : 'single',
                'home_address' => sprintf('%d Sample Street, Barangay %d, %s', 10 + $index * 3, 1 + $index % 9, self::PLACES[($index + 2) % count(self::PLACES)]),
                // 0917-555-01xx: a fictional mobile range for demos.
                'mobile_number' => sprintf('0917 555 01%02d', $index),
                'personal_email' => strtolower($candidate->candidate_number).'@example.org',
                'emergency_contact_name' => self::RELATIONS[$index % count(self::RELATIONS)].' of '.$candidate->first_name,
                'emergency_contact_relationship' => self::RELATIONS[$index % count(self::RELATIONS)],
                'emergency_contact_phone' => sprintf('0918 555 02%02d', $index),
                'eligibility' => $index % 3 === 0 ? 'Civil Service Professional' : ($index % 3 === 1 ? 'Licensed professional (PRC)' : null),
                'prior_service' => self::SERVICE[$index % count(self::SERVICE)],
                'previous_occupation' => self::OCCUPATIONS[$index % count(self::OCCUPATIONS)],
            ]);
            $background->candidate()->associate($candidate);
            $background->save();

            [$degree, $school] = self::DEGREES[$index % count(self::DEGREES)];
            $entries = [['level' => 'bachelor', 'degree' => $degree, 'school' => $school, 'year_graduated' => 2019 + $index % 5, 'honors' => $index % 6 === 2 ? 'Cum Laude' : null]];
            if ($index % 7 === 4) {
                $entries[] = ['level' => 'master', 'degree' => 'Master in Public Administration', 'school' => 'State University of the North', 'year_graduated' => 2024, 'honors' => null];
            }
            foreach ($entries as $position => $entry) {
                $row = new CandidateEducation([...$entry, 'position' => $position + 1]);
                $row->candidate()->associate($candidate);
                $row->save();
            }
        });
    }
}
