<?php

namespace Ispgrid\Documents;

use App\Contracts\CustomerFormTab;
use App\Models\Account;
use App\Models\User;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Ispgrid\Documents\Models\Document;
use Ispgrid\Documents\Models\DocumentType;
use Ispgrid\Documents\Models\NumberMode;

/**
 * The Documents tab in the customer form: the customer's documents, and upload rows (a type, its ID number when the
 * type keeps one, and a file) saved with the customer. A type that already has a file is replaced. With "Document
 * upload is required" on, a customer without any document does not save. Files are stored after the customer is
 * saved (saved()).
 */
final class DocumentsTab implements CustomerFormTab
{
    public const MAX_ROWS = 10;

    public function __construct(private CustomerDocuments $documents) {}

    public function key(): string
    {
        return 'documents';
    }

    public function label(): string
    {
        return __('Documents');
    }

    public function view(): string
    {
        return 'documents::customer-tab';
    }

    public function visible(User $user, ?Account $account): bool
    {
        return $user->can(Abilities::UPLOAD) && ($account === null || ! $account->trashed());
    }

    /**
     * One empty upload row, and what the tab shows (display only: no rule, so core drops it before saving). A stored
     * number is only ever sent masked.
     *
     * @return array<string, mixed>
     */
    public function state(?Account $account): array
    {
        $current = $account === null ? new Collection : $this->documents->current($account->id);
        $uploaders = $this->documents->uploaders($current);
        $taken = $current->pluck('document_type_id')->all();
        $types = $this->activeTypes();

        return [
            'uploads' => [self::emptyRow()],
            'documents' => $current->map(fn (Document $document): array => [
                'type' => $document->type->name,
                'file' => $document->original_name,
                'date' => $document->created_at?->toFormattedDateString(),
                'by' => (string) ($uploaders[$document->uploaded_by] ?? __('Unknown')),
                'number' => $document->number === null ? '' : IdNumber::mask($document->number),
            ])->all(),
            'typeOptions' => $types->mapWithKeys(fn (DocumentType $type): array => [$type->id => in_array($type->id, $taken, true)
                ? __(':type (replaces the current file)', ['type' => $type->name])
                : $type->name,
            ])->all(),
            'types' => $types->mapWithKeys(fn (DocumentType $type): array => [$type->id => [
                'help' => (string) $type->help,
                'numbered' => $type->number_mode !== NumberMode::None,
            ]])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(?Account $account): array
    {
        return [
            'uploads' => ['present', 'array', 'max:'.self::MAX_ROWS, $this->uploadsRule($account)],
            'uploads.*' => ['nullable', 'array:type,number,file'],
            'uploads.*.type' => [new PairRule('file', __('Choose the document type.')), 'nullable', Rule::in($this->activeTypes()->pluck('id')->all())],
            'uploads.*.file' => [new PairRule('type', __('Choose the file.')), 'nullable', 'file', 'mimetypes:'.implode(',', ContentRule::TYPES), 'max:10000', new ContentRule],
            'uploads.*.number' => ['nullable', 'string', new NumberRule],
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     * @return list<string>
     */
    public function warnings(array $state, ?Account $account): array
    {
        $types = $this->activeTypes()->keyBy('id');
        $warnings = [];

        foreach (self::rows($state) as $row) {
            $type = $types->get((int) ($row['type'] ?? 0));
            $typed = $row['number'] ?? null;

            if ($type === null || ! is_string($typed) || ! IdNumber::isValid($typed)) {
                continue;
            }

            foreach ($this->documents->duplicates($account ?? new Account, $type, $typed) as $other) {
                $warnings[] = __(':type :number is already on :customer.', [
                    'type' => $type->name,
                    'number' => IdNumber::mask(IdNumber::normalise($typed)),
                    'customer' => $other->fullname ?: $other->username,
                ]);
            }
        }

        return $warnings;
    }

    /**
     * Nothing goes into core's database: documents live in the add-on's own, written in saved().
     *
     * @param  array<string, mixed>  $state
     */
    public function save(Account $account, array $state): void {}

    /**
     * @param  array<string, mixed>  $state
     */
    public function saved(Account $account, array $state): void
    {
        $types = $this->activeTypes()->keyBy('id');
        $by = auth()->user();

        foreach (self::rows($state) as $row) {
            $type = $types->get((int) ($row['type'] ?? 0));
            $file = $row['file'] ?? null;

            if (! $file instanceof UploadedFile || $type === null || ! $by instanceof User) {
                continue;
            }

            $number = $type->number_mode === NumberMode::None ? null : ($row['number'] ?? null);
            $this->documents->upload($account, $type, $file, is_string($number) ? $number : null, $by);
        }
    }

    /**
     * @return array{type: string, number: string, file: null}
     */
    public static function emptyRow(): array
    {
        return ['type' => '', 'number' => '', 'file' => null];
    }

    /**
     * Each type at most once in a save, and, when uploads are required, at least one document on the customer or
     * in this save.
     */
    private function uploadsRule(?Account $account): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($account): void {
            $chosen = array_filter(array_map(
                fn (array $row): ?string => filled($row['file'] ?? null) && filled($row['type'] ?? null) ? (string) $row['type'] : null,
                self::rows(['uploads' => $value]),
            ));

            if (count($chosen) !== count(array_unique($chosen))) {
                $fail(__('Choose each document type once.'));
            } elseif ($chosen === [] && UploadRequired::isOn()
                && ($account === null || ! Document::query()->where('account_id', $account->id)->exists())) {
                $fail(__('Upload at least one document.'));
            }
        };
    }

    /**
     * The upload rows of a state, skipping anything that is not a row.
     *
     * @param  array<string, mixed>  $state
     * @return list<array<string, mixed>>
     */
    private static function rows(array $state): array
    {
        return array_values(array_filter(is_array($state['uploads'] ?? null) ? $state['uploads'] : [], 'is_array'));
    }

    /**
     * @return Collection<int, DocumentType>
     */
    private function activeTypes(): Collection
    {
        return DocumentType::query()->where('active', true)->orderBy('position')->orderBy('id')->get();
    }
}
