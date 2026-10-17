<div>
    <x-ui.card>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <x-ui.heading>{{ __('Documents') }}</x-ui.heading>
            @if ($this->allowed['view'])
                <x-ui.button size="sm" variant="secondary" icon="arrow-down-tray" :href="route('documents.zip', $accountId)">{{ __('Download all') }}</x-ui.button>
            @endif
        </div>

        @if ($this->rows === [])
            <x-ui.text muted class="mt-4">{{ __('No document types yet. Add them on System, then Add-ons.') }}</x-ui.text>
        @endif

        <div class="mt-4 grid gap-4">
            @foreach ($this->rows as $row)
                <div wire:key="documents-row-{{ $row['type_id'] }}" class="grid gap-2 border-b border-zinc-200 pb-4 text-sm last:border-0 dark:border-zinc-700">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="font-medium">
                            {{ $row['name'] }}
                            @if ($row['required'] && $row['document'] === null)
                                <x-ui.badge color="amber">{{ __('Missing') }}</x-ui.badge>
                            @endif
                        </div>

                        @if ($row['document'] !== null)
                            <div class="flex flex-wrap gap-1">
                                @if ($this->allowed['view'])
                                    <x-ui.button size="sm" variant="ghost" icon="eye" :href="$row['document']['url']" target="_blank">{{ __('View') }}</x-ui.button>
                                @endif
                                @if ($this->allowed['edit'])
                                    <x-ui.button size="sm" variant="ghost" wire:click="edit({{ $row['document']['id'] }})">{{ __('Edit') }}</x-ui.button>
                                    <x-ui.button
                                        size="sm"
                                        variant="ghost"
                                        icon="trash"
                                        class="text-red-600 dark:text-red-400"
                                        wire:click="delete({{ $row['document']['id'] }})"
                                        wire:confirm="{{ __('Delete the :type of :customer?', ['type' => $row['name'], 'customer' => $this->account->fullname ?: $this->account->username]) }}"
                                    >{{ __('Delete') }}</x-ui.button>
                                @endif
                            </div>
                        @endif
                    </div>

                    @if ($row['document'] !== null)
                        <x-ui.text muted>
                            {{ __('Uploaded :date by :name', ['date' => $row['document']['date'], 'name' => $row['document']['by']]) }}
                            @if ($row['document']['number'] !== '')
                                &middot; <span class="font-mono">{{ $row['document']['number'] }}</span>
                                @if ($this->allowed['view'] && $shownId !== $row['document']['id'])
                                    <x-ui.button size="sm" variant="ghost" wire:click="showNumber({{ $row['document']['id'] }})">{{ __('Show') }}</x-ui.button>
                                @endif
                            @endif
                        </x-ui.text>
                    @elseif ($this->allowed['upload'])
                        @if ($row['numbered'])
                            <x-ui.input wire:model="numbers.{{ $row['type_id'] }}" :label="__('ID number')" maxlength="40" autocomplete="off" />
                        @endif
                        <div class="grid gap-3 sm:grid-cols-2">
                            <x-ui.file-upload wire:model="uploads.{{ $row['type_id'] }}" id="documents-card-photo-{{ $row['type_id'] }}" :label="__('Take photo')" accept="image/*" capture="environment" />
                            <x-ui.file-upload wire:model="uploads.{{ $row['type_id'] }}" id="documents-card-file-{{ $row['type_id'] }}" :label="__('Choose file')" accept="image/jpeg,image/png,application/pdf" :description="__('JPG, PNG or PDF, up to 10 MB.')" />
                        </div>
                        <div>
                            <x-ui.button size="sm" wire:click="upload({{ $row['type_id'] }})">{{ __('Upload') }}</x-ui.button>
                        </div>
                    @else
                        <x-ui.text muted>{{ __('Not uploaded') }}</x-ui.text>
                    @endif
                </div>
            @endforeach
        </div>
    </x-ui.card>

    @if ($this->allowed['edit'])
        <x-ui.modal name="documents-edit" width="sm">
            <form wire:submit="saveEdit" class="grid gap-4">
                <x-ui.heading>{{ __('Edit document') }}</x-ui.heading>
                @error('type')
                    <x-ui.alert variant="danger">{{ $message }}</x-ui.alert>
                @enderror
                <x-ui.select wire:model="editTypeId" :label="__('Document type')" :options="$this->typeOptions" />
                <x-ui.input wire:model="editNumber" :label="__('ID number')" maxlength="40" autocomplete="off" :description="__('Leave empty to keep the number on file.')" />
                <x-ui.checkbox wire:model="clearNumber" :label="__('Clear the number')" />
                <div class="flex gap-2">
                    <x-ui.button type="submit">{{ __('Save') }}</x-ui.button>
                    <x-ui.button variant="secondary" x-on:click="$dispatch('modal-close', { name: 'documents-edit' })">{{ __('Cancel') }}</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif
</div>
