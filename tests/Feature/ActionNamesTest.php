<?php

/*
| Livewire's browser side keeps some $wire names for itself (`upload` is its own file upload helper), so
| wire:submit="upload" never reached the component's upload() method. The names come from the installed Livewire.
*/
it('names no button or form action after one of Livewire\'s own $wire helpers', function () {
    $livewire = (string) file_get_contents(base_path('vendor/livewire/livewire/dist/livewire.esm.js'));
    preg_match('/var aliases = \{(.*?)\};/s', $livewire, $block);
    preg_match_all('/"(\w+)":/', $block[1] ?? '', $reserved);

    $root = dirname(__DIR__, 2);
    $actions = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/resources/views", FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        preg_match_all('/wire:(?:click|submit)[\w.-]*="\s*([A-Za-z_]\w*)/', (string) file_get_contents($file->getPathname()), $matches);
        $actions = [...$actions, ...$matches[1]];
    }

    expect($reserved[1])->toContain('upload')
        ->and(array_values(array_intersect(array_unique($actions), $reserved[1])))->toBe([]);
});
