<?php

use App\Livewire\Concerns\AuthorizesEveryRequest;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Account;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Ispgrid\Documents\Abilities;
use Ispgrid\Documents\CustomerDocuments;
use Ispgrid\Documents\Models\Document;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Hidden (deleted) documents, newest first: Restore puts one back on the customer's card, Erase for good removes
 * the file and its row.
 */
new #[Title('Deleted documents')] class extends Component
{
    use AuthorizesEveryRequest, ShowsToasts, WithPagination;

    #[Url]
    public string $search = '';

    public function mount(): void
    {
        $this->authorizeAccess();
    }

    protected function authorizeAccess(): void
    {
        $this->authorize(Abilities::RESTORE);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function canErase(): bool
    {
        return (bool) auth()->user()?->can(Abilities::ERASE);
    }

    #[Computed]
    public function documents(): LengthAwarePaginator
    {
        $search = trim($this->search);

        return Document::onlyTrashed()->with('type')
            ->when($search !== '', fn ($query) => $query->whereIn('account_id', Account::withTrashed()
                ->where(fn ($accounts) => $accounts->where('username', 'like', "%{$search}%")->orWhere('fullname', 'like', "%{$search}%"))
                ->pluck('id')))
            ->orderByDesc('deleted_at')->orderByDesc('id')
            ->paginate(25);
    }

    /**
     * The page's rows, ready to show.
     *
     * @return list<array{id: int, customer: string, account_id: int, type: string, deleted: string, by: string}>
     */
    #[Computed]
    public function rows(): array
    {
        $documents = collect($this->documents->items());
        $accounts = Account::withTrashed()->whereIn('id', $documents->pluck('account_id'))->get()->keyBy('id');
        $staff = User::query()->whereIn('id', $documents->pluck('deleted_by')->filter())->pluck('name', 'id');

        return $documents->map(fn (Document $document): array => [
            'id' => $document->id,
            'customer' => ($account = $accounts->get($document->account_id)) === null ? '#'.$document->account_id : ($account->fullname ?: $account->username),
            'account_id' => $document->account_id,
            'type' => $document->type->name,
            'deleted' => (string) $document->deleted_at?->toDayDateTimeString(),
            'by' => (string) ($staff[$document->deleted_by] ?? __('Unknown')),
        ])->all();
    }

    public function restore(int $id): void
    {
        $this->authorize(Abilities::RESTORE);
        app(CustomerDocuments::class)->restore($this->hidden($id), auth()->user());
        $this->toast(__('Document restored.'));
    }

    public function erase(int $id): void
    {
        $this->authorize(Abilities::ERASE);
        app(CustomerDocuments::class)->erase($this->hidden($id), auth()->user());
        $this->toast(__('Document erased.'));
    }

    private function hidden(int $id): Document
    {
        return Document::onlyTrashed()->with('type')->findOrFail($id);
    }
};
