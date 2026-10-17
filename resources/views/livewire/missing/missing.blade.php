<section class="grid gap-6">
    <div>
        <x-ui.heading level="1" size="xl">{{ __('Missing documents') }}</x-ui.heading>
        <x-ui.text muted class="mt-1">{{ __('Customers without a document their ISP requires. Deleted customers are left out.') }}</x-ui.text>
    </div>

    @if ($this->accounts === null)
        <x-ui.text muted>{{ __('No document type is required. Mark one as required on System, then Add-ons.') }}</x-ui.text>
    @else
        <div class="w-full sm:w-72">
            <x-ui.input wire:model.live.debounce.400ms="search" type="search" :label="__('Customer')" :placeholder="__('Name or username')" />
        </div>

        <x-ui.table>
            <x-slot:head>
                <x-ui.th>{{ __('Customer') }}</x-ui.th>
                <x-ui.th>{{ __('Username') }}</x-ui.th>
                <x-ui.th>{{ __('Missing') }}</x-ui.th>
            </x-slot:head>

            @forelse ($this->rows as $row)
                <tr wire:key="missing-{{ $row['id'] }}">
                    <x-ui.td class="font-medium">
                        <x-ui.link href="{{ route('customers.show', $row['id']) }}" wire:navigate>{{ $row['customer'] }}</x-ui.link>
                    </x-ui.td>
                    <x-ui.td>{{ $row['username'] }}</x-ui.td>
                    <x-ui.td>{{ $row['missing'] }}</x-ui.td>
                </tr>
            @empty
                <tr>
                    <x-ui.td colspan="3" class="text-center text-zinc-500">{{ __('Every customer has the required documents.') }}</x-ui.td>
                </tr>
            @endforelse
        </x-ui.table>

        {{ $this->accounts->links() }}
    @endif
</section>
