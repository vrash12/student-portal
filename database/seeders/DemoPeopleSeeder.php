<?php

namespace Database\Seeders;

use App\Models\Candidate;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Fictional Filipino names and illustrated profile pictures for the client
 * demo set (owner request, 2026-10-02). Usernames and candidate numbers do
 * not change (student01 … student20, instructor1, instructor2, admin), so
 * demo sign-ins keep working. The North Campus candidates (DemoCampusSeeder)
 * get pictures too.
 *
 * Only placeholder records are changed: candidates still named "Student NN"
 * and staff still named "Instructor One/Two" or "Administrator"; names
 * edited in the application are kept. Pictures are added only to
 * candidates without one (an uploaded photo is never replaced). The
 * pictures are generated drawings (no photographs of real people, no
 * insignia). Safe to run again. Never runs in production.
 *
 * php artisan db:seed --class=DemoPeopleSeeder
 */
class DemoPeopleSeeder extends Seeder
{
    /** Display names of the demo staff accounts, by username. */
    public const STAFF = [
        'admin' => 'Teresita P. Vergara',
        'instructor1' => 'Ramon S. Estrada',
        'instructor2' => 'Liza M. Tan',
    ];

    private const PLACEHOLDER_STAFF = ['Administrator', 'Instructor One', 'Instructor Two'];

    /**
     * Fictional candidates by candidate number: first, middle, last, suffix, female.
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: ?string, 4: bool}>
     */
    public const CANDIDATES = [
        'student01' => ['Mark Anthony', 'Dizon', 'Villanueva', null, false],
        'student02' => ['Kristine Mae', 'Lopez', 'Bautista', null, true],
        'student03' => ['John Paul', 'Garcia', 'Mendoza', null, false],
        'student04' => ['Maria Angelica', 'Torres', 'Fernandez', null, true],
        'student05' => ['Christian Jay', 'Ramos', 'Aquino', null, false],
        'student06' => ['Jessa Mae', 'Castillo', 'Navarro', null, true],
        'student07' => ['Rafael', 'Domingo', 'Cruz', null, false],
        'student08' => ['Princess Joy', 'Manalo', 'Soriano', null, true],
        'student09' => ['Jerome', 'Pascual', 'Del Rosario', null, false],
        'student10' => ['Andrea Nicole', 'Salazar', 'Gonzales', null, true],
        'student11' => ['Ramil', 'Tolentino', 'Macaraeg', null, false],
        'student12' => ['Camille Joy', 'Dela Peña', 'Ocampo', null, true],
        'student13' => ['Arnel', 'Bernardo', 'Sison', null, false],
        'student14' => ['Rose Ann', 'Cabrera', 'Valdez', null, true],
        'student15' => ['Paolo Enrique', 'Agustin', 'Mercado', null, false],
        'student16' => ['Hazel Mae', 'Ferrer', 'Aguilar', null, true],
        'student17' => ['Kevin Lloyd', 'Mariano', 'Santiago', null, false],
        'student18' => ['Erika Jane', 'Morales', 'Rivera', null, true],
        'student19' => ['Noel', 'Bautista', 'Francisco', 'Jr.', false],
        'student20' => ['Lovely Grace', 'Medina', 'Espiritu', null, true],
    ];

    /** Warm skin tones (RGB). */
    private const SKIN = [[198, 142, 99], [185, 127, 86], [212, 160, 122], [168, 114, 76], [224, 180, 143], [176, 124, 88]];

    /** Soft backgrounds (RGB). */
    private const BACKGROUNDS = [[214, 228, 220], [222, 226, 236], [232, 226, 214], [218, 232, 236], [228, 222, 232], [226, 234, 216]];

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Demo people must not be seeded in production.');
        }

        foreach (self::STAFF as $username => $name) {
            User::query()->where('username', $username)->whereIn('name', self::PLACEHOLDER_STAFF)->update(['name' => $name]);
        }

        $candidates = Candidate::query()->with('user')->whereIn('candidate_number', array_keys(self::CANDIDATES))->get();
        foreach ($candidates as $candidate) {
            [$first, $middle, $last, $suffix, $female] = self::CANDIDATES[$candidate->candidate_number];

            DB::transaction(function () use ($candidate, $first, $middle, $last, $suffix, $female): void {
                if ($candidate->first_name === 'Student' && ctype_digit((string) $candidate->last_name)) {
                    $candidate->forceFill(['first_name' => $first, 'middle_name' => $middle, 'last_name' => $last, 'suffix' => $suffix])->save();
                    $candidate->user?->forceFill(['name' => $candidate->full_name])->save();
                }

                self::addPortrait($candidate, $female);
            });
        }

        // The second demo campus's candidates already have their names.
        $northCandidates = Candidate::query()->whereIn('candidate_number', array_keys(DemoCampusSeeder::CANDIDATES))->get();
        foreach ($northCandidates as $candidate) {
            self::addPortrait($candidate, DemoCampusSeeder::CANDIDATES[$candidate->candidate_number][3]);
        }
    }

    /** A drawn picture for a candidate without one (an uploaded photo is never replaced). */
    private static function addPortrait(Candidate $candidate, bool $female): void
    {
        if ($candidate->profile_photo_path !== null) {
            return;
        }

        $path = 'candidate-photos/demo-'.$candidate->candidate_number.'.png';
        Storage::disk('local')->put($path, self::portrait($candidate->candidate_number, $female));
        $candidate->forceFill(['profile_photo_path' => $path])->save();
    }

    /**
     * An illustrated head-and-shoulders portrait (PNG, 480 x 480), the same
     * for the same seed. Drawn at three times the size and scaled down so
     * the edges are smooth.
     */
    public static function portrait(string $seed, bool $female): string
    {
        mt_srand(crc32($seed));
        $scale = 3;
        $size = 480 * $scale;
        $image = imagecreatetruecolor($size, $size);
        $color = fn (array $rgb): int => imagecolorallocate($image, $rgb[0], $rgb[1], $rgb[2]);
        $shade = fn (array $rgb, float $factor): array => array_map(fn (int $value): int => max(0, min(255, (int) round($value * $factor))), $rgb);
        $s = fn (float $value): int => (int) round($value * $scale);

        $background = self::BACKGROUNDS[mt_rand(0, count(self::BACKGROUNDS) - 1)];
        $skin = self::SKIN[mt_rand(0, count(self::SKIN) - 1)];
        $hair = [mt_rand(18, 34), mt_rand(13, 24), mt_rand(10, 18)];
        $uniform = [mt_rand(52, 66), mt_rand(70, 84), mt_rand(44, 56)];
        imagefill($image, 0, 0, $color($background));

        // Shoulders: uniform shirt with a lighter undershirt at the collar.
        imagefilledellipse($image, $s(240), $s(500), $s(420), $s(300), $color($uniform));
        imagefilledpolygon($image, [$s(196), $s(360), $s(284), $s(360), $s(240), $s(432)], $color($shade($uniform, 0.82)));
        imagefilledpolygon($image, [$s(212), $s(358), $s(268), $s(358), $s(240), $s(406)], $color([240, 238, 230]));
        imagefilledpolygon($image, [$s(170), $s(352), $s(240), $s(432), $s(214), $s(450), $s(160), $s(372)], $color($shade($uniform, 1.12)));
        imagefilledpolygon($image, [$s(310), $s(352), $s(240), $s(432), $s(266), $s(450), $s(320), $s(372)], $color($shade($uniform, 1.12)));

        // Neck and head.
        imagefilledrectangle($image, $s(210), $s(300), $s(270), $s(372), $color($shade($skin, 0.9)));
        $hairColor = $color($hair);
        if ($female) {
            // Hair gathered in a bun behind the head.
            imagefilledellipse($image, $s(240), $s(104), $s(96), $s(80), $hairColor);
            imagefilledellipse($image, $s(240), $s(200), $s(192), $s(212), $hairColor);
        }
        imagefilledellipse($image, $s(148), $s(214), $s(30), $s(48), $color($shade($skin, 0.93)));
        imagefilledellipse($image, $s(332), $s(214), $s(30), $s(48), $color($shade($skin, 0.93)));
        imagefilledellipse($image, $s(240), $s(212), $s(178), $s(220), $color($skin));

        // Hair: short cut, or swept back for the bun.
        if ($female) {
            imagefilledarc($image, $s(240), $s(198), $s(188), $s(206), 180, 360, $hairColor, IMG_ARC_PIE);
            imagefilledpolygon($image, [$s(150), $s(196), $s(176), $s(150), $s(170), $s(232)], $hairColor);
            imagefilledpolygon($image, [$s(330), $s(196), $s(304), $s(150), $s(310), $s(232)], $hairColor);
        } else {
            imagefilledarc($image, $s(240), $s(182), $s(186), $s(176), 180, 360, $hairColor, IMG_ARC_PIE);
            imagefilledrectangle($image, $s(150), $s(170), $s(166), $s(206), $hairColor);
            imagefilledrectangle($image, $s(314), $s(170), $s(330), $s(206), $hairColor);
        }

        // Face: brows, eyes, nose, mouth.
        $dark = $color([36, 28, 24]);
        imagesetthickness($image, $s(5));
        $browY = $s(196 + mt_rand(-3, 3));
        imageline($image, $s(184), $browY, $s(220), $browY - $s(4), $hairColor);
        imageline($image, $s(260), $browY - $s(4), $s(296), $browY, $hairColor);
        imagefilledellipse($image, $s(203), $s(220), $s(16), $s(13), $dark);
        imagefilledellipse($image, $s(277), $s(220), $s(16), $s(13), $dark);
        imagesetthickness($image, $s(4));
        $nose = $color($shade($skin, 0.78));
        imagearc($image, $s(240), $s(250), $s(26), $s(22), 20, 160, $nose);
        $mouth = $color([150, 78, 70]);
        imagesetthickness($image, $s(5));
        imagearc($image, $s(240), $s(272), $s(58), $s(28 + mt_rand(0, 10)), 20, 160, $mouth);

        $small = imagecreatetruecolor(480, 480);
        imagecopyresampled($small, $image, 0, 0, 0, 0, 480, 480, $size, $size);
        ob_start();
        imagepng($small, null, 9);
        $png = (string) ob_get_clean();
        imagedestroy($image);
        imagedestroy($small);
        mt_srand();

        return $png;
    }
}
