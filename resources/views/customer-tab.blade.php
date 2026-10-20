{{-- The Documents tab, included by core's customer form. It shows only what the tab's state() prepared. --}}
@php($tab = $addonTabs['documents'] ?? [])

@error('addonTabs.documents.uploads')
    <x-ui.alert variant="danger">{{ $message }}</x-ui.alert>
@enderror

@if (($tab['documents'] ?? []) !== [])
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($tab['documents'] as $document)
            <div wire:key="documents-current-{{ $loop->index }}" class="rounded-lg border border-zinc-200 p-3 text-sm dark:border-zinc-700">
                <div class="font-medium">{{ $document['type'] }}</div>
                <x-ui.text muted class="break-all">{{ $document['file'] }}</x-ui.text>
                <x-ui.text muted>
                    {{ __('Uploaded :date by :name', ['date' => $document['date'], 'name' => $document['by']]) }}
                    @if ($document['number'] !== '')
                        &middot; <span class="font-mono">{{ $document['number'] }}</span>
                    @endif
                </x-ui.text>
            </div>
        @endforeach
    </div>
@endif

@if (($tab['typeOptions'] ?? []) === [])
    <x-ui.text muted>{{ __('No document types yet. Add them on System, then Add-ons.') }}</x-ui.text>
@else
    @foreach ($tab['uploads'] ?? [] as $index => $row)
        @continue(! is_array($row))
        <div wire:key="documents-upload-{{ $index }}" class="grid gap-3 rounded-lg border border-zinc-200 p-4 sm:grid-cols-2 lg:grid-cols-3 dark:border-zinc-700">
            <x-ui.select
                wire:model.live="addonTabs.documents.uploads.{{ $index }}.type"
                :label="__('Document type')"
                :options="$tab['typeOptions']"
                :placeholder="__('Choose a type')"
                :description="$tab['types'][$row['type'] ?? '']['help'] ?? null"
            />
            @if ($tab['types'][$row['type'] ?? '']['numbered'] ?? false)
                <x-ui.input wire:model="addonTabs.documents.uploads.{{ $index }}.number" :label="__('ID number')" maxlength="40" autocomplete="off" />
            @endif
            <x-ui.file-upload
                wire:model="addonTabs.documents.uploads.{{ $index }}.file"
                :label="__('Choose file')"
                accept="image/jpeg,image/png,application/pdf"
                :description="__('JPG, PNG or PDF, up to 10 MB.')"
            />
            @if ($index > 0)
                <div class="sm:col-span-2 lg:col-span-3">
                    <x-ui.button size="sm" variant="ghost" wire:click="$set('addonTabs.documents.uploads.{{ $index }}', null)">{{ __('Remove') }}</x-ui.button>
                </div>
            @endif
        </div>
    @endforeach

    @if (count($tab['uploads'] ?? []) < \Ispgrid\Documents\DocumentsTab::MAX_ROWS)
        <div>
            <x-ui.button size="sm" variant="secondary" icon="plus" wire:click="$set('addonTabs.documents.uploads.{{ count($tab['uploads'] ?? []) }}', { type: '', number: '', file: null })">{{ __('Add another document') }}</x-ui.button>
        </div>
    @endif
@endif
