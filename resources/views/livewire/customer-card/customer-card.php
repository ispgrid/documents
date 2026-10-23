<?php

use App\Livewire\Concerns\AuthorizesEveryRequest;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Account;
use Illuminate\Support\Collection;
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
 * The Documents card on the customer page (deleted customers too): the current documents, and one upload box (a
 * type, its ID number when the type keeps one, a file). What each staff member may do follows their boxes: View
 * customer documents opens files and numbers, Upload documents adds files, Edit and delete documents changes or
 * hides them.
 */
new class extends Component
{
    use AuthorizesEveryRequest, ShowsToasts, WithFileUploads;

    #[Locked]
    public int $accountId;

    public string $uploadType = '';

    public string $uploadNumber = '';

    public mixed $uploadFile = null;

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
     * The current documents, in the order of their types (switched-off types too).
     *
     * @return list<array{id: int, type: string, file: string, date: ?string, by: string, number: string, url: string}>
     */
    #[Computed]
    public function documents(): array
    {
        $documents = app(CustomerDocuments::class)->current($this->accountId);
        $uploaders = app(CustomerDocuments::class)->uploaders($documents);

        return $documents->map(fn (Document $document): array => [
            'id' => $document->id,
            'type' => $document->type->name,
            'file' => $document->original_name,
            'date' => $document->created_at?->toFormattedDateString(),
            'by' => (string) ($uploaders[$document->uploaded_by] ?? __('Unknown')),
            'number' => match (true) {
                $document->number === null => '',
                $this->allowed['view'] && $this->shownId === $document->id => $document->number,
                default => IdNumber::mask($document->number),
            },
            'url' => route('documents.file', $document->id),
        ])->all();
    }

    /**
     * The active types for the upload box; a type that already has a file says it is replaced.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function uploadTypes(): array
    {
        $taken = Document::query()->where('account_id', $this->accountId)->pluck('document_type_id')->all();

        return $this->activeTypes()->mapWithKeys(fn (DocumentType $type): array => [$type->id => in_array($type->id, $taken, true)
            ? __(':type (replaces the current file)', ['type' => $type->name])
            : $type->name,
        ])->all();
    }

    /**
     * The type chosen in the upload box: its help text, whether it keeps an ID number, and the confirm when it
     * replaces a file.
     *
     * @return array{help: ?string, numbered: bool, confirm: ?string}
     */
    #[Computed]
    public function chosen(): array
    {
        $type = $this->activeTypes()->firstWhere('id', (int) $this->uploadType);
        $replaces = $type !== null && Document::query()->where('account_id', $this->accountId)->where('document_type_id', $type->id)->exists();

        return [
            'help' => $type?->help,
            'numbered' => $type !== null && $type->number_mode !== NumberMode::None,
            'confirm' => $replaces ? __('Replace the :type of :customer? The current file can be restored from Deleted documents.', ['type' => $type->name, 'customer' => $this->customerName]) : null,
        ];
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

    /**
     * Stores the file as the customer's document of the chosen type, replacing (hiding) one already there.
     */
    public function saveDocument(): void
    {
        $this->authorize(Abilities::UPLOAD);
        abort_if($this->account->trashed(), 403);
        $this->validate([
            'uploadType' => ['required', Rule::in($this->activeTypes()->pluck('id')->all())],
            'uploadFile' => ['required', 'file', 'mimetypes:'.implode(',', ContentRule::TYPES), 'max:10000', new ContentRule],
            'uploadNumber' => ['nullable', 'string', new NumberRule('uploadFile')],
        ], [
            'uploadType.required' => __('Choose the document type.'),
            'uploadFile.required' => __('Choose the file.'),
        ]);
        $type = DocumentType::query()->findOrFail((int) $this->uploadType);
        $number = $type->number_mode === NumberMode::None || trim($this->uploadNumber) === '' ? null : $this->uploadNumber;

        app(CustomerDocuments::class)->upload($this->account, $type, $this->uploadFile, $number, auth()->user());
        $this->reset('uploadType', 'uploadNumber', 'uploadFile');
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

    /**
     * @return Collection<int, DocumentType>
     */
    private function activeTypes(): Collection
    {
        return DocumentType::query()->where('active', true)->orderBy('position')->orderBy('id')->get();
    }

    private function can(string $box): bool
    {
        return (bool) auth()->user()?->can($box);
    }
};
