<?php

namespace App\Support;

use Dompdf\Canvas;
use Dompdf\Dompdf;
use Dompdf\FontMetrics;
use Dompdf\Options;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Shared PDF rendering for system documents: no remote resources, scripts
 * or PHP; fonts and the configured logo come from local files only.
 *
 * Every page carries the school logo as a watermark at 20% opacity (owner
 * request, 2026-10-03: the Print buttons became Save as PDF, and every PDF
 * is watermarked).
 */
final class PdfDocument
{
    /** Opacity of the logo watermark on every page. */
    public const WATERMARK_OPACITY = 0.2;

    /** Watermark width as a share of the page's shorter side. */
    private const WATERMARK_SIZE = 0.6;

    private const MAX_LOGO_BYTES = 5 * 1024 * 1024;

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
        $canvas = $pdf->getCanvas();
        self::watermark($canvas);
        [$x, $y] = $orientation === 'landscape' ? [700, 590] : [520, 770];
        $canvas->page_text($x, $y, 'Page {PAGE_NUM} of {PAGE_COUNT}', $pdf->getFontMetrics()->getFont('DejaVu Sans'), 7, [0.35, 0.39, 0.43]);

        return $pdf->output();
    }

    /**
     * A PDF file download, never cached by the browser or a proxy.
     */
    public static function download(string $html, string $orientation, string $filename): Response
    {
        $name = (Str::slug(pathinfo($filename, PATHINFO_FILENAME)) ?: 'document').'.pdf';

        return response(self::render($html, $orientation), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Embed only a configured local raster logo; never request remote resources. */
    public static function logo(): ?string
    {
        $path = self::logoPath();
        if ($path === null) {
            return null;
        }

        return 'data:'.mime_content_type($path).';base64,'.base64_encode((string) file_get_contents($path));
    }

    /**
     * The configured logo as a file under public/, if it is a PNG or JPEG of
     * at most 5 MB; null otherwise (no logo configured, a remote address, or
     * a path leaving public/).
     */
    public static function logoPath(): ?string
    {
        $url = config('institution.logo_url');
        if (! is_string($url) || ! str_starts_with($url, '/') || str_starts_with($url, '//')) {
            return null;
        }
        $root = realpath(public_path());
        $path = realpath(public_path(ltrim($url, '/')));
        if ($root === false || $path === false || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR) || ! is_file($path) || filesize($path) > self::MAX_LOGO_BYTES) {
            return null;
        }

        return in_array(mime_content_type($path), ['image/png', 'image/jpeg'], true) ? $path : null;
    }

    /**
     * The logo, centered on every page at 20% opacity. Multiply keeps text
     * drawn under it fully dark while the white page shows the faint logo.
     */
    private static function watermark(Canvas $canvas): void
    {
        $path = self::logoPath();
        $size = $path === null ? false : @getimagesize($path);
        if ($path === null || $size === false || $size[0] <= 0 || $size[1] <= 0) {
            return;
        }

        $canvas->page_script(function (int $pageNumber, int $pageCount, Canvas $page, FontMetrics $fontMetrics) use ($path, $size): void {
            $pageWidth = $page->get_width();
            $pageHeight = $page->get_height();
            $width = min($pageWidth, $pageHeight) * self::WATERMARK_SIZE;
            $height = $width * $size[1] / $size[0];
            if ($height > $pageHeight * self::WATERMARK_SIZE) {
                $height = $pageHeight * self::WATERMARK_SIZE;
                $width = $height * $size[0] / $size[1];
            }

            $page->set_opacity(self::WATERMARK_OPACITY, 'Multiply');
            $page->image($path, ($pageWidth - $width) / 2, ($pageHeight - $height) / 2, $width, $height);
            $page->set_opacity(1.0);
        });
    }
}
