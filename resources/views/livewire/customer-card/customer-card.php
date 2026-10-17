<?php

use App\Livewire\Concerns\AuthorizesEveryRequest;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Account;
use App\Models\User;
use Illuminate\Validation\Rule;
use Ispgrid\Documents\Abilities;
use Ispgrid\Documents\ContentRule;
use Ispgrid\Documents\CustomerDocuments;
use Ispgrid\Documents\IdNumber;
use Ispgrid\Documents\Models\Document;
use Ispgrid\Documents\Models\DocumentType;
use Ispgrid\Documents\Models\NumberMode;
use Ispgrid\Documents\NumberRule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * The Documents card on the customer page (deleted customers too). What each staff member may do follows their
 * boxes: View customer documents opens files and numbers, Upload documents adds files, Edit and delete documents
 * changes or hides them.
 */
new class extends Component
{
    use AuthorizesEveryRequest, ShowsToasts, WithFileUploads;

    #[Locked]
    public int $accountId;

    /**
     * New files by type id, and the number typed with each.
     *
     * @var array<int|string, mixed>
     */
    public array $uploads = [];

    /**
     * @var array<int|string, mixed>
     */
    public array $numbers = [];

    /**
     * The document whose full number is shown.
     */
    #[Locked]
    public ?int $shownId = null;

    #[Locked]
    public ?int $editingId = null;

    public string $editTypeId = '';

    public string $editNumber = '';

    public bool $clearNumber = false;

    public function mount(int $accountId): void
    {
        $this->accountId = $accountId;
        $this->authorizeAccess();
    }

    protected function authorizeAccess(): void
    {
        abort_unless($this->can(Abilities::VIEW) || $this->can(Abilities::UPLOAD), 403);
    }

    #[Computed]
    public function account(): Account
    {
        return Account::withTrashed()->findOrFail($this->accountId);
    }

    /**
     * @return array{view: bool, upload: bool, edit: bool}
     */
    #[Computed]
    public function allowed(): array
    {
        return [
            'view' => $this->can(Abilities::VIEW),
            'upload' => $this->can(Abilities::UPLOAD) && ! $this->account->trashed(),
            'edit' => $this->can(Abilities::EDIT),
        ];
    }

    /**
     * One row per active type, and per switched-off type that still has a current file.
     *
     * @return list<array{type_id: int, name: string, help: string, active: bool, required: bool, numbered: bool, document: ?array{id: int, date: ?string, by: string, number: string, url: string}}>
     */
    #[Computed]
    public function rows(): array
    {
        $documents = Document::query()->where('account_id', $this->accountId)->get()->keyBy('document_type_id');
        $uploaders = User::query()->whereIn('id', $documents->pluck('uploaded_by')->filter())->pluck('name', 'id');
        $types = DocumentType::query()
            ->where(fn ($query) => $query->where('active', true)->orWhereIn('id', $documents->keys()))
            ->orderBy('position')->orderBy('id')->get();

        return $types->map(function (DocumentType $type) use ($documents, $uploaders): array {
            $document = $documents->get($type->id);

            return [
                'type_id' => $type->id,
                'name' => $type->name,
                'help' => (string) $type->help,
                'active' => $type->active,
                'required' => $type->required && $type->active,
                'numbered' => $type->number_mode !== NumberMode::None && $type->active,
                'document' => $document === null ? null : [
                    'id' => $document->id,
                    'date' => $document->created_at?->toFormattedDateString(),
                    'by' => (string) ($uploaders[$document->uploaded_by] ?? __('Unknown')),
                    'number' => match (true) {
                        $document->number === null => '',
                        $this->allowed['view'] && $this->shownId === $document->id => $document->number,
                        default => IdNumber::mask($document->number),
                    },
                    'url' => route('documents.file', $document->id),
                ],
            ];
        })->values()->all();
    }

    /**
     * The types a document can be moved to in Edit: the active ones, and the type it has now even when switched off.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function typeOptions(): array
    {
        $current = $this->editingId === null ? null : Document::query()->whereKey($this->editingId)->value('document_type_id');

        return DocumentType::query()
            ->where(fn ($query) => $query->where('active', true)->orWhere('id', $current))
            ->orderBy('position')->pluck('name', 'id')->all();
    }

    #[Computed]
    public function customerName(): string
    {
        return (string) ($this->account->fullname ?: $this->account->username);
    }

    public function showNumber(int $id): void
    {
        $this->authorize(Abilities::VIEW);
        $document = $this->document($id);
        $this->shownId = $document->id;
        app(CustomerDocuments::class)->log('document-number-shown', $this->account, $document->type->name, auth()->user());
    }

    public function upload(int $typeId): void
    {
        $this->authorize(Abilities::UPLOAD);
        abort_if($this->account->trashed(), 403);
        $type = DocumentType::query()->where('active', true)->findOrFail($typeId);
        $this->validate([
            "uploads.{$typeId}" => ['required', 'file', 'mimetypes:'.implode(',', ContentRule::TYPES), 'max:10000', new ContentRule],
            "numbers.{$typeId}" => ['nullable', 'string', new NumberRule("uploads.{$typeId}")],
        ]);
        $number = $this->numbers[$typeId] ?? null;

        app(CustomerDocuments::class)->upload($this->account, $type, $this->uploads[$typeId], is_string($number) ? $number : null, auth()->user());
        unset($this->uploads[$typeId], $this->numbers[$typeId]);
        $this->toast(__(':type saved.', ['type' => $type->name]));
    }

    public function edit(int $id): void
    {
        $this->authorize(Abilities::EDIT);
        $document = $this->document($id);
        $this->editingId = $document->id;
        $this->editTypeId = (string) $document->document_type_id;
        $this->editNumber = '';
        $this->clearNumber = false;
        $this->resetErrorBag();
        $this->dispatch('modal-open', name: 'documents-edit');
    }

    /**
     * Saves Edit: the type, and the number (empty keeps it, "Clear number" removes it).
     */
    public function saveEdit(): void
    {
        $this->authorize(Abilities::EDIT);
        $document = $this->document((int) $this->editingId);
        $this->validate([
            'editTypeId' => ['required', Rule::in(array_keys($this->typeOptions))],
            'editNumber' => ['nullable', 'string', fn (string $attribute, mixed $value, Closure $fail) => IdNumber::isValid((string) $value)
                ? null
                : $fail(__('Type the number on one line, up to :max characters.', ['max' => IdNumber::MAX]))],
        ]);
        $number = match (true) {
            $this->clearNumber => '',
            trim($this->editNumber) === '' => null,
            default => $this->editNumber,
        };

        app(CustomerDocuments::class)->edit($document, DocumentType::query()->findOrFail((int) $this->editTypeId), $number, auth()->user());
        $this->reset('editingId', 'editTypeId', 'editNumber', 'clearNumber', 'shownId');
        $this->dispatch('modal-close', name: 'documents-edit');
        $this->toast(__('Document saved.'));
    }

    public function delete(int $id): void
    {
        $this->authorize(Abilities::EDIT);
        app(CustomerDocuments::class)->delete($this->document($id), auth()->user());
        $this->toast(__('Document deleted. It can be restored from Deleted documents.'));
    }

    /**
     * A current document of this customer; any other id is not found.
     */
    private function document(int $id): Document
    {
        return Document::query()->with('type')->where('account_id', $this->accountId)->findOrFail($id);
    }

    private function can(string $box): bool
    {
        return (bool) auth()->user()?->can($box);
    }
};
