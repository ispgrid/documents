<?php

use App\Models\Role;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Ispgrid\Documents\Models\DocumentType;
use Ispgrid\Documents\Models\NumberMode;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/*
| Run from the core checkout:
|   ISPGRID_ADDONS=on php vendor/bin/pest --test-directory=vendor/ispgrid/documents/tests vendor/ispgrid/documents/tests
| The documents database lives on its own connection, outside RefreshDatabase's transaction: it is emptied before
| each test, and the add-on disk is faked.
*/
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        DB::connection('documents')->table('documents')->delete();
        DB::connection('documents')->table('document_types')->delete();
        Storage::fake('addons');
    })
    ->in(__DIR__);

/**
 * A staff member whose role holds exactly these boxes (and the boxes they imply).
 */
function staffWith(string ...$boxes): User
{
    $role = Role::create(['name' => 'Desk '.Str::random(6), 'guard_name' => 'web']);
    $role->givePermissionTo(app(Permissions::class)->withImplied($boxes));

    return User::factory()->role($role->name)->create();
}

/**
 * Takes a box away the way an Admin does on Roles; the next request loads the staff member fresh.
 */
function revokeBox(User $staff, string $box): void
{
    $staff->roles->each(fn (Role $role) => $role->revokePermissionTo($box));
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $staff->unsetRelation('roles')->unsetRelation('permissions');
}

/**
 * The smallest valid PNG and PDF, so a file's type is detected from its bytes.
 */
function pngBytes(): string
{
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
}

function pdfBytes(): string
{
    return "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
}

function pngUpload(string $name = 'aadhaar.png'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, pngBytes());
}

/**
 * @param  array<string, mixed>  $attributes
 */
function documentType(string $name = 'Aadhaar card', array $attributes = []): DocumentType
{
    return DocumentType::query()->create([
        'name' => $name,
        'number_mode' => NumberMode::Full,
        'position' => DocumentType::query()->count() + 1,
        ...$attributes,
    ]);
}
