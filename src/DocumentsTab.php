<?php

namespace Ispgrid\Documents;

use App\Contracts\CustomerFormTab;
use App\Models\Account;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Ispgrid\Documents\Models\Document;
use Ispgrid\Documents\Models\DocumentType;
use Ispgrid\Documents\Models\NumberMode;

/**
 * The Documents tab in the customer form: one block per active type, with Take photo and Choose file, saved with the
 * customer. A missing required document only warns. Files are stored after the customer is saved (saved()).
 */
final class DocumentsTab implements CustomerFormTab
{
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
     * Empty file and number fields, and "types": what the tab shows (display only, it has no rule, so core drops it
     * before saving). A stored number is only ever sent masked.
     *
     * @return array<string, mixed>
     */
    public function state(?Account $account): array
    {
        $current = $account === null
            ? new Collection
            : Document::query()->where('account_id', $account->id)->get()->keyBy('document_type_id');
        $uploaders = User::query()->whereIn('id', $current->pluck('uploaded_by')->filter())->pluck('name', 'id');

        return [
            'files' => [],
            'numbers' => [],
            'types' => $this->activeTypes()->mapWithKeys(fn (DocumentType $type): array => [$type->id => [
                'name' => $type->name,
                'required' => $type->required,
                'help' => (string) $type->help,
                'numbered' => $type->number_mode !== NumberMode::None,
                'current' => ($document = $current->get($type->id)) === null ? null : [
                    'name' => $document->original_name,
                    'date' => $document->created_at?->toFormattedDateString(),
                    'by' => (string) ($uploaders[$document->uploaded_by] ?? __('Unknown')),
                    'number' => $document->number === null ? '' : IdNumber::mask($document->number),
                ],
            ]])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(?Account $account): array
    {
        $types = $this->activeTypes();
        $numbered = $types->filter(fn (DocumentType $type): bool => $type->number_mode !== NumberMode::None);

        return [
            'files' => ['array', self::keysIn($types)],
            'files.*' => ['nullable', 'file', 'mimetypes:'.implode(',', ContentRule::TYPES), 'max:10000', new ContentRule],
            'numbers' => ['array', self::keysIn($numbered)],
            'numbers.*' => ['nullable', 'string', new NumberRule],
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     * @return list<string>
     */
    public function warnings(array $state, ?Account $account): array
    {
        $files = is_array($state['files'] ?? null) ? array_filter($state['files']) : [];
        $numbers = is_array($state['numbers'] ?? null) ? $state['numbers'] : [];
        $types = $this->activeTypes();
        $stored = $account === null ? [] : Document::query()->where('account_id', $account->id)->pluck('document_type_id')->all();

        $missing = $types->filter(fn (DocumentType $type): bool => $type->required
            && ! in_array($type->id, $stored, true)
            && ! array_key_exists($type->id, $files));
        $warnings = $missing->isEmpty() ? [] : [__('Missing documents: :types.', ['types' => $missing->pluck('name')->implode(', ')])];

        foreach ($types as $type) {
            $typed = $numbers[$type->id] ?? null;

            if (! is_string($typed) || ! IdNumber::isValid($typed)) {
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

        foreach ((array) ($state['files'] ?? []) as $typeId => $file) {
            $type = $types->get((int) $typeId);

            if (! $file instanceof UploadedFile || $type === null || ! $by instanceof User) {
                continue;
            }

            $number = $state['numbers'][$typeId] ?? null;
            $this->documents->upload($account, $type, $file, is_string($number) ? $number : null, $by);
        }
    }

    /**
     * @return Collection<int, DocumentType>
     */
    private function activeTypes(): Collection
    {
        return DocumentType::query()->where('active', true)->orderBy('position')->orderBy('id')->get();
    }

    /**
     * Only these type ids may appear as keys; none at all when there are none.
     *
     * @param  Collection<int, DocumentType>  $types
     */
    private static function keysIn(Collection $types): string
    {
        return $types->isEmpty() ? 'max:0' : 'array:'.$types->pluck('id')->implode(',');
    }
}
