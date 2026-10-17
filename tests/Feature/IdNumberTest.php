<?php

use Ispgrid\Documents\IdNumber;
use Ispgrid\Documents\Models\NumberMode;

it('trims and collapses spaces, letters unchanged', function () {
    expect(IdNumber::normalise('  ABCDE   1234F '))->toBe('ABCDE 1234F')
        ->and(IdNumber::normalise('mÑo&1'))->toBe('mÑo&1');
});

it('takes one line of up to 40 printable characters', function (string $typed, bool $valid) {
    expect(IdNumber::isValid($typed))->toBe($valid);
})->with([
    'line break' => ["12\n34", false],
    'control character' => ["12\x0034", false],
    'too long' => [str_repeat('a', 41), false],
    'only spaces' => ['   ', false],
    'Chile RUT' => ['12.345.678-K', true],
    'Mexico RFC' => ['MÑO&1', true],
    'Hong Kong ID' => ['A123456(7)', true],
    'forty' => [str_repeat('a', 40), true],
]);

it('keeps the last 4 letters or digits', function () {
    expect(IdNumber::lastFour('RJ-13/DLC/12/123456'))->toBe('3456')
        ->and(IdNumber::lastFour('ABCDE1234F'))->toBe('234F')
        ->and(IdNumber::lastFour('1234 5678 4821'))->toBe('4821');
});

it('masks all but the last 4 letters or digits, keeping spaces and symbols', function () {
    expect(IdNumber::mask('1234 5678 4821'))->toBe('xxxx xxxx 4821')
        ->and(IdNumber::mask('A123456(7)'))->toBe('xxxx456(7)')
        ->and(IdNumber::mask('4821'))->toBe('4821');
});

it('fingerprints on letters and digits only, ignoring case', function () {
    expect(IdNumber::fingerprint('abcde1234f'))->toBe(IdNumber::fingerprint('ABCDE 1234-F'))
        ->and(IdNumber::fingerprint('ABCDE1234F'))->not->toBe(IdNumber::fingerprint('ABCDE1234G'))
        ->and(IdNumber::fingerprint('ABCDE1234F'))->not->toContain('1234');
});

it('keeps what the type allows', function () {
    expect(IdNumber::forMode(' 1234  5678 4821 ', NumberMode::Full))->toBe('1234 5678 4821')
        ->and(IdNumber::forMode('1234 5678 4821', NumberMode::LastFour))->toBe('4821')
        ->and(IdNumber::forMode('1234 5678 4821', NumberMode::None))->toBeNull()
        ->and(IdNumber::forMode('  ', NumberMode::Full))->toBeNull();
});
