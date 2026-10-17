<?php

use App\Livewire\Concerns\AuthorizesEveryRequest;
use App\Models\Account;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Ispgrid\Documents\Abilities;
use Ispgrid\Documents\Models\Document;
use Ispgrid\Documents\Models\DocumentType;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Customers (not deleted) without a current file for at least one active required document type.
 */
new #[Title('Missing documents')] class extends Component
{
    use AuthorizesEveryRequest, WithPagination;

    #[Url]
    public string $search = '';

    public function mount(): void
    {
        $this->authorizeAccess();
    }

    protected function authorizeAccess(): void
    {
        $this->authorize(Abilities::VIEW);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /**
     * @return Collection<int, DocumentType>
     */
    #[Computed]
    public function requiredTypes(): Collection
    {
        return DocumentType::query()->where('active', true)->where('required', true)->orderBy('position')->get();
    }

    #[Computed]
    public function accounts(): ?LengthAwarePaginator
    {
        $required = $this->requiredTypes;

        if ($required->isEmpty()) {
            return null;
        }

        // The customers that have every required type; the rest are missing something.
        $complete = Document::query()
            ->whereIn('document_type_id', $required->pluck('id'))
            ->groupBy('account_id')
            ->havingRaw('COUNT(DISTINCT document_type_id) = ?', [$required->count()])
            ->pluck('account_id');
        $search = trim($this->search);

        return Account::query()
            ->whereNotIn('id', $complete)
            ->when($search !== '', fn ($query) => $query->where(fn ($accounts) => $accounts->where('username', 'like', "%{$search}%")->orWhere('fullname', 'like', "%{$search}%")))
            ->orderBy('username')
            ->paginate(25);
    }

    /**
     * @return list<array{id: int, customer: string, username: string, missing: string}>
     */
    #[Computed]
    public function rows(): array
    {
        if ($this->accounts === null) {
            return [];
        }

        $accounts = collect($this->accounts->items());
        $held = Document::query()->whereIn('account_id', $accounts->pluck('id'))->get(['account_id', 'document_type_id'])->groupBy('account_id');

        return $accounts->map(fn (Account $account): array => [
            'id' => $account->id,
            'customer' => (string) ($account->fullname ?: $account->username),
            'username' => $account->username,
            'missing' => $this->requiredTypes
                ->whereNotIn('id', ($held->get($account->id) ?? collect())->pluck('document_type_id'))
                ->pluck('name')->implode(', '),
        ])->all();
    }
};
