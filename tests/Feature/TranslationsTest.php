<?php

/*
| Every string this add-on shows has a Hindi entry, here or in core's lang/hi.json.
*/
it('ships every string it shows in Hindi', function () {
    $root = dirname(__DIR__, 2);
    $strings = [];

    foreach (['src', 'resources'] as $folder) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/{$folder}", FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            preg_match_all("/__\\('((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents($file->getPathname()), $matches);
            $strings = [...$strings, ...array_map(fn (string $s): string => stripslashes($s), $matches[1])];
        }
    }

    $known = array_merge(
        json_decode((string) file_get_contents(base_path('lang/hi.json')), true),
        json_decode((string) file_get_contents("{$root}/lang/hi.json"), true),
    );

    expect(array_values(array_diff(array_unique($strings), array_keys($known))))->toBe([]);
});
