<?php

namespace App\Support;

use App\Models\Candidate;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Str;

/**
 * A candidate's QR code (owner request, 2026-10-02): the candidate's
 * identification for attendance. It holds the address /q/<token>; the token
 * is random (32 letters and digits) and says nothing about the candidate.
 *
 * The attendance scanner reads the token from the code, whatever the host in
 * the address. Opened with a phone camera, the address leads signed-in staff
 * who may see the candidate to the candidate's profile, and nobody else
 * anywhere. Reissuing a code (a lost card) replaces the token, so the old code
 * stops working. Drawn on the server as SVG (bacon/bacon-qr-code), no
 * internet needed.
 */
final class CandidateQrCode
{
    public const TOKEN_LENGTH = 32;

    public static function newToken(): string
    {
        do {
            $token = Str::random(self::TOKEN_LENGTH);
        } while (Candidate::query()->where('qr_token', $token)->exists());

        return $token;
    }

    /** The text inside the code: the candidate's QR address on this server. */
    public static function payload(Candidate $candidate): string
    {
        return route('qr.open', ['token' => (string) $candidate->qr_token]);
    }

    public static function svg(Candidate $candidate, int $size = 320): string
    {
        $renderer = new ImageRenderer(new RendererStyle($size, 4), new SvgImageBackEnd);

        return (new Writer($renderer))->writeString(self::payload($candidate), 'UTF-8', ErrorCorrectionLevel::M());
    }

    /**
     * The token in what a scanner read: the QR address (any host, for codes
     * printed before a move to another server) or the bare token.
     */
    public static function tokenFrom(string $scanned): ?string
    {
        $value = trim($scanned);
        if (preg_match('~/q/([A-Za-z0-9]{32})/?$~', $value, $match) === 1) {
            return $match[1];
        }

        return preg_match('/^[A-Za-z0-9]{32}$/', $value) === 1 ? $value : null;
    }

    public static function find(string $scanned): ?Candidate
    {
        $token = self::tokenFrom($scanned);

        return $token === null ? null : Candidate::query()->where('qr_token', $token)->first();
    }
}
