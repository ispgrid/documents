<div class="grid gap-4">
    <x-ui.checkbox
        wire:model.live="uploadRequired"
        :label="__('Document upload is required')"
        :description="__('A customer cannot be saved, when added or edited, until at least one document is uploaded.')"
    />

    @if ($this->canRestore)
        <div>
            <x-ui.button size="sm" variant="secondary" icon="trash" :href="route('documents.deleted')">{{ __('Deleted documents') }}</x-ui.button>
        </div>
    @endif

    <x-ui.text muted>{{ __('The documents your staff keep for each customer. A type that has documents can be switched off but not deleted.') }}</x-ui.text>

    @error('types')
        <x-ui.alert variant="danger">{{ $message }}</x-ui.alert>
    @enderror

    @if ($this->rows !== [])
        <x-ui.table>
            <x-slot:head>
                <x-ui.th>{{ __('Document type') }}</x-ui.th>
                <x-ui.th>{{ __('ID number') }}</x-ui.th>
                <x-ui.th><span class="sr-only">{{ __('Actions') }}</span></x-ui.th>
            </x-slot:head>

            @foreach ($this->rows as $row)
                <tr wire:key="document-type-{{ $row['id'] }}">
                    <x-ui.td class="font-medium">
                        {{ $row['name'] }}
                        @unless ($row['active'])
                            <x-ui.badge>{{ __('Off') }}</x-ui.badge>
                        @endunless
                    </x-ui.td>
                    <x-ui.td>{{ $row['mode'] }}</x-ui.td>
                    <x-ui.td class="text-end whitespace-nowrap">
                        <x-ui.button size="sm" variant="ghost" wire:click="moveUp({{ $row['id'] }})" :disabled="$row['first']">{{ __('Move up') }}</x-ui.button>
                        <x-ui.button size="sm" variant="ghost" wire:click="moveDown({{ $row['id'] }})" :disabled="$row['last']">{{ __('Move down') }}</x-ui.button>
                        <x-ui.button size="sm" variant="ghost" wire:click="edit({{ $row['id'] }})">{{ __('Edit') }}</x-ui.button>
                        <x-ui.button size="sm" variant="ghost" wire:click="toggleActive({{ $row['id'] }})">{{ $row['active'] ? __('Switch off') : __('Switch on') }}</x-ui.button>
                        <x-ui.button
                            size="sm"
                            variant="ghost"
                            icon="trash"
                            class="text-red-600 dark:text-red-400"
                            wire:click="delete({{ $row['id'] }})"
                            wire:confirm="{{ __('Delete :name?', ['name' => $row['name']]) }}"
                        >{{ __('Delete') }}</x-ui.button>
                    </x-ui.td>
                </tr>
            @endforeach
        </x-ui.table>
    @endif

    <form wire:submit="save" class="grid gap-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
        <x-ui.heading level="3">{{ $editingId === null ? __('Add document type') : __('Edit document type') }}</x-ui.heading>
        <div class="grid gap-3 sm:grid-cols-2">
            <x-ui.input wire:model="name" :label="__('Name')" maxlength="60" required />
            <x-ui.select wire:model="numberMode" :label="__('ID number')" :options="$this->modes" />
        </div>
        <x-ui.input wire:model="help" :label="__('Help text')" maxlength="200" :description="__('Shown to staff when they upload, e.g. Upload a masked Aadhaar (first 8 digits hidden).')" />

        @if ($cutCount > 0)
            <x-ui.alert variant="warning">
                {{ trans_choice(':count stored number will be cut to its last 4 characters. This cannot be undone.|:count stored numbers will be cut to their last 4 characters. This cannot be undone.', $cutCount) }}
                <div class="mt-3 flex gap-2">
                    <x-ui.button variant="danger" wire:click="cutNumbers">{{ __('Cut the numbers and save') }}</x-ui.button>
                    <x-ui.button variant="secondary" wire:click="$set('cutCount', 0)">{{ __('Cancel') }}</x-ui.button>
                </div>
            </x-ui.alert>
        @endif

        <div class="flex gap-2">
            <x-ui.button type="submit">{{ __('Save') }}</x-ui.button>
            @if ($editingId !== null)
                <x-ui.button variant="secondary" wire:click="cancel">{{ __('Cancel') }}</x-ui.button>
            @endif
        </div>
    </form>
</div>
