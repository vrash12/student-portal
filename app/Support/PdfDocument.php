<?php

namespace App\Support;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\File;

/**
 * Shared PDF rendering for system documents: no remote resources, scripts
 * or PHP; fonts and the configured logo come from local files only.
 */
final class PdfDocument
{
    public static function render(string $html, string $orientation): string
    {
        $cache = storage_path('framework/cache/pdf');
        File::ensureDirectoryExists($cache);
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setIsFontSubsettingEnabled(true);
        $options->setDefaultFont('DejaVu Sans');
        $options->setChroot([public_path('branding'), base_path('vendor/dompdf/dompdf/lib/fonts')]);
        $options->setTempDir($cache);
        $options->setFontCache($cache);

        $pdf = new Dompdf($options);
        $pdf->setPaper('letter', $orientation);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->render();
        [$x, $y] = $orientation === 'landscape' ? [700, 590] : [520, 770];
        $pdf->getCanvas()->page_text($x, $y, 'Page {PAGE_NUM} of {PAGE_COUNT}', $pdf->getFontMetrics()->getFont('DejaVu Sans'), 7, [0.35, 0.39, 0.43]);

        return $pdf->output();
    }

    /** Embed only a configured local raster logo; never request remote resources. */
    public static function logo(): ?string
    {
        $url = config('institution.logo_url');
        if (! is_string($url) || ! str_starts_with($url, '/') || str_starts_with($url, '//')) {
            return null;
        }
        $root = realpath(public_path());
        $path = realpath(public_path(ltrim($url, '/')));
        if ($root === false || $path === false || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR) || ! is_file($path) || filesize($path) > 5 * 1024 * 1024) {
            return null;
        }
        $mime = mime_content_type($path);
        if (! in_array($mime, ['image/png', 'image/jpeg'], true)) {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($path));
    }
}
