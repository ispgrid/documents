<?php

namespace Ispgrid\Documents;

use Closure;
use finfo;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * A JPG, PNG or PDF judged by the file's own bytes, never by its name or what the browser claims.
 */
final class ContentRule implements ValidationRule
{
    public const TYPES = ['image/jpeg', 'image/png', 'application/pdf'];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! self::allowed((string) $value->get())) {
            $fail(__('Upload a JPG, PNG or PDF.'));
        }
    }

    public static function allowed(string $bytes): bool
    {
        return in_array((new finfo(FILEINFO_MIME_TYPE))->buffer($bytes), self::TYPES, true);
    }
}
