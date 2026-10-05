<?php

namespace App\Support;

use App\Enums\CandidateStatus;
use App\Models\Candidate;
use Illuminate\Support\Facades\Storage;

/**
 * The candidate's ID card (owner request, 2026-10-05): a vertical card of the
 * standard ID size (CR80, 54 x 85.6 mm), shown on the staff side and saved
 * as a PDF for printing. Administrators only for now.
 *
 * Front: logo and organization, picture, name, candidate number, class and
 * campus, valid until the end of the class's academic year. Back: "Official
 * Identification Card" with the institution's core values (the organization
 * is named once, on the front), the candidate's attendance QR code
 * (CandidateQrCode), the emergency contact from the background record, a
 * return notice and a signature line.
 *
 * The same values feed the page and the PDF, so both always agree.
 */
final class CandidateIdCard
{
    /** Pictures are scaled down to this many pixels high for the PDF. */
    private const PHOTO_HEIGHT_PIXELS = 480;

    /** The picture frame on the card is 17 wide by 20 high (the PDF cannot crop, so GD does). */
    public const PHOTO_ASPECT = 17 / 20;

    /** Candidates who get a card in a class sheet: those still in training. */
    public const IN_TRAINING = [CandidateStatus::Enrolled, CandidateStatus::OnLeave];

    /**
     * @return array<string, mixed>
     */
    public static function data(Candidate $candidate): array
    {
        $candidate->loadMissing(['classBatch.academicPeriod', 'campus', 'background']);
        $background = $candidate->background;
        $emergencyName = $background?->emergency_contact_name;
        $emergencyPhone = $background?->emergency_contact_phone;

        $card = [
            'number' => $candidate->candidate_number,
            'lastName' => $candidate->last_name,
            'givenNames' => implode(' ', array_filter(
                [$candidate->first_name, $candidate->middle_name, $candidate->suffix],
                fn (?string $part): bool => $part !== null && $part !== '',
            )),
            'name' => $candidate->full_name,
            'roleLabel' => (string) config('institution.id_card.role_label'),
            'className' => $candidate->classBatch?->name,
            'campusName' => $candidate->campus?->name,
            'campusAddress' => $candidate->campus?->address,
            'validUntil' => $candidate->classBatch?->academicPeriod?->ends_on?->toDateString(),
            'emergencyContact' => $emergencyName === null && $emergencyPhone === null ? null : [
                'name' => $emergencyName,
                'relationship' => $background?->emergency_contact_relationship,
                'phone' => $emergencyPhone,
            ],
            'organization' => (string) config('institution.organization_name'),
            'systemName' => (string) config('institution.system_name'),
            // The same values as the sign-in page (INSTITUTION_CORE_VALUES).
            'coreValues' => array_values(array_map('strval', (array) config('institution.login.core_values'))),
        ];

        return [...$card, 'textSizes' => self::textSizes($card)];
    }

    /**
     * Font sizes in points that keep long names and class lines on one line
     * of the small card (the page draws the card at 2 px per point).
     *
     * @param  array<string, mixed>  $card
     * @return array{lastName: float, givenNames: float, meta: float}
     */
    private static function textSizes(array $card): array
    {
        $lastName = mb_strlen((string) $card['lastName']);
        $given = mb_strlen((string) $card['givenNames']);
        $meta = mb_strlen(implode(' · ', array_filter([$card['className'], $card['campusName']])));

        return [
            'lastName' => match (true) {
                $lastName > 20 => 7.0,
                $lastName > 14 => 8.5,
                default => 10.5,
            },
            'givenNames' => match (true) {
                $given > 34 => 5.6,
                $given > 26 => 6.4,
                default => 7.2,
            },
            'meta' => match (true) {
                $meta > 44 => 4.2,
                $meta > 34 => 4.8,
                default => 5.6,
            },
        ];
    }

    /**
     * What the card is missing, so staff can complete the record first.
     *
     * @return list<string>
     */
    public static function missing(Candidate $candidate): array
    {
        $data = self::data($candidate);

        return array_values(array_filter([
            $candidate->profile_photo_path === null ? 'No picture: add one on Edit Candidate.' : null,
            $data['validUntil'] === null ? 'No class: the card shows no validity date.' : null,
            $data['emergencyContact'] === null ? 'No emergency contact: add one on Edit Background.' : null,
        ]));
    }

    /** The picture as a small JPEG or PNG data address for the PDF, or null. */
    public static function photoDataUri(Candidate $candidate): ?string
    {
        $path = $candidate->profile_photo_path;
        if ($path === null || ! Storage::disk('local')->exists($path)) {
            return null;
        }
        $file = Storage::disk('local')->path($path);
        $mime = mime_content_type($file);
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return null;
        }

        // Without GD the picture is used as it is (WebP is left out: the PDF cannot draw it).
        return self::resized($file)
            ?? ($mime === 'image/webp' ? null : 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($file)));
    }

    /** The brass ring drawn around the logo on the card (IdCardArtwork's palette). */
    private const LOGO_RING = [201, 161, 59];

    /**
     * The configured logo cut to a circle with transparent corners and a
     * brass ring (PNG), so a round emblem sits cleanly on the card's
     * camouflage band; the logo as it is without GD; null without a logo.
     */
    public static function logoDataUri(): ?string
    {
        $path = PdfDocument::logoPath();
        if ($path === null || ! function_exists('imagecreatefromstring')) {
            return PdfDocument::logo();
        }
        $source = @imagecreatefromstring((string) file_get_contents($path));
        if ($source === false) {
            return PdfDocument::logo();
        }

        $size = 240;
        $side = min(imagesx($source), imagesy($source));
        $logo = imagecreatetruecolor($size, $size);
        imagealphablending($logo, false);
        imagesavealpha($logo, true);
        imagecopyresampled($logo, $source, 0, 0, intdiv(imagesx($source) - $side, 2), intdiv(imagesy($source) - $side, 2), $size, $size, $side, $side);

        $transparent = (int) imagecolorallocatealpha($logo, 255, 255, 255, 127);
        $radius = $size / 2 - 1;
        $centre = ($size - 1) / 2;
        $ring = (int) imagecolorallocate($logo, ...self::LOGO_RING);
        $ringWidth = 10;
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                $distance = sqrt(($x - $centre) ** 2 + ($y - $centre) ** 2);
                if ($distance > $radius) {
                    imagesetpixel($logo, $x, $y, $transparent);
                } elseif ($distance > $radius - $ringWidth) {
                    imagesetpixel($logo, $x, $y, $ring);
                }
            }
        }

        ob_start();
        imagepng($logo);
        $png = (string) ob_get_clean();

        return $png === '' ? PdfDocument::logo() : 'data:image/png;base64,'.base64_encode($png);
    }

    public static function qrDataUri(Candidate $candidate): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode(CandidateQrCode::svg($candidate, 360));
    }

    /**
     * The picture cropped to the card's frame from the centre (a little
     * above it, where the face usually is), scaled down and saved as JPEG
     * with GD; null without GD or when the file cannot be read.
     */
    private static function resized(string $file): ?string
    {
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }
        $image = @imagecreatefromstring((string) file_get_contents($file));
        if ($image === false) {
            return null;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        // The largest frame-shaped area of the picture.
        $cropWidth = (int) min($width, round($height * self::PHOTO_ASPECT));
        $cropHeight = (int) min($height, round($cropWidth / self::PHOTO_ASPECT));
        $cropX = intdiv($width - $cropWidth, 2);
        $cropY = (int) round(($height - $cropHeight) * 0.35);

        $targetHeight = min(self::PHOTO_HEIGHT_PIXELS, $cropHeight);
        $target = imagecreatetruecolor(max(1, (int) round($targetHeight * self::PHOTO_ASPECT)), max(1, $targetHeight));
        // A white background under transparent PNG/WebP pixels.
        imagefill($target, 0, 0, (int) imagecolorallocate($target, 255, 255, 255));
        imagecopyresampled($target, $image, 0, 0, $cropX, $cropY, imagesx($target), imagesy($target), $cropWidth, $cropHeight);

        ob_start();
        imagejpeg($target, null, 88);
        $jpeg = (string) ob_get_clean();

        return $jpeg === '' ? null : 'data:image/jpeg;base64,'.base64_encode($jpeg);
    }
}
