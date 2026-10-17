<?php

use App\Models\Account;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Ispgrid\Documents\Abilities;
use Ispgrid\Documents\CustomerDocuments;
use Ispgrid\Documents\Models\Document;
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

it('uploads a missing document from the card', function () {
    $this->actingAs(staffWith(Abilities::UPLOAD));
    $photo = documentType('Customer photo');

    card($this->account)
        ->set("uploads.{$photo->id}", UploadedFile::fake()->createWithContent('a.png', '<html></html>'))->call('upload', $photo->id)
        ->assertHasErrors(["uploads.{$photo->id}"])
        ->set("uploads.{$photo->id}", pngUpload('me.png'))->call('upload', $photo->id)->assertHasNoErrors();

    expect(Document::query()->where('document_type_id', $photo->id)->sole()->original_name)->toBe('me.png');
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

    card($this->account)->assertOk()->assertSee('xxxx xxxx 4821')->assertDontSee(__('Take photo'))
        ->set("uploads.{$photo->id}", pngUpload())->call('upload', $photo->id)->assertForbidden();
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

it('stops an open card once its box is taken away', function () {
    $staff = staffWith(Abilities::VIEW);
    $this->actingAs($staff);
    $card = card($this->account)->assertOk();

    revokeBox($staff, Abilities::VIEW);

    $card->call('showNumber', $this->document->id)->assertForbidden();
});
