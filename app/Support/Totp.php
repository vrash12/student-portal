<?php

namespace App\Support;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * Time-based one-time passwords (TOTP, RFC 6238 over HOTP, RFC 4226) with
 * the settings every authenticator app uses by default: HMAC-SHA1, 30-second
 * steps, 6 digits. The phone and the server compute the same code from the
 * shared secret and the time, so nothing travels between them at sign-in
 * and no internet is needed.
 *
 * Secrets are 20 random bytes (160 bits, the RFC's recommendation), written
 * in Base32 (RFC 4648) for the QR code and for typing by hand.
 */
final class Totp
{
    public const PERIOD_SECONDS = 30;

    public const DIGITS = 6;

    private const SECRET_BYTES = 20;

    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(self::SECRET_BYTES));
    }

    /** The 30-second step that contains the given Unix time. */
    public static function stepAt(int $unixTime): int
    {
        return intdiv($unixTime, self::PERIOD_SECONDS);
    }

    /** The code of one step (RFC 4226 §5.3 dynamic truncation). */
    public static function codeAt(string $secret, int $step, int $digits = self::DIGITS): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), self::base32Decode($secret), true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);

        return str_pad((string) ($value % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    /**
     * The step whose code matches, looking $window steps either side of the
     * current one, or null. Every candidate step is compared (constant time)
     * so the answer takes as long whichever step matches.
     */
    public static function matchingStep(string $secret, string $code, int $unixTime, int $window = 1): ?int
    {
        $code = self::normalizeCode($code);
        if ($code === null) {
            return null;
        }

        $current = self::stepAt($unixTime);
        $matched = null;
        for ($step = $current - $window; $step <= $current + $window; $step++) {
            if (hash_equals(self::codeAt($secret, $step), $code) && $matched === null) {
                $matched = $step;
            }
        }

        return $matched;
    }

    /** Six digits, ignoring spaces and dashes typed for readability ("492 817"). */
    public static function normalizeCode(string $code): ?string
    {
        $digits = preg_replace('/[\s-]+/', '', $code) ?? '';

        return preg_match('/^\d{'.self::DIGITS.'}$/', $digits) === 1 ? $digits : null;
    }

    /**
     * The otpauth:// address an authenticator app reads from the QR code
     * (Key Uri Format): "Issuer: account" with the issuer repeated as a
     * parameter, which the apps use to group and label the code.
     */
    public static function provisioningUri(string $secret, string $issuer, string $account): string
    {
        $label = rawurlencode($issuer).':'.rawurlencode($account);

        return 'otpauth://totp/'.$label.'?'.http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD_SECONDS,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public static function qrSvg(string $uri, int $size = 240): string
    {
        $renderer = new ImageRenderer(new RendererStyle($size, 2), new SvgImageBackEnd);

        return (new Writer($renderer))->writeString($uri, 'UTF-8', ErrorCorrectionLevel::M());
    }

    /** The secret in groups of four for typing by hand: "JBSW Y3DP …". */
    public static function formatSecret(string $secret): string
    {
        return implode(' ', str_split($secret, 4));
    }

    public static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::BASE32_ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $encoded;
    }

    public static function base32Decode(string $encoded): string
    {
        $encoded = strtoupper(preg_replace('/[\s=-]+/', '', $encoded) ?? '');
        $bits = '';
        foreach (str_split($encoded) as $character) {
            $value = strpos(self::BASE32_ALPHABET, $character);
            if ($value === false) {
                throw new \InvalidArgumentException('The secret is not valid Base32.');
            }
            $bits .= str_pad(decbin($value), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr((int) bindec($chunk));
            }
        }

        return $bytes;
    }
}
