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

function ramesh(): Account
{
    return Account::query()->where('username', 'ramesh')->sole();
}

it('stores a photo taken while creating the customer, encrypted, and logs it', function () {
    newCustomerForm()
        ->set("addonTabs.documents.files.{$this->aadhaar->id}", pngUpload())
        ->set("addonTabs.documents.numbers.{$this->aadhaar->id}", '1234 5678 4821')
        ->call('save')->assertHasNoErrors();

    $document = Document::query()->sole();
    expect($document->account_id)->toBe(ramesh()->id)
        ->and($document->number)->toBe('1234 5678 4821')
        ->and(app(StoredFile::class)->read($document))->toBe(pngBytes())
        ->and(Activity::query()->where('event', 'document-uploaded')->sole()->subject_id)->toBe(ramesh()->id);
});

it('warns about a missing required document and still saves the customer', function () {
    $form = newCustomerForm();

    expect($form->instance()->warningTabs())->toBe(['documents']);

    $form->call('save')->assertHasNoErrors();

    expect(ramesh())->not->toBeNull()
        ->and(session('toast'))->toContain('Missing documents: Aadhaar card.');
});

it('warns about a number already on another customer', function () {
    app(CustomerDocuments::class)->upload(Account::factory()->create(['fullname' => 'Sita Devi']), $this->aadhaar, pngUpload(), '1234 5678 4821', $this->staff);

    newCustomerForm()
        ->set("addonTabs.documents.files.{$this->aadhaar->id}", pngUpload())
        ->set("addonTabs.documents.numbers.{$this->aadhaar->id}", '123456784821')
        ->call('save')->assertHasNoErrors();

    expect(session('toast'))->toContain('Aadhaar card xxxxxxxx4821 is already on Sita Devi.');
});

it('refuses a file that is not a picture or a PDF, whatever its name', function () {
    newCustomerForm()
        ->set("addonTabs.documents.files.{$this->aadhaar->id}", UploadedFile::fake()->createWithContent('aadhaar.png', '<html><script>alert(1)</script></html>'))
        ->call('save')->assertHasErrors(["addonTabs.documents.files.{$this->aadhaar->id}"]);

    expect(Account::query()->where('username', 'ramesh')->exists())->toBeFalse()
        ->and(Document::query()->count())->toBe(0);
});

it('refuses an unknown or switched-off type and a bad number', function () {
    $off = documentType('Old form', ['active' => false]);

    newCustomerForm()
        ->set('addonTabs.documents.files.999', pngUpload())
        ->call('save')->assertHasErrors(['addonTabs.documents.files']);

    newCustomerForm()
        ->set("addonTabs.documents.files.{$off->id}", pngUpload())
        ->call('save')->assertHasErrors(['addonTabs.documents.files']);

    newCustomerForm()
        ->set("addonTabs.documents.files.{$this->aadhaar->id}", pngUpload())
        ->set("addonTabs.documents.numbers.{$this->aadhaar->id}", "1234\n5678")
        ->call('save')->assertHasErrors(["addonTabs.documents.numbers.{$this->aadhaar->id}"]);
});

it('refuses a number without a file', function () {
    newCustomerForm()
        ->set("addonTabs.documents.numbers.{$this->aadhaar->id}", '1234 5678 4821')
        ->call('save')->assertHasErrors(["addonTabs.documents.numbers.{$this->aadhaar->id}"]);
});

it('shows a stored full number only masked when editing', function () {
    $account = Account::factory()->create();
    app(CustomerDocuments::class)->upload($account, $this->aadhaar, pngUpload(), '1234 5678 4821', $this->staff);

    $form = Livewire::test('pages::customers.form', ['account' => $account->id]);

    $form->assertSee('xxxx xxxx 4821')->assertDontSee('1234 5678 4821');
    expect(json_encode($form->get('addonTabs')))->not->toContain('5678');
});

it('replaces a file, hiding the old one and keeping its number', function () {
    $account = Account::factory()->create();
    $old = app(CustomerDocuments::class)->upload($account, $this->aadhaar, pngUpload(), '1234 5678 4821', $this->staff);

    Livewire::test('pages::customers.form', ['account' => $account->id])
        ->set("addonTabs.documents.files.{$this->aadhaar->id}", UploadedFile::fake()->createWithContent('scan.pdf', pdfBytes()))
        ->call('save')->assertHasNoErrors();

    $current = Document::query()->sole();
    expect($old->fresh()->trashed())->toBeTrue()
        ->and($current->mime)->toBe('application/pdf')
        ->and($current->number)->toBe('1234 5678 4821');
});

it('deletes Livewire\'s readable copy once the file is stored', function () {
    $form = newCustomerForm()->set("addonTabs.documents.files.{$this->aadhaar->id}", pngUpload());
    $temporary = $form->get("addonTabs.documents.files.{$this->aadhaar->id}");

    expect($temporary)->toBeInstanceOf(TemporaryUploadedFile::class)->and($temporary->exists())->toBeTrue();

    $form->call('save')->assertHasNoErrors();

    expect($temporary->exists())->toBeFalse()
        ->and(Storage::disk('addons')->allFiles('documents'))->toHaveCount(1);
});

it('hides the tab from staff without Upload documents and ignores what they send', function () {
    $this->actingAs(staffWith('add-customers', Abilities::VIEW));

    $form = newCustomerForm();
    expect($form->instance()->addonTabViews())->not->toHaveKey('documents');

    $form->set("addonTabs.documents.files.{$this->aadhaar->id}", pngUpload())->call('save')->assertHasNoErrors();

    expect(ramesh())->not->toBeNull()->and(Document::query()->count())->toBe(0);
});

it('keeps the number setting of the type', function () {
    $lastFour = documentType('Voter ID', ['number_mode' => NumberMode::LastFour]);

    newCustomerForm()
        ->set("addonTabs.documents.files.{$lastFour->id}", pngUpload())
        ->set("addonTabs.documents.numbers.{$lastFour->id}", 'ABC/1234567')
        ->call('save')->assertHasNoErrors();

    expect(Document::query()->sole()->number)->toBe('4567');
});
