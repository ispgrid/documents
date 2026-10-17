{{-- The Documents tab, included by core's customer form. It shows only what the tab's state() prepared. --}}
@if (($addonTabs['documents']['types'] ?? []) === [])
    <x-ui.text muted>{{ __('No document types yet. Add them on System, then Add-ons.') }}</x-ui.text>
@endif

@foreach ($addonTabs['documents']['types'] ?? [] as $typeId => $type)
    <div wire:key="documents-type-{{ $typeId }}" class="grid gap-3 border-b border-zinc-200 pb-5 last:border-0 dark:border-zinc-700">
        <div>
            <x-ui.heading level="3">
                {{ $type['name'] }}
                @if ($type['required'])
                    <x-ui.badge color="blue">{{ __('Required') }}</x-ui.badge>
                @endif
            </x-ui.heading>
            @if ($type['help'] !== '')
                <x-ui.text muted>{{ $type['help'] }}</x-ui.text>
            @endif
        </div>

        @if ($type['current'] !== null)
            <x-ui.text>
                {{ __(':file, uploaded :date by :name', ['file' => $type['current']['name'], 'date' => $type['current']['date'], 'name' => $type['current']['by']]) }}
                @if ($type['current']['number'] !== '')
                    &middot; {{ $type['current']['number'] }}
                @endif
            </x-ui.text>
        @endif

        @if ($type['numbered'])
            <x-ui.input
                wire:model="addonTabs.documents.numbers.{{ $typeId }}"
                :label="$type['current'] === null ? __('ID number') : __('New ID number (with the new file)')"
                maxlength="40"
                autocomplete="off"
                :description="$type['current'] === null ? null : __('Leave empty to keep the number on file.')"
            />
        @endif

        <div class="grid gap-3 sm:grid-cols-2">
            <x-ui.file-upload
                wire:model="addonTabs.documents.files.{{ $typeId }}"
                id="documents-photo-{{ $typeId }}"
                :label="$type['current'] === null ? __('Take photo') : __('Replace with a photo')"
                accept="image/*"
                capture="environment"
            />
            <x-ui.file-upload
                wire:model="addonTabs.documents.files.{{ $typeId }}"
                id="documents-file-{{ $typeId }}"
                :label="$type['current'] === null ? __('Choose file') : __('Replace with a file')"
                accept="image/jpeg,image/png,application/pdf"
                :description="__('JPG, PNG or PDF, up to 10 MB.')"
            />
        </div>
    </div>
@endforeach
