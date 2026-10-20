<?php

namespace Ispgrid\Documents;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A field of an upload row that must be filled once its partner in the same row is (a file needs its type, a type
 * its file). Runs on empty fields too.
 */
final class PairRule implements DataAwareRule, ValidationRule
{
    public bool $implicit = true;

    /**
     * @var array<string, mixed>
     */
    private array $data = [];

    /**
     * @param  string  $partner  the partner's key in the same row
     * @param  string  $message  shown when this field is empty and the partner is not
     */
    public function __construct(private string $partner, private string $message) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $partner = data_get($this->data, (string) preg_replace('/[^.]+$/', $this->partner, $attribute));

        if (blank($value) && filled($partner)) {
            $fail($this->message);
        }
    }
}
