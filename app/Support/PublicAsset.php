<?php

namespace App\Support;

/**
 * Addresses of files in public/ that keep their name when replaced (logos,
 * sign-in backgrounds, the favicon), with a version tag from the file's
 * modification time and size. Browsers and Hostinger keep such files for up
 * to a week; a new tag makes them fetch a replaced file at once (owner
 * report, 2026-10-02: old logo after a deploy). Built files under
 * public/build already change name when they change.
 */
final class PublicAsset
{
    /** @var array<string, string> */
    private static array $versions = [];

    /**
     * "/branding/logo.jpg" becomes "/branding/logo.jpg?v=…". Other addresses
     * (empty, external, missing files) are returned unchanged.
     */
    public static function url(?string $url): ?string
    {
        if ($url === null || $url === '' || ! str_starts_with($url, '/') || str_starts_with($url, '//') || str_contains($url, '?')) {
            return $url === '' ? null : $url;
        }

        if (! array_key_exists($url, self::$versions)) {
            $path = public_path(ltrim($url, '/'));
            $root = realpath(public_path());
            $real = realpath($path);
            self::$versions[$url] = $root !== false && $real !== false && str_starts_with($real, $root.DIRECTORY_SEPARATOR) && is_file($real)
                ? dechex((int) filemtime($real)).dechex((int) filesize($real))
                : '';
        }

        return self::$versions[$url] === '' ? $url : $url.'?v='.self::$versions[$url];
    }
}
