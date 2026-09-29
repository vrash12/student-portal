<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\TransformsRequest;

/**
 * Replaces invalid UTF-8 byte sequences in request input with "?".
 *
 * Browsers always send valid UTF-8, but a crafted form request can carry
 * invalid bytes. Laravel's string rules accept them and the utf8mb4 columns
 * then reject the insert, which surfaced as a server error instead of a
 * normal save or validation message. Cleaning input once, like TrimStrings,
 * covers every free-text field.
 */
class ReplaceInvalidUtf8 extends TransformsRequest
{
    /**
     * @param  string  $key
     * @param  mixed  $value
     */
    protected function transform($key, $value): mixed
    {
        return is_string($value) && ! mb_check_encoding($value, 'UTF-8') ? mb_scrub($value, 'UTF-8') : $value;
    }
}
