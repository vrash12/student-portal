<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Normalizes list filters read from the query string. Unknown values are
 * ignored rather than trusted, so invalid input simply shows the unfiltered
 * list instead of an error.
 */
final class QueryFilters
{
    private const MAX_SEARCH_LENGTH = 100;

    public static function search(Request $request, string $key = 'search'): string
    {
        return Str::limit(trim((string) $request->query($key, '')), self::MAX_SEARCH_LENGTH, '');
    }

    /**
     * @param  list<string>  $allowed
     */
    public static function oneOf(Request $request, string $key, array $allowed): string
    {
        $value = (string) $request->query($key, '');

        return in_array($value, $allowed, true) ? $value : '';
    }

    /**
     * A positive integer id from the query string, or an empty string.
     */
    public static function id(Request $request, string $key): string
    {
        $value = (string) $request->query($key, '');

        return ctype_digit($value) && (int) $value > 0 ? $value : '';
    }

    /**
     * A LIKE pattern matching the term anywhere, with wildcards escaped so
     * user input is always treated literally.
     */
    public static function likeTerm(string $term): string
    {
        return '%'.addcslashes($term, '%_\\').'%';
    }
}
