<?php

namespace Database\Seeders;

use App\Enums\SystemRole;
use App\Models\Candidate;
use App\Models\CandidateMedicalDocument;
use App\Models\User;
use App\Services\Medical\MedicalDocumentService;
use App\Support\PdfDocument;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Demo medical documents (owner request, 2026-10-02): fictional
 * certificates and check-up findings "uploaded" by some demo candidates,
 * in every review state, so the upload, review and view-only flows can be
 * shown. Every file says on its face that it is a fictional demo document.
 *
 * Safe to run again: a document with the same title for the same candidate
 * is not added twice. Never runs in production.
 *
 * php artisan db:seed --class=DemoMedicalDocumentSeeder
 */
class DemoMedicalDocumentSeeder extends Seeder
{
    /**
     * Candidate number => [title, category, date, findings, review (null waits, 'accept', or a return reason), photo?].
     *
     * @var array<string, list<array{0: string, 1: string, 2: string, 3: string, 4: ?string, 5?: bool}>>
     */
    private const DOCUMENTS = [
        'student01' => [
            ['Medical Certificate: Fit for Training', 'medical_certificate', '2026-07-20', 'Physically and mentally fit to undergo training. No restriction.', 'accept'],
            ['Annual Physical Examination Findings', 'checkup_findings', '2026-09-28', 'Blood pressure 118/76. Heart and lungs normal. Vision 20/20 both eyes. Fit for training.', null],
        ],
        'student02' => [
            ['Complete Blood Count', 'laboratory_result', '2026-09-25', 'Hemoglobin 14.2 g/dL. White blood cells 7.1 x10^9/L. Platelets 250 x10^9/L. All within normal range.', null],
        ],
        'student03' => [
            ['Chest X-ray Result', 'imaging', '2026-09-10', 'Lung fields clear. Heart not enlarged. Impression: normal chest.', 'The lower half of the page is cut off. Upload the full result, please.'],
        ],
        'student05' => [
            ['Vaccination Card', 'vaccination', '2026-08-02', 'Tetanus-diphtheria booster given. Hepatitis B series complete.', null, true],
        ],
        'student06' => [
            ['Medical Check-up Findings', 'checkup_findings', '2026-09-18', 'Mild exercise-induced asthma, controlled with an inhaler before strenuous activity. Fit for training with the inhaler available.', 'accept'],
            ['Pulmonary Function Test', 'laboratory_result', '2026-09-20', 'Mild reversible airway obstruction. Recommendation: inhaler before endurance runs; reassess in six months.', 'accept'],
        ],
    ];

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Demo medical documents must not be seeded in production.');
        }

        $admin = User::query()->where('username', 'admin')->first()
            ?? User::query()->whereHas('role', fn ($role) => $role->where('code', SystemRole::SuperAdministrator->value))->orderBy('id')->first();
        if ($admin === null) {
            $this->command?->warn('No administrator account: medical documents not seeded.');

            return;
        }

        $service = app(MedicalDocumentService::class);
        $candidates = Candidate::query()->whereIn('candidate_number', array_keys(self::DOCUMENTS))->with('user')->get()->keyBy('candidate_number');
        $temp = storage_path('framework/cache/demo-medical');
        File::ensureDirectoryExists($temp);

        foreach (self::DOCUMENTS as $number => $documents) {
            $candidate = $candidates->get($number);
            if ($candidate === null || $candidate->user === null) {
                continue;
            }
            foreach ($documents as $entry) {
                [$title, $category, $date, $findings, $review] = $entry;
                $photo = $entry[5] ?? false;
                if (CandidateMedicalDocument::query()->where('candidate_id', $candidate->id)->where('title', $title)->exists()) {
                    continue;
                }

                $path = $temp.DIRECTORY_SEPARATOR.uniqid('demo-', true).($photo ? '.png' : '.pdf');
                file_put_contents($path, $photo ? $this->photo($candidate, $title, $date, $findings) : $this->pdf($candidate, $title, $date, $findings));
                try {
                    $file = new UploadedFile($path, basename($path), null, null, true);
                    $document = $service->upload($candidate, $file, ['category' => $category, 'title' => $title, 'document_date' => $date, 'notes' => null], $candidate->user);
                } finally {
                    File::delete($path);
                }

                if ($review === 'accept') {
                    $service->accept($document, null, $admin);
                } elseif ($review !== null) {
                    $service->return($document, $review, $admin);
                }
            }
        }
    }

    private function pdf(Candidate $candidate, string $title, string $date, string $findings): string
    {
        $escape = fn (string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        return PdfDocument::render(<<<HTML
            <html><head><style>
                body { font-family: 'DejaVu Sans'; font-size: 11pt; color: #1f2937; }
                .demo { border: 2px dashed #b91c1c; color: #b91c1c; padding: 8px; text-align: center; font-weight: bold; }
                h1 { font-size: 16pt; margin: 24px 0 4px; }
                .muted { color: #6b7280; font-size: 9pt; }
                table { width: 100%; border-collapse: collapse; margin-top: 16px; }
                td { border: 1px solid #d1d5db; padding: 6px 8px; vertical-align: top; }
                td.label { width: 30%; background: #f3f4f6; font-weight: bold; }
            </style></head><body>
                <div class="demo">DEMO DOCUMENT · FICTIONAL · NOT A REAL MEDICAL RECORD</div>
                <h1>{$escape($title)}</h1>
                <p class="muted">Training Center Clinic (placeholder)</p>
                <table>
                    <tr><td class="label">Candidate</td><td>{$escape($candidate->full_name)} ({$escape($candidate->candidate_number)})</td></tr>
                    <tr><td class="label">Date</td><td>{$escape($date)}</td></tr>
                    <tr><td class="label">Findings</td><td>{$escape($findings)}</td></tr>
                    <tr><td class="label">Physician</td><td>Dr. Placeholder, Clinic Physician (fictional)</td></tr>
                </table>
                <p class="muted" style="margin-top: 32px">Generated for the system demonstration. It describes no real person.</p>
            </body></html>
            HTML, 'portrait');
    }

    /** A "photo" of a paper card, as a candidate would take with a tablet. */
    private function photo(Candidate $candidate, string $title, string $date, string $findings): string
    {
        $image = imagecreatetruecolor(1200, 800);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 236, 232, 222));
        $card = (int) imagecolorallocate($image, 255, 253, 247);
        imagefilledrectangle($image, 60, 60, 1140, 740, $card);
        $ink = (int) imagecolorallocate($image, 31, 41, 55);
        $red = (int) imagecolorallocate($image, 185, 28, 28);
        imagerectangle($image, 60, 60, 1140, 740, $ink);

        imagestring($image, 5, 100, 100, 'DEMO DOCUMENT - FICTIONAL - NOT A REAL MEDICAL RECORD', $red);
        imagestring($image, 5, 100, 160, strtoupper($title), $ink);
        imagestring($image, 4, 100, 220, 'Name: '.$candidate->full_name.' ('.$candidate->candidate_number.')', $ink);
        imagestring($image, 4, 100, 260, 'Date: '.$date, $ink);
        foreach (explode("\n", wordwrap($findings, 70, "\n", true)) as $line => $text) {
            imagestring($image, 4, 100, 320 + 32 * $line, $text, $ink);
        }
        imagestring($image, 4, 100, 660, 'Training Center Clinic (placeholder)', $ink);

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
