<?php

namespace Tests\Unit;

use App\Support\Totp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TotpTest extends TestCase
{
    /** The RFC 6238 Appendix B secret "12345678901234567890" (ASCII) in Base32. */
    private const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    /**
     * @return array<string, array{int, string}>
     */
    public static function rfcVectors(): array
    {
        return [
            '59' => [59, '94287082'],
            '1111111109' => [1111111109, '07081804'],
            '1111111111' => [1111111111, '14050471'],
            '1234567890' => [1234567890, '89005924'],
            '2000000000' => [2000000000, '69279037'],
            '20000000000' => [20000000000, '65353130'],
        ];
    }

    #[DataProvider('rfcVectors')]
    public function test_codes_match_the_rfc_6238_test_vectors(int $time, string $expected): void
    {
        $this->assertSame($expected, Totp::codeAt(self::RFC_SECRET, Totp::stepAt($time), 8));
        // Six digits (what the apps show) are the last six of the same value.
        $this->assertSame(substr($expected, 2), Totp::codeAt(self::RFC_SECRET, Totp::stepAt($time)));
    }

    public function test_base32_round_trips_and_matches_rfc_4648(): void
    {
        $this->assertSame(self::RFC_SECRET, Totp::base32Encode('12345678901234567890'));
        $this->assertSame('12345678901234567890', Totp::base32Decode(self::RFC_SECRET));
        $this->assertSame('MZXW6YTBOI', Totp::base32Encode('foobar'));
        $this->assertSame('foobar', Totp::base32Decode('mzxw 6ytb oi======'));

        $secret = Totp::generateSecret();
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
        $this->assertSame(20, strlen(Totp::base32Decode($secret)));
    }

    public function test_an_invalid_secret_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Totp::base32Decode('NOT-BASE32-1');
    }

    public function test_codes_of_the_neighbouring_steps_are_accepted_and_older_ones_are_not(): void
    {
        $now = 1_759_650_000;
        $step = Totp::stepAt($now);

        $this->assertSame($step, Totp::matchingStep(self::RFC_SECRET, Totp::codeAt(self::RFC_SECRET, $step), $now));
        $this->assertSame($step - 1, Totp::matchingStep(self::RFC_SECRET, Totp::codeAt(self::RFC_SECRET, $step - 1), $now));
        $this->assertSame($step + 1, Totp::matchingStep(self::RFC_SECRET, Totp::codeAt(self::RFC_SECRET, $step + 1), $now));
        $this->assertNull(Totp::matchingStep(self::RFC_SECRET, Totp::codeAt(self::RFC_SECRET, $step - 2), $now));
        $this->assertNull(Totp::matchingStep(self::RFC_SECRET, Totp::codeAt(self::RFC_SECRET, $step - 1), $now, window: 0));
    }

    public function test_typed_codes_are_normalized(): void
    {
        $this->assertSame('492817', Totp::normalizeCode(' 492 817 '));
        $this->assertSame('492817', Totp::normalizeCode('492-817'));
        $this->assertNull(Totp::normalizeCode('49281'));
        $this->assertNull(Totp::normalizeCode('4928170'));
        $this->assertNull(Totp::normalizeCode('49281a'));
    }

    public function test_the_provisioning_address_follows_the_key_uri_format(): void
    {
        $uri = Totp::provisioningUri(self::RFC_SECRET, 'OCS Training', 'admin');

        $this->assertSame('otpauth://totp/OCS%20Training:admin?secret='.self::RFC_SECRET.'&issuer=OCS%20Training&algorithm=SHA1&digits=6&period=30', $uri);
        $this->assertSame('ABCD EFGH IJ', Totp::formatSecret('ABCDEFGHIJ'));
        $this->assertStringStartsWith('<?xml', Totp::qrSvg($uri));
    }
}
