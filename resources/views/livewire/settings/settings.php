<?php

use App\Livewire\Concerns\AuthorizesEveryRequest;
use App\Livewire\Concerns\ShowsToasts;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Ispgrid\Documents\Abilities;
use Ispgrid\Documents\CustomerDocuments;
use Ispgrid\Documents\IdNumber;
use Ispgrid\Documents\Models\Document;
use Ispgrid\Documents\Models\DocumentType;
use Ispgrid\Documents\Models\NumberMode;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The add-on's section on System, then Add-ons: the document types the ISP keeps, written by the ISP (nothing is
 * prefilled). A type that ever had a document is switched off, never deleted.
 */
new class extends Component
{
    use AuthorizesEveryRequest, ShowsToasts;

    public ?int $editingId = null;

    public string $name = '';

    public string $help = '';

    public bool $required = false;

    public string $numberMode = 'none';

    /**
     * Stored numbers that saving would cut to the last 4; while above 0, the confirm shows.
     */
    public int $cutCount = 0;

    public function mount(): void
    {
        $this->authorizeAccess();
    }

    protected function authorizeAccess(): void
    {
        $this->authorize(Abilities::TYPES);
    }

    /**
     * @return list<array{id: int, name: string, required: bool, mode: string, active: bool, first: bool, last: bool}>
     */
    #[Computed]
    public function rows(): array
    {
        $types = DocumentType::query()->orderBy('position')->orderBy('id')->get();

        return $types->values()->map(fn (DocumentType $type, int $i): array => [
            'id' => $type->id,
            'name' => $type->name,
            'required' => $type->required,
            'mode' => $type->number_mode->label(),
            'active' => $type->active,
            'first' => $i === 0,
            'last' => $i === $types->count() - 1,
        ])->all();
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function modes(): array
    {
        $modes = [];

        foreach (NumberMode::cases() as $mode) {
            $modes[$mode->value] = $mode->label();
        }

        return $modes;
    }

    public function edit(int $id): void
    {
        $this->authorize(Abilities::TYPES);
        $type = DocumentType::query()->findOrFail($id);
        $this->editingId = $type->id;
        $this->name = $type->name;
        $this->help = (string) $type->help;
        $this->required = $type->required;
        $this->numberMode = $type->number_mode->value;
        $this->cutCount = 0;
        $this->resetErrorBag();
    }

    public function cancel(): void
    {
        $this->reset('editingId', 'name', 'help', 'required', 'numberMode', 'cutCount');
        $this->resetErrorBag();
    }

    /**
     * Adds or updates the type. Switching a type from full numbers to the last 4 first asks, because it cuts every
     * number already stored for it.
     */
    public function save(): void
    {
        $this->authorize(Abilities::TYPES);
        $this->validate($this->rules());
        $type = $this->editingId === null ? null : DocumentType::query()->findOrFail($this->editingId);

        // Coming to last 4 from full or from none (numbers kept under none may be full ones).
        if ($type !== null && $type->number_mode !== NumberMode::LastFour && $this->numberMode === NumberMode::LastFour->value) {
            $this->cutCount = Document::withTrashed()->where('document_type_id', $type->id)->whereNotNull('number')->count();

            if ($this->cutCount > 0) {
                return;
            }
        }

        $this->store($type);
    }

    /**
     * After the confirm: cuts every stored number of the type (hidden documents too) to its last 4 and saves.
     */
    public function cutNumbers(): void
    {
        $this->authorize(Abilities::TYPES);
        $this->validate($this->rules());

        // The choice was changed back after the warning: nothing to cut.
        if ($this->numberMode !== NumberMode::LastFour->value || $this->editingId === null) {
            $this->cutCount = 0;

            return;
        }

        $type = DocumentType::query()->findOrFail($this->editingId);
        $count = 0;

        DB::connection('documents')->transaction(function () use ($type, &$count): void {
            Document::withTrashed()->where('document_type_id', $type->id)->whereNotNull('number')->lazyById()
                ->each(function (Document $document) use (&$count): void {
                    $document->forceFill(['number' => IdNumber::lastFour((string) $document->number), 'number_fingerprint' => null])->save();
                    $count++;
                });
            $this->store($type);
        });

        app(CustomerDocuments::class)->log('document-numbers-cut', null, $type->name, auth()->user(), ['count' => $count]);
    }

    public function moveUp(int $id): void
    {
        $this->move($id, -1);
    }

    public function moveDown(int $id): void
    {
        $this->move($id, 1);
    }

    public function toggleActive(int $id): void
    {
        $this->authorize(Abilities::TYPES);
        $type = DocumentType::query()->findOrFail($id);
        $type->update(['active' => ! $type->active]);
    }

    /**
     * Only a type that never had a document; one with documents (hidden ones too) is switched off instead.
     */
    public function delete(int $id): void
    {
        $this->authorize(Abilities::TYPES);
        $type = DocumentType::query()->findOrFail($id);

        if ($type->documents()->exists()) {
            throw ValidationException::withMessages(['types' => __('This type has documents. Switch it off instead.')]);
        }

        $type->delete();

        if ($this->editingId === $id) {
            $this->cancel();
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60', Rule::unique('documents.document_types', 'name')->ignore($this->editingId)],
            'help' => ['nullable', 'string', 'max:200'],
            'required' => ['boolean'],
            'numberMode' => ['required', Rule::enum(NumberMode::class)],
        ];
    }

    private function store(?DocumentType $type): void
    {
        $values = [
            'name' => trim($this->name),
            'help' => trim($this->help) === '' ? null : trim($this->help),
            'required' => $this->required,
            'number_mode' => $this->numberMode,
        ];

        if ($type === null) {
            DocumentType::query()->create([...$values, 'position' => (int) DocumentType::query()->max('position') + 1]);
        } else {
            $type->update($values);
        }

        $this->cancel();
        $this->toast(__('Document type saved.'));
    }

    private function move(int $id, int $step): void
    {
        $this->authorize(Abilities::TYPES);
        $types = DocumentType::query()->orderBy('position')->orderBy('id')->get()->values();
        $index = $types->search(fn (DocumentType $type): bool => $type->id === $id);
        $swap = $index === false ? null : $types->get($index + $step);

        if ($swap === null) {
            return;
        }

        $order = $types->pluck('id')->all();
        [$order[$index], $order[$index + $step]] = [$order[$index + $step], $order[$index]];

        foreach ($order as $position => $typeId) {
            DocumentType::query()->whereKey($typeId)->update(['position' => $position + 1]);
        }
    }
};
