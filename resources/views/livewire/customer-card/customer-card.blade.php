<div>
    <x-ui.card>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <x-ui.heading>{{ __('Documents') }}</x-ui.heading>
            @if ($this->allowed['view'] && $this->documents !== [])
                <x-ui.button size="sm" variant="secondary" icon="arrow-down-tray" :href="route('documents.zip', $accountId)">{{ __('Download all') }}</x-ui.button>
            @endif
        </div>

        @if ($this->documents === [])
            <x-ui.text muted class="mt-4">{{ __('No documents yet.') }}</x-ui.text>
        @else
            <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($this->documents as $document)
                    <div wire:key="documents-card-{{ $document['id'] }}" class="grid content-start gap-1 rounded-lg border border-zinc-200 p-3 text-sm dark:border-zinc-700">
                        <div class="font-medium">{{ $document['type'] }}</div>
                        <x-ui.text muted class="break-all">{{ $document['file'] }}</x-ui.text>
                        <x-ui.text muted>{{ __('Uploaded :date by :name', ['date' => $document['date'], 'name' => $document['by']]) }}</x-ui.text>
                        @if ($document['number'] !== '')
                            <x-ui.text muted>
                                <span class="font-mono">{{ $document['number'] }}</span>
                                @if ($this->allowed['view'] && $shownId !== $document['id'])
                                    <x-ui.button size="sm" variant="ghost" wire:click="showNumber({{ $document['id'] }})">{{ __('Show') }}</x-ui.button>
                                @endif
                            </x-ui.text>
                        @endif
                        @if ($this->allowed['view'] || $this->allowed['edit'])
                            <div class="mt-1 flex flex-wrap gap-1">
                                @if ($this->allowed['view'])
                                    <x-ui.button size="sm" variant="ghost" icon="eye" :href="$document['url']" target="_blank">{{ __('View') }}</x-ui.button>
                                @endif
                                @if ($this->allowed['edit'])
                                    <x-ui.button size="sm" variant="ghost" wire:click="edit({{ $document['id'] }})">{{ __('Edit') }}</x-ui.button>
                                    <x-ui.button
                                        size="sm"
                                        variant="ghost"
                                        icon="trash"
                                        class="text-red-600 dark:text-red-400"
                                        wire:click="delete({{ $document['id'] }})"
                                        wire:confirm="{{ __('Delete the :type of :customer?', ['type' => $document['type'], 'customer' => $this->customerName]) }}"
                                    >{{ __('Delete') }}</x-ui.button>
                                @endif
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

        @if ($this->allowed['upload'])
            @if ($this->uploadTypes === [])
                <x-ui.text muted class="mt-4">{{ __('No document types yet. Add them on System, then Add-ons.') }}</x-ui.text>
            @else
                <form wire:submit="saveDocument" class="mt-4 grid gap-3 rounded-lg border border-zinc-200 p-4 sm:grid-cols-2 lg:grid-cols-3 dark:border-zinc-700">
                    <x-ui.select
                        wire:model.live="uploadType"
                        :label="__('Document type')"
                        :options="$this->uploadTypes"
                        :placeholder="__('Choose a type')"
                        :description="$this->chosen['help']"
                    />
                    @if ($this->chosen['numbered'])
                        <x-ui.input wire:model="uploadNumber" :label="__('ID number')" maxlength="40" autocomplete="off" :description="$this->chosen['confirm'] === null ? null : __('Leave empty to keep the number on file.')" />
                    @endif
                    <x-ui.file-upload wire:model="uploadFile" id="documents-card-file" :label="__('Choose file')" accept="image/jpeg,image/png,application/pdf" :description="__('JPG, PNG or PDF, up to 10 MB.')" />
                    <div class="sm:col-span-2 lg:col-span-3">
                        @if ($this->chosen['confirm'] !== null)
                            <x-ui.button type="submit" size="sm" icon="arrow-up-tray" wire:confirm="{{ $this->chosen['confirm'] }}">{{ __('Upload') }}</x-ui.button>
                        @else
                            <x-ui.button type="submit" size="sm" icon="arrow-up-tray">{{ __('Upload') }}</x-ui.button>
                        @endif
                    </div>
                </form>
            @endif
        @endif
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
