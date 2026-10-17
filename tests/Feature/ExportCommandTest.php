<?php

use App\Models\Account;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Ispgrid\Documents\CustomerDocuments;
use Ispgrid\Documents\Models\NumberMode;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->folder = sys_get_temp_dir().'/documents-export-'.uniqid();
    $this->admin = User::factory()->create(['name' => 'Ravi']);
    $documents = app(CustomerDocuments::class);
    $this->ramesh = Account::factory()->create(['fullname' => 'Ramesh Kumar', 'username' => 'ramesh']);
    $sita = Account::factory()->create(['fullname' => 'Sita Devi', 'username' => 'sita']);
    $aadhaar = documentType();
    $agreement = documentType('Agreement', ['number_mode' => NumberMode::None]);
    $old = $documents->upload($this->ramesh, $aadhaar, pngUpload(), '1111 2222 3333', $this->admin);
    $documents->upload($this->ramesh, $aadhaar, pngUpload(), '1234 5678 4821', $this->admin);
    $documents->upload($this->ramesh, $agreement, UploadedFile::fake()->createWithContent('a.pdf', pdfBytes()), null, $this->admin);
    $documents->upload($sita, $aadhaar, pngUpload(), '9999 8888 7777', $this->admin);
    $this->hiddenDate = $old->fresh()->deleted_at->format('Y-m-d');
});

afterEach(fn () => File::deleteDirectory($this->folder));

it('writes readable copies of every current document and an index', function () {
    $this->artisan('documents:export', ['folder' => $this->folder])->assertSuccessful();

    expect(file_get_contents("{$this->folder}/ramesh/Aadhaar card.png"))->toBe(pngBytes())
        ->and(file_get_contents("{$this->folder}/ramesh/Agreement.pdf"))->toBe(pdfBytes())
        ->and(file_exists("{$this->folder}/sita/Aadhaar card.png"))->toBeTrue()
        ->and(File::files("{$this->folder}/ramesh"))->toHaveCount(2);

    $csv = array_map('str_getcsv', file("{$this->folder}/documents.csv", FILE_IGNORE_NEW_LINES));
    expect($csv[0])->toBe(['customer', 'username', 'type', 'id_number', 'uploaded_at', 'uploaded_by', 'deleted'])
        ->and(collect($csv)->firstWhere(2, 'Aadhaar card'))->toMatchArray([0 => 'Ramesh Kumar', 1 => 'ramesh', 3 => '1234 5678 4821', 5 => 'Ravi', 6 => 'no'])
        ->and(Activity::query()->where('event', 'documents-exported')->sole()->properties['count'])->toBe(3);
});

it('adds hidden documents only when asked', function () {
    $this->artisan('documents:export', ['folder' => $this->folder, '--include-deleted' => true])->assertSuccessful();

    expect(file_get_contents("{$this->folder}/ramesh/Aadhaar card (deleted {$this->hiddenDate}).png"))->toBe(pngBytes())
        ->and(file_get_contents("{$this->folder}/documents.csv"))->toContain('1111 2222 3333');
});

it('exports one customer', function () {
    $this->artisan('documents:export', ['folder' => $this->folder, '--customer' => 'ramesh'])->assertSuccessful();

    expect(is_dir("{$this->folder}/ramesh"))->toBeTrue()->and(is_dir("{$this->folder}/sita"))->toBeFalse();

    $this->artisan('documents:export', ['folder' => $this->folder.'-x', '--customer' => 'nobody'])->assertFailed();
});

it('refuses a folder that is not empty', function () {
    File::ensureDirectoryExists($this->folder);
    file_put_contents("{$this->folder}/keep.txt", 'mine');

    $this->artisan('documents:export', ['folder' => $this->folder])->assertFailed();

    expect(File::files($this->folder))->toHaveCount(1);
});
