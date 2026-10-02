<?php

namespace App\Services\Backups;

use RuntimeException;

/**
 * Encrypts and decrypts backup files with a passphrase (AES-256-GCM,
 * PHP's OpenSSL extension), in chunks so large backups never sit in memory.
 *
 * File layout: a 32-byte header ("OCTMSBK" + format version, a random salt,
 * the PBKDF2 iteration count and the chunk size), then chunks of
 * [last-chunk flag][length][IV][tag][ciphertext]. Each chunk is
 * authenticated together with the header, its position and the flag, so a
 * changed, reordered or truncated file is refused, as is a wrong passphrase.
 */
final class BackupCipher
{
    private const MAGIC = "OCTMSBK\x01";

    private const CIPHER = 'aes-256-gcm';

    private const HEADER_BYTES = 32;

    private const CHUNK_BYTES = 1_048_576;

    private const ITERATIONS = 600_000;

    public function __construct(private readonly string $passphrase)
    {
        if ($passphrase === '') {
            throw new RuntimeException('The backup passphrase is not set (BACKUP_PASSPHRASE).');
        }
    }

    public function encrypt(string $sourcePath, string $targetPath): void
    {
        $salt = random_bytes(16);
        $header = self::MAGIC.$salt.pack('N', self::ITERATIONS).pack('N', self::CHUNK_BYTES);
        $key = $this->key($salt, self::ITERATIONS);

        $in = $this->open($sourcePath, 'rb');
        $out = $this->open($targetPath, 'wb');
        try {
            $this->write($out, $header);
            $index = 0;
            $chunk = $this->read($in, self::CHUNK_BYTES);
            do {
                $next = feof($in) ? '' : $this->read($in, self::CHUNK_BYTES);
                $last = $next === '' && feof($in);
                $iv = random_bytes(12);
                $tag = '';
                $cipherText = openssl_encrypt($chunk, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, $this->aad($header, $index, $last), 16);
                if ($cipherText === false) {
                    throw new RuntimeException('The backup could not be encrypted.');
                }
                $this->write($out, ($last ? "\x01" : "\x00").pack('N', strlen($cipherText)).$iv.$tag.$cipherText);
                $chunk = $next;
                $index++;
            } while (! $last);
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    /**
     * @throws RuntimeException when the passphrase is wrong or the file is damaged
     */
    public function decrypt(string $sourcePath, string $targetPath): void
    {
        $in = $this->open($sourcePath, 'rb');
        $out = $this->open($targetPath, 'wb');
        try {
            $header = $this->read($in, self::HEADER_BYTES);
            if (strlen($header) !== self::HEADER_BYTES || ! str_starts_with($header, self::MAGIC)) {
                throw new RuntimeException('This is not a backup file of this system, or it is damaged.');
            }
            $salt = substr($header, 8, 16);
            $iterations = unpack('N', substr($header, 24, 4))[1];
            $key = $this->key($salt, $iterations);

            $index = 0;
            $last = false;
            while (! $last) {
                $meta = $this->read($in, 33);
                if (strlen($meta) !== 33) {
                    throw new RuntimeException('The backup file is incomplete.');
                }
                $last = $meta[0] === "\x01";
                $length = unpack('N', substr($meta, 1, 4))[1];
                $iv = substr($meta, 5, 12);
                $tag = substr($meta, 17, 16);
                $cipherText = $length === 0 ? '' : $this->read($in, $length);
                if (strlen($cipherText) !== $length) {
                    throw new RuntimeException('The backup file is incomplete.');
                }
                $plain = openssl_decrypt($cipherText, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, $this->aad($header, $index, $last));
                if ($plain === false) {
                    throw new RuntimeException('The backup could not be decrypted: the passphrase is wrong or the file is damaged.');
                }
                $this->write($out, $plain);
                $index++;
            }
            if ($this->read($in, 1) !== '') {
                throw new RuntimeException('The backup file has unexpected data at its end.');
            }
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    private function key(string $salt, int $iterations): string
    {
        return hash_pbkdf2('sha256', $this->passphrase, $salt, $iterations, 32, true);
    }

    private function aad(string $header, int $index, bool $last): string
    {
        return $header.pack('J', $index).($last ? "\x01" : "\x00");
    }

    /**
     * @return resource
     */
    private function open(string $path, string $mode)
    {
        $handle = @fopen($path, $mode);
        if ($handle === false) {
            throw new RuntimeException("Cannot open {$path}.");
        }

        return $handle;
    }

    /**
     * @param  resource  $handle
     */
    private function read($handle, int $bytes): string
    {
        $data = '';
        while (strlen($data) < $bytes && ! feof($handle)) {
            $part = fread($handle, $bytes - strlen($data));
            if ($part === false) {
                throw new RuntimeException('The backup file could not be read.');
            }
            $data .= $part;
        }

        return $data;
    }

    /**
     * @param  resource  $handle
     */
    private function write($handle, string $data): void
    {
        if (fwrite($handle, $data) !== strlen($data)) {
            throw new RuntimeException('The backup file could not be written. Is the disk full?');
        }
    }
}
