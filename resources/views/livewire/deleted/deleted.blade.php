<section class="grid gap-6">
    <div>
        <x-ui.heading level="1" size="xl">{{ __('Deleted documents') }}</x-ui.heading>
        <x-ui.text muted class="mt-1">{{ __('Deleted documents are hidden from the customer, not removed. Restore one, or erase it for good.') }}</x-ui.text>
        <x-ui.text muted class="mt-1">{{ __('Backups taken before an erase still hold the file for up to 14 days.') }}</x-ui.text>
    </div>

    @error('document')
        <x-ui.alert variant="danger">{{ $message }}</x-ui.alert>
    @enderror

    <div class="w-full sm:w-72">
        <x-ui.input wire:model.live.debounce.400ms="search" type="search" :label="__('Customer')" :placeholder="__('Name or username')" />
    </div>

    <x-ui.table>
        <x-slot:head>
            <x-ui.th>{{ __('Customer') }}</x-ui.th>
            <x-ui.th>{{ __('Document type') }}</x-ui.th>
            <x-ui.th>{{ __('Deleted') }}</x-ui.th>
            <x-ui.th><span class="sr-only">{{ __('Actions') }}</span></x-ui.th>
        </x-slot:head>

        @forelse ($this->rows as $row)
            <tr wire:key="deleted-document-{{ $row['id'] }}">
                <x-ui.td class="font-medium">
                    <x-ui.link href="{{ route('customers.show', $row['account_id']) }}" wire:navigate>{{ $row['customer'] }}</x-ui.link>
                </x-ui.td>
                <x-ui.td>{{ $row['type'] }}</x-ui.td>
                <x-ui.td>{{ __(':date by :name', ['date' => $row['deleted'], 'name' => $row['by']]) }}</x-ui.td>
                <x-ui.td class="text-end whitespace-nowrap">
                    <x-ui.button size="sm" variant="ghost" icon="arrow-uturn-left" wire:click="restore({{ $row['id'] }})">{{ __('Restore') }}</x-ui.button>
                    @if ($this->canErase)
                        <x-ui.button
                            size="sm"
                            variant="ghost"
                            icon="trash"
                            class="text-red-600 dark:text-red-400"
                            wire:click="erase({{ $row['id'] }})"
                            wire:confirm="{{ __('Erase the :type of :customer for good? This cannot be undone.', ['type' => $row['type'], 'customer' => $row['customer']]) }}"
                        >{{ __('Erase for good') }}</x-ui.button>
                    @endif
                </x-ui.td>
            </tr>
        @empty
            <tr>
                <x-ui.td colspan="4" class="text-center text-zinc-500">{{ __('No deleted documents.') }}</x-ui.td>
            </tr>
        @endforelse
    </x-ui.table>

    {{ $this->documents->links() }}
</section>
