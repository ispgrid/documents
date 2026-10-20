<?php

namespace Ispgrid\Documents;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An ID number typed in the Documents tab or card: one printable line of up to 40 characters, and only together
 * with a new file for the same type (changing a stored number on its own is Edit, under its own box).
 */
final class NumberRule implements DataAwareRule, ValidationRule
{
    /**
     * @var array<string, mixed>
     */
    private array $data = [];

    /**
     * @param  string|null  $fileField  the field holding the file this number goes with; by default "file" next to
     *                                  the number in the same upload row
     */
    public function __construct(private ?string $fileField = null) {}

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
        if (! is_string($value) || ! IdNumber::isValid($value)) {
            $fail(__('Type the number on one line, up to :max characters.', ['max' => IdNumber::MAX]));

            return;
        }

        $fileField = $this->fileField ?? preg_replace('/[^.]+$/', 'file', $attribute);

        if (data_get($this->data, (string) $fileField) === null) {
            $fail(__('Add the file for this number.'));
        }
    }
}
