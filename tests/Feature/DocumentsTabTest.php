<?php

use App\Models\Account;
use App\Models\AppSetting;
use App\Models\User;
use Database\Seeders\RadiusAttributeSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Ispgrid\Documents\Abilities;
use Ispgrid\Documents\CustomerDocuments;
use Ispgrid\Documents\Models\Document;
use Ispgrid\Documents\Models\NumberMode;
use Ispgrid\Documents\StoredFile;
use Ispgrid\Documents\UploadRequired;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RadiusAttributeSeeder::class);
    AppSetting::put('phone_digits', '10');
    $this->staff = User::factory()->create();
    $this->actingAs($this->staff);
    $this->aadhaar = documentType();
});

function newCustomerForm(): Testable
{
    return Livewire::test('pages::customers.form')
        ->set('fullname', 'Ramesh Kumar')
        ->set('service_type', 'PPP')
        ->set('username', 'ramesh')
        ->set('password', 'secret12');
}

/**
 * Fills upload row $index of the Documents tab.
 */
function uploadRow(Testable $form, int $index, int|string $typeId, mixed $file, string $number = ''): Testable
{
    return $form
        ->set("addonTabs.documents.uploads.{$index}", ['type' => (string) $typeId, 'number' => $number, 'file' => null])
        ->set("addonTabs.documents.uploads.{$index}.file", $file);
}

function ramesh(): Account
{
    return Account::query()->where('username', 'ramesh')->sole();
}

it('stores a document chosen while creating the customer, encrypted, and logs it', function () {
    uploadRow(newCustomerForm(), 0, $this->aadhaar->id, pngUpload(), '1234 5678 4821')
        ->call('save')->assertHasNoErrors();

    $document = Document::query()->sole();
    expect($document->account_id)->toBe(ramesh()->id)
        ->and($document->number)->toBe('1234 5678 4821')
        ->and(app(StoredFile::class)->read($document))->toBe(pngBytes())
        ->and(Activity::query()->where('event', 'document-uploaded')->sole()->subject_id)->toBe(ramesh()->id);
});

it('stores several documents from one save', function () {
    $pan = documentType('PAN card');

    $form = uploadRow(newCustomerForm(), 0, $this->aadhaar->id, pngUpload());
    uploadRow($form, 1, $pan->id, UploadedFile::fake()->createWithContent('pan.pdf', pdfBytes()), 'ABCDE1234F')
        ->call('save')->assertHasNoErrors();

    expect(Document::query()->orderBy('document_type_id')->pluck('mime')->all())->toBe(['image/png', 'application/pdf'])
        ->and(Document::query()->where('document_type_id', $pan->id)->sole()->number)->toBe('ABCDE1234F');
});

it('saves a customer without documents while upload is not required', function () {
    newCustomerForm()->call('save')->assertHasNoErrors();

    expect(ramesh())->not->toBeNull();
});

it('refuses a customer without any document once upload is required, when adding and when editing', function () {
    UploadRequired::put(true);

    newCustomerForm()->call('save')->assertHasErrors(['addonTabs.documents.uploads']);
    expect(Account::query()->where('username', 'ramesh')->exists())->toBeFalse();

    $old = Account::factory()->create();
    Livewire::test('pages::customers.form', ['account' => $old->id])->set('fullname', 'New name')
        ->call('save')->assertHasErrors(['addonTabs.documents.uploads']);
    expect($old->fresh()->fullname)->not->toBe('New name');

    uploadRow(newCustomerForm(), 0, $this->aadhaar->id, pngUpload())->call('save')->assertHasNoErrors();
});

it('lets a customer who has a document save without a new one when upload is required', function () {
    UploadRequired::put(true);
    $account = Account::factory()->create();
    app(CustomerDocuments::class)->upload($account, $this->aadhaar, pngUpload(), null, $this->staff);

    Livewire::test('pages::customers.form', ['account' => $account->id])->set('fullname', 'New name')
        ->call('save')->assertHasNoErrors();

    expect($account->fresh()->fullname)->toBe('New name');
});

it('needs the type and the file of a row together', function () {
    newCustomerForm()->set('addonTabs.documents.uploads.0.file', pngUpload())
        ->call('save')->assertHasErrors(['addonTabs.documents.uploads.0.type' => __('Choose the document type.')]);

    newCustomerForm()->set('addonTabs.documents.uploads.0.type', (string) $this->aadhaar->id)
        ->call('save')->assertHasErrors(['addonTabs.documents.uploads.0.file' => __('Choose the file.')]);
});

it('refuses the same type twice in one save', function () {
    $form = uploadRow(newCustomerForm(), 0, $this->aadhaar->id, pngUpload());
    uploadRow($form, 1, $this->aadhaar->id, pngUpload())
        ->call('save')->assertHasErrors(['addonTabs.documents.uploads' => __('Choose each document type once.')]);

    expect(Document::query()->count())->toBe(0);
});

it('warns about a number already on another customer', function () {
    app(CustomerDocuments::class)->upload(Account::factory()->create(['fullname' => 'Sita Devi']), $this->aadhaar, pngUpload(), '1234 5678 4821', $this->staff);

    uploadRow(newCustomerForm(), 0, $this->aadhaar->id, pngUpload(), '123456784821')
        ->call('save')->assertHasNoErrors();

    expect(session('toast'))->toContain('Aadhaar card xxxxxxxx4821 is already on Sita Devi.');
});

it('refuses a file that is not a picture or a PDF, whatever its name', function () {
    uploadRow(newCustomerForm(), 0, $this->aadhaar->id, UploadedFile::fake()->createWithContent('aadhaar.png', '<html><script>alert(1)</script></html>'))
        ->call('save')->assertHasErrors(['addonTabs.documents.uploads.0.file']);

    expect(Account::query()->where('username', 'ramesh')->exists())->toBeFalse()
        ->and(Document::query()->count())->toBe(0);
});

it('refuses an unknown or switched-off type, a bad number and a tampered row', function () {
    $off = documentType('Old form', ['active' => false]);

    uploadRow(newCustomerForm(), 0, 999, pngUpload())->call('save')->assertHasErrors(['addonTabs.documents.uploads.0.type']);
    uploadRow(newCustomerForm(), 0, $off->id, pngUpload())->call('save')->assertHasErrors(['addonTabs.documents.uploads.0.type']);
    uploadRow(newCustomerForm(), 0, $this->aadhaar->id, pngUpload(), "1234\n5678")
        ->call('save')->assertHasErrors(['addonTabs.documents.uploads.0.number']);
    newCustomerForm()->set('addonTabs.documents.uploads.0.path', 'documents/1/secret')
        ->call('save')->assertHasErrors(['addonTabs.documents.uploads.0']);
    newCustomerForm()->set('addonTabs.documents.uploads', array_fill(0, 11, ['type' => '', 'number' => '', 'file' => null]))
        ->call('save')->assertHasErrors(['addonTabs.documents.uploads']);
});

it('refuses a number without a file', function () {
    newCustomerForm()->set('addonTabs.documents.uploads.0.number', '1234 5678 4821')
        ->call('save')->assertHasErrors(['addonTabs.documents.uploads.0.number']);
});

it('ignores a removed row', function () {
    $form = uploadRow(newCustomerForm(), 0, $this->aadhaar->id, pngUpload());
    uploadRow($form, 1, documentType('PAN card')->id, pngUpload())
        ->set('addonTabs.documents.uploads.1', null)
        ->call('save')->assertHasNoErrors();

    expect(Document::query()->sole()->document_type_id)->toBe($this->aadhaar->id);
});

it('lists the stored documents with the full number only masked when editing', function () {
    $account = Account::factory()->create();
    app(CustomerDocuments::class)->upload($account, $this->aadhaar, pngUpload('front.png'), '1234 5678 4821', $this->staff);

    $form = Livewire::test('pages::customers.form', ['account' => $account->id]);

    $form->assertSee('front.png')->assertSee('xxxx xxxx 4821')->assertDontSee('1234 5678 4821')
        ->assertSee(__(':type (replaces the current file)', ['type' => 'Aadhaar card']));
    expect(json_encode($form->get('addonTabs')))->not->toContain('5678');
});

it('replaces a file, hiding the old one and keeping its number', function () {
    $account = Account::factory()->create();
    $old = app(CustomerDocuments::class)->upload($account, $this->aadhaar, pngUpload(), '1234 5678 4821', $this->staff);

    uploadRow(Livewire::test('pages::customers.form', ['account' => $account->id]), 0, $this->aadhaar->id, UploadedFile::fake()->createWithContent('scan.pdf', pdfBytes()))
        ->call('save')->assertHasNoErrors();

    $current = Document::query()->sole();
    expect($old->fresh()->trashed())->toBeTrue()
        ->and($current->mime)->toBe('application/pdf')
        ->and($current->number)->toBe('1234 5678 4821');
});

it('deletes Livewire\'s readable copy once the file is stored', function () {
    $form = uploadRow(newCustomerForm(), 0, $this->aadhaar->id, pngUpload());
    $temporary = $form->get('addonTabs.documents.uploads.0.file');

    expect($temporary)->toBeInstanceOf(TemporaryUploadedFile::class)->and($temporary->exists())->toBeTrue();

    $form->call('save')->assertHasNoErrors();

    expect($temporary->exists())->toBeFalse()
        ->and(Storage::disk('addons')->allFiles('documents'))->toHaveCount(1);
});

it('hides the tab from staff without Upload documents and ignores what they send, even when upload is required', function () {
    UploadRequired::put(true);
    $this->actingAs(staffWith('add-customers', Abilities::VIEW));

    $form = newCustomerForm();
    expect($form->instance()->addonTabViews())->not->toHaveKey('documents');

    uploadRow($form, 0, $this->aadhaar->id, pngUpload())->call('save')->assertHasNoErrors();

    expect(ramesh())->not->toBeNull()->and(Document::query()->count())->toBe(0);
});

it('refuses a path where a file belongs', function () {
    uploadRow(newCustomerForm(), 0, $this->aadhaar->id, 'documents/1/secret')
        ->call('save')->assertHasErrors(['addonTabs.documents.uploads.0.file']);
});

it('keeps the temporary copy when storing the document fails, so staff can save again', function () {
    Document::creating(fn () => throw new RuntimeException('database down'));
    $form = uploadRow(newCustomerForm(), 0, $this->aadhaar->id, pngUpload());
    $temporary = $form->get('addonTabs.documents.uploads.0.file');

    $form->call('save')->assertHasNoErrors();

    expect($temporary->exists())->toBeTrue()
        ->and(Storage::disk('addons')->allFiles())->toBe([]);
});

it('keeps the number setting of the type, and no number for a type without one', function () {
    $lastFour = documentType('Voter ID', ['number_mode' => NumberMode::LastFour]);
    $plain = documentType('Agreement', ['number_mode' => NumberMode::None]);

    $form = uploadRow(newCustomerForm(), 0, $lastFour->id, pngUpload(), 'ABC/1234567');
    uploadRow($form, 1, $plain->id, pngUpload(), 'XYZ 99')->call('save')->assertHasNoErrors();

    expect(Document::query()->where('document_type_id', $lastFour->id)->sole()->number)->toBe('4567')
        ->and(Document::query()->where('document_type_id', $plain->id)->sole()->number)->toBeNull();
});
