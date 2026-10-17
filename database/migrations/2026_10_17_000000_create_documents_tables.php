<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ispgrid\Documents\Abilities;
use Spatie\Permission\PermissionRegistrar;

/**
 * The documents database and its tables, created only when missing: ISPs must keep these records, so nothing here
 * ever drops them (down() does nothing). Also the add-on's permission rows, given to no role.
 */
return new class extends Migration
{
    public function up(): void
    {
        $database = config('database.connections.documents.database');
        DB::statement("CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        DB::purge('documents');
        $schema = Schema::connection('documents');

        if (! $schema->hasTable('document_types')) {
            $schema->create('document_types', function (Blueprint $table) {
                $table->id();
                $table->string('name', 60)->unique();
                $table->string('help', 200)->nullable();
                $table->boolean('required')->default(false);
                $table->string('number_mode', 5)->default('none');
                $table->unsignedInteger('position')->default(0);
                $table->boolean('active')->default(true);
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('documents')) {
            $schema->create('documents', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('account_id')->index();
                $table->foreignId('document_type_id')->constrained('document_types');
                $table->string('path', 100);
                $table->string('original_name', 255);
                $table->string('mime', 50);
                $table->unsignedInteger('size');
                $table->text('number')->nullable();
                $table->char('number_fingerprint', 64)->nullable()->index();
                $table->unsignedBigInteger('uploaded_by')->nullable();
                $table->unsignedBigInteger('deleted_by')->nullable();
                $table->softDeletes();
                $table->timestamps();
                // One current file per customer and type, even when two saves race: hidden rows have no key.
                $table->string('current_key', 41)
                    ->storedAs("IF(deleted_at IS NULL, CONCAT(account_id, '-', document_type_id), NULL)")
                    ->unique();
            });
        }

        $now = now();
        DB::table('permissions')->insertOrIgnore(array_map(
            fn (string $name): array => ['name' => $name, 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now],
            Abilities::all(),
        ));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Never: ISPs must keep these records.
    }
};
