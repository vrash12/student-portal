<?php

namespace Tests\Feature;

use App\Support\PdfDocument;
use Tests\TestCase;

/**
 * Every PDF carries the school logo as a watermark at 20% opacity (owner
 * request, 2026-10-03); without a usable local logo, no watermark is drawn.
 */
class PdfWatermarkTest extends TestCase
{
    private const HTML = '<p>Page one</p><div style="page-break-before: always">Page two</div>';

    public function test_every_page_carries_the_logo_at_twenty_percent_opacity(): void
    {
        config(['institution.logo_url' => '/branding/logo.jpg']);

        $pdf = PdfDocument::render(self::HTML, 'portrait');

        $this->assertStringContainsString('/CA 0.2', $pdf);
        $this->assertStringContainsString('/BM /Multiply', $pdf);
        // One image object, drawn on both pages.
        $this->assertSame(1, substr_count($pdf, '/Subtype /Image'));
        $this->assertSame(2, preg_match_all('~/Type /Page\b(?!s)~', $pdf));
    }

    public function test_no_watermark_without_a_local_logo(): void
    {
        foreach ([null, 'https://example.test/logo.png', '/../.env', '/branding/missing.png', '/favicon.ico'] as $url) {
            config(['institution.logo_url' => $url]);

            $this->assertNull(PdfDocument::logoPath());
            $this->assertStringNotContainsString('/CA 0.2', PdfDocument::render(self::HTML, 'portrait'));
        }
    }
}
