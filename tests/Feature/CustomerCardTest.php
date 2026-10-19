<?php

use App\Models\Account;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Ispgrid\Documents\Abilities;
use Ispgrid\Documents\CustomerDocuments;
use Ispgrid\Documents\Models\Document;
use Ispgrid\Documents\Models\DocumentType;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->account = Account::factory()->create(['fullname' => 'Ramesh Kumar']);
    $this->aadhaar = documentType();
    $this->document = app(CustomerDocuments::class)->upload($this->account, $this->aadhaar, pngUpload(), '1234 5678 4821', $this->admin);
});

function card(Account $account): Testable
{
    return Livewire::test('documents::customer-card', ['accountId' => $account->id]);
}

it('shows the card only to staff with View customer documents or Upload documents', function () {
    $this->actingAs(staffWith('view-customers'));
    $this->get(route('customers.show', $this->account))->assertOk()->assertDontSee('xxxx xxxx 4821');
    card($this->account)->assertForbidden();

    $this->actingAs(staffWith(Abilities::VIEW));
    $this->get(route('customers.show', $this->account))->assertOk()->assertSee('xxxx xxxx 4821')->assertDontSee('1234 5678 4821');
});

it('lets staff with Upload documents only add files, not open them', function () {
    $this->actingAs(staffWith(Abilities::UPLOAD));

    card($this->account)->assertOk()->assertDontSee(route('documents.file', $this->document))->call('showNumber', $this->document->id)->assertForbidden();
    $this->get(route('documents.file', $this->document))->assertForbidden();
    $this->get(route('documents.zip', $this->account))->assertForbidden();
});

it('opens a document decrypted and logs the view', function () {
    $this->actingAs($this->admin);

    $response = $this->get(route('documents.file', $this->document));

    $response->assertOk()->assertHeader('Content-Type', 'image/png');
    expect($response->getContent())->toBe(pngBytes())
        ->and($response->headers->get('Content-Disposition'))->toContain('Ramesh Kumar_Aadhaar card.png')
        ->and(Activity::query()->where('event', 'document-viewed')->sole()->subject_id)->toBe($this->account->id);
});

it('does not open a hidden document from the card route', function () {
    $this->actingAs($this->admin);
    app(CustomerDocuments::class)->delete($this->document, $this->admin);

    $this->get(route('documents.file', $this->document))->assertNotFound();
});

it('shows the full number once and logs it', function () {
    $this->actingAs($this->admin);

    card($this->account)->assertSee('xxxx xxxx 4821')->call('showNumber', $this->document->id)->assertSee('1234 5678 4821');

    expect(Activity::query()->where('event', 'document-number-shown')->count())->toBe(1);
});

it('uploads a document of the chosen type from the card', function () {
    $this->actingAs(staffWith(Abilities::UPLOAD));
    $photo = documentType('Customer photo');

    card($this->account)
        ->set('uploadType', (string) $photo->id)
        ->set('uploadFile', UploadedFile::fake()->createWithContent('a.png', '<html></html>'))->call('upload')
        ->assertHasErrors(['uploadFile'])
        ->set('uploadFile', pngUpload('me.png'))->call('upload')->assertHasNoErrors()
        ->assertSet('uploadType', '')->assertSet('uploadFile', null);

    expect(Document::query()->where('document_type_id', $photo->id)->sole()->original_name)->toBe('me.png');
});

it('needs the type and the file in the upload box', function () {
    $this->actingAs($this->admin);

    card($this->account)->call('upload')
        ->assertHasErrors(['uploadType' => __('Choose the document type.'), 'uploadFile' => __('Choose the file.')]);
    card($this->account)->set('uploadType', (string) documentType('Old form', ['active' => false])->id)->set('uploadFile', pngUpload())
        ->call('upload')->assertHasErrors(['uploadType']);
});

it('edits the type and number, refusing a taken type', function () {
    $this->actingAs(staffWith(Abilities::EDIT));
    $pan = documentType('PAN card');
    $agreement = documentType('Agreement');
    app(CustomerDocuments::class)->upload($this->account, $pan, pngUpload(), null, $this->admin);

    card($this->account)->call('edit', $this->document->id)
        ->set('editTypeId', (string) $pan->id)->call('saveEdit')->assertHasErrors(['type'])
        ->set('editTypeId', (string) $agreement->id)->set('editNumber', 'AB 99')->call('saveEdit')->assertHasNoErrors();

    expect($this->document->fresh()->document_type_id)->toBe($agreement->id)
        ->and($this->document->fresh()->number)->toBe('AB 99');
});

it('needs Edit and delete documents to edit or delete', function () {
    $this->actingAs(staffWith(Abilities::VIEW, Abilities::UPLOAD));

    card($this->account)->call('edit', $this->document->id)->assertForbidden();
    card($this->account)->call('delete', $this->document->id)->assertForbidden();
});

it('hides a deleted document and logs it', function () {
    $this->actingAs(staffWith(Abilities::EDIT));

    card($this->account)->call('delete', $this->document->id)->assertHasNoErrors()->assertDontSee('xxxx xxxx 4821');

    expect($this->document->fresh()->trashed())->toBeTrue()
        ->and(Activity::query()->where('event', 'document-deleted')->count())->toBe(1);
});

it('refuses a document of another customer', function () {
    $this->actingAs($this->admin);
    $other = Account::factory()->create();

    card($other)->call('showNumber', $this->document->id)->assertNotFound();
    card($other)->call('delete', $this->document->id)->assertNotFound();
});

it('still shows the documents of a deleted customer, without Upload', function () {
    $this->actingAs($this->admin);
    $photo = documentType('Customer photo');
    $this->account->delete();

    card($this->account)->assertOk()->assertSee('xxxx xxxx 4821')->assertDontSee(__('Choose file'))
        ->set('uploadType', (string) $photo->id)->set('uploadFile', pngUpload())->call('upload')->assertForbidden();
});

it('downloads all current documents as one zip and logs it', function () {
    $this->actingAs($this->admin);
    app(CustomerDocuments::class)->upload($this->account, documentType('Agreement'), UploadedFile::fake()->createWithContent('a.pdf', pdfBytes()), null, $this->admin);

    $response = $this->get(route('documents.zip', $this->account));

    $response->assertOk();
    $path = tempnam(sys_get_temp_dir(), 'zip');
    file_put_contents($path, $response->getContent());
    $zip = new ZipArchive;
    $zip->open($path);
    $names = collect(range(0, $zip->numFiles - 1))->map(fn (int $i): string => $zip->getNameIndex($i))->sort()->values()->all();

    expect($names)->toBe(['Ramesh Kumar_Aadhaar card.png', 'Ramesh Kumar_Agreement.pdf'])
        ->and($zip->getFromName('Ramesh Kumar_Aadhaar card.png'))->toBe(pngBytes())
        ->and(Activity::query()->where('event', 'documents-downloaded')->count())->toBe(1);
    $zip->close();
    unlink($path);
});

it('lets staff with Upload documents replace a file from the card', function () {
    $this->actingAs(staffWith(Abilities::UPLOAD));

    card($this->account)->assertSee(__(':type (replaces the current file)', ['type' => 'Aadhaar card']))
        ->set('uploadType', (string) $this->aadhaar->id)
        ->assertSeeHtml('wire:confirm="'.e(__('Replace the :type of :customer? The current file can be restored from Deleted documents.', ['type' => 'Aadhaar card', 'customer' => 'Ramesh Kumar'])).'"')
        ->set('uploadFile', pngUpload('sharper.png'))->call('upload')->assertHasNoErrors();

    expect($this->document->fresh()->trashed())->toBeTrue()
        ->and(Document::query()->sole()->original_name)->toBe('sharper.png')
        ->and(Document::query()->sole()->number)->toBe('1234 5678 4821');
});

it('masks the number again once View is taken away', function () {
    $staff = staffWith(Abilities::VIEW, Abilities::UPLOAD);
    $this->actingAs($staff);
    $card = card($this->account)->call('showNumber', $this->document->id)->assertSee('1234 5678 4821');

    revokeBox($staff, Abilities::VIEW);

    $card->call('$refresh')->assertDontSee('1234 5678 4821')->assertSee('xxxx xxxx 4821');
});

it('edits the number of a document on a switched-off type', function () {
    $this->actingAs($this->admin);
    $this->aadhaar->update(['active' => false]);

    card($this->account)->call('edit', $this->document->id)->set('editNumber', '9999 8888 7777')->call('saveEdit')->assertHasNoErrors();

    expect($this->document->fresh()->number)->toBe('9999 8888 7777');
});

it('shows the type help text where staff upload', function () {
    $this->actingAs($this->admin);
    documentType('Customer photo', ['help' => 'Face clearly visible']);

    $photo = DocumentType::query()->where('name', 'Customer photo')->sole();

    card($this->account)->assertDontSee('Face clearly visible')->set('uploadType', (string) $photo->id)->assertSee('Face clearly visible');
});

it('refuses a path or text where a file belongs', function () {
    $this->actingAs($this->admin);
    $photo = documentType('Customer photo');

    card($this->account)->set('uploadType', (string) $photo->id)->set('uploadFile', 'documents/1/secret')->call('upload')->assertHasErrors(['uploadFile']);
});

it('refuses Upload once the customer is deleted while the card is open', function () {
    $this->actingAs($this->admin);
    $photo = documentType('Customer photo');
    $card = card($this->account)->assertOk();

    $this->account->delete();

    $card->set('uploadType', (string) $photo->id)->set('uploadFile', pngUpload())->call('upload')->assertForbidden();
    expect(Document::query()->where('document_type_id', $photo->id)->count())->toBe(0);
});

it('refuses a zip too large for one download', function () {
    $this->actingAs($this->admin);
    Document::query()->update(['size' => 70 * 1024 * 1024]);

    $this->get(route('documents.zip', $this->account))->assertStatus(413);
});

it('stops an open card once its box is taken away', function () {
    $staff = staffWith(Abilities::VIEW);
    $this->actingAs($staff);
    $card = card($this->account)->assertOk();

    revokeBox($staff, Abilities::VIEW);

    $card->call('showNumber', $this->document->id)->assertForbidden();
});

it('offers Download all only when the customer has documents', function () {
    $this->actingAs($this->admin);
    $empty = Account::factory()->create();

    card($this->account)->assertSee(route('documents.zip', $this->account->id));
    card($empty)->assertDontSee(route('documents.zip', $empty->id))->assertSee(__('No documents yet.'));
});
