<?php

use App\Models\Role;
use App\Support\Permissions;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ispgrid\Documents\Abilities;

function documentsMigration(): object
{
    return require __DIR__.'/../../database/migrations/2026_10_17_000000_create_documents_tables.php';
}

/**
 * @return array<string, mixed>
 */
function documentRow(int $accountId, int $typeId): array
{
    return ['account_id' => $accountId, 'document_type_id' => $typeId, 'path' => 'documents/x', 'original_name' => 'a.png', 'mime' => 'image/png', 'size' => 1];
}

it('creates its own database and tables', function () {
    expect(DB::connection('documents')->getDatabaseName())->toBe('ispgrid_documents_test')
        ->and(Schema::connection('documents')->hasTable('document_types'))->toBeTrue()
        ->and(Schema::connection('documents')->hasTable('documents'))->toBeTrue();
});

it('adds the six permission boxes and gives them to no role', function () {
    foreach (Abilities::all() as $name) {
        expect(DB::table('permissions')->where('name', $name)->where('guard_name', 'web')->exists())->toBeTrue()
            ->and(app(Permissions::class)->has($name))->toBeTrue()
            ->and(Role::findByName(Role::TECHNICIAN)->hasPermissionTo($name))->toBeFalse();
    }
});

it('runs again without harm', function () {
    documentsMigration()->up();
    documentsMigration()->up();

    expect(DB::table('permissions')->whereIn('name', Abilities::all())->count())->toBe(6);
});

it('allows one current file per customer and type', function () {
    $type = DB::connection('documents')->table('document_types')->insertGetId(['name' => 'Aadhaar card']);
    DB::connection('documents')->table('documents')->insert(documentRow(5, $type));

    expect(fn () => DB::connection('documents')->table('documents')->insert(documentRow(5, $type)))
        ->toThrow(UniqueConstraintViolationException::class);

    DB::connection('documents')->table('documents')->update(['deleted_at' => now()]);
    DB::connection('documents')->table('documents')->insert(documentRow(5, $type));

    expect(DB::connection('documents')->table('documents')->count())->toBe(2);
});
