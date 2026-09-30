<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Support\Str;

/**
 * Normalizes text fields before validation. Only strings are changed: any
 * other value (an array such as name[]=x) is passed through unchanged, so
 * the field's `string` rule rejects it with a validation error instead of
 * the conversion failing with a server error.
 */
trait NormalizesTextInput
{
    protected function trimmedInput(string $key): mixed
    {
        $value = $this->input($key);

        return is_string($value) ? trim($value) : $value;
    }

    protected function lowercaseInput(string $key): mixed
    {
        $value = $this->trimmedInput($key);

        return is_string($value) ? Str::lower($value) : $value;
    }

    /**
     * Trimmed text, with an empty value stored as null.
     */
    protected function optionalInput(string $key, bool $lowercase = false): mixed
    {
        $value = $lowercase ? $this->lowercaseInput($key) : $this->trimmedInput($key);

        return $value === '' ? null : $value;
    }
}
