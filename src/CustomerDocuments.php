<?php

namespace Ispgrid\Documents;

use App\Models\Account;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Ispgrid\Documents\Models\Document;
use Ispgrid\Documents\Models\DocumentType;
use Ispgrid\Documents\Models\NumberMode;
use Throwable;

/**
 * Every change to a customer's documents, each with its Activity log entry on the customer. Callers check the
 * permission first. Nothing here removes a file except erase().
 */
final class CustomerDocuments
{
    public function __construct(private StoredFile $files) {}

    /**
     * Stores a file as the customer's current document of this type. A file already there is hidden, and its number
     * carries over when none is typed.
     */
    public function upload(Account $account, DocumentType $type, UploadedFile $file, ?string $typedNumber, User $by): Document
    {
        $stored = $this->files->put($account->id, $file);

        try {
            // A second save that raced past the lock hits the unique current_key; the retry hides its file.
            [$document, $replaced] = retry(2, fn (): array => DB::connection('documents')->transaction(
                fn (): array => $this->storeRow($account, $type, $stored, $typedNumber, $by),
            ), 0, fn (Throwable $e): bool => $e instanceof UniqueConstraintViolationException);
        } catch (Throwable $exception) {
            $this->files->erase($stored['path']);

            throw $exception;
        }

        $this->log($replaced ? 'document-replaced' : 'document-uploaded', $account, $type->name, $by);

        return $document;
    }

    /**
     * Moves a document to another type and/or changes its number: null keeps the number, '' clears it. The number
     * then follows the type's setting (kept, cut to the last 4, or dropped).
     */
    public function edit(Document $document, DocumentType $to, ?string $typedNumber, User $by): void
    {
        $from = $document->type;
        $number = $typedNumber === null ? $document->number : IdNumber::normalise($typedNumber);
        $number = $number === null || $number === '' ? null : IdNumber::forMode($number, $to->number_mode);

        $this->refuseTaken($document, $to->id, 'type', function () use ($document, $to, $number): void {
            $document->forceFill([
                'document_type_id' => $to->id,
                'number' => $number,
                'number_fingerprint' => self::fingerprintFor($number, $to->number_mode),
            ])->save();
        });

        $this->log('document-edited', $this->account($document), $to->name, $by, ['from' => $from->name]);
    }

    public function delete(Document $document, User $by): void
    {
        $document->forceFill(['deleted_by' => $by->id])->save();
        $document->delete();

        $this->log('document-deleted', $this->account($document), $document->type->name, $by);
    }

    public function restore(Document $document, User $by): void
    {
        $this->refuseTaken($document, $document->document_type_id, 'document', function () use ($document): void {
            $document->forceFill(['deleted_by' => null])->restore();
        });

        $this->log('document-restored', $this->account($document), $document->type->name, $by);
    }

    /**
     * Removes the file and its row for good. Backups taken before still hold it until they age out.
     */
    public function erase(Document $document, User $by): void
    {
        $account = $this->account($document);
        $typeName = $document->type->name;
        $document->forceDelete();
        $this->files->erase($document->path);

        $this->log('document-erased', $account, $typeName, $by);
    }

    /**
     * Other customers whose current document holds the same full number (compared on letters and digits, ignoring
     * case); for a customer not saved yet (no id), every customer. Types that keep only the last 4 are never
     * compared: 4 characters match far too often.
     *
     * @return Collection<int, Account>
     */
    public function duplicates(Account $account, DocumentType $type, string $typedNumber): Collection
    {
        if ($type->number_mode !== NumberMode::Full || IdNumber::normalise($typedNumber) === '') {
            return new Collection;
        }

        $ids = Document::query()
            ->where('number_fingerprint', IdNumber::fingerprint($typedNumber))
            ->when($account->id !== null, fn ($query) => $query->where('account_id', '!=', $account->id))
            ->distinct()
            ->pluck('account_id');

        return Account::withTrashed()->whereIn('id', $ids)->orderBy('username')->get();
    }

    /**
     * Active required types this customer has no current file for, in order.
     *
     * @return Collection<int, DocumentType>
     */
    public function missingTypes(Account $account): Collection
    {
        return DocumentType::query()
            ->where('active', true)
            ->where('required', true)
            ->whereNotIn('id', Document::query()->where('account_id', $account->id)->select('document_type_id'))
            ->orderBy('position')
            ->get();
    }

    /**
     * An Activity log entry on the customer: who, which type and the event, never a number or a file.
     *
     * @param  array<string, mixed>  $properties
     */
    public function log(string $event, ?Account $account, string $typeName, ?User $by, array $properties = []): void
    {
        $entry = activity()->event($event)->withProperties(['type' => $typeName, ...$properties]);

        if ($account !== null) {
            $entry->performedOn($account);
        }

        if ($by !== null) {
            $entry->causedBy($by);
        }

        $entry->log($event);
    }

    /**
     * @param  array{path: string, mime: string, size: int, original_name: string}  $stored
     * @return array{0: Document, 1: bool}
     */
    private function storeRow(Account $account, DocumentType $type, array $stored, ?string $typedNumber, User $by): array
    {
        $current = Document::query()
            ->where('account_id', $account->id)
            ->where('document_type_id', $type->id)
            ->lockForUpdate()
            ->first();

        $number = $typedNumber === null ? null : IdNumber::forMode($typedNumber, $type->number_mode);
        $fingerprint = self::fingerprintFor($number, $type->number_mode);

        if ($current !== null) {
            if ($number === null) {
                [$number, $fingerprint] = [$current->number, $current->number_fingerprint];
            }

            $current->forceFill(['deleted_by' => $by->id])->save();
            $current->delete();
        }

        $document = Document::query()->create([
            ...$stored,
            'account_id' => $account->id,
            'document_type_id' => $type->id,
            'number' => $number,
            'number_fingerprint' => $fingerprint,
            'uploaded_by' => $by->id,
        ]);

        return [$document, $current !== null];
    }

    /**
     * Runs the change unless the customer already has another current file of that type, which the unique
     * current_key also refuses when two saves race.
     */
    private function refuseTaken(Document $document, int $typeId, string $key, callable $change): void
    {
        $taken = Document::query()
            ->where('account_id', $document->account_id)
            ->where('document_type_id', $typeId)
            ->whereKeyNot($document->id)
            ->exists();

        $refusal = ValidationException::withMessages([$key => __('That type already has a file. Delete that one first.')]);

        if ($taken) {
            throw $refusal;
        }

        try {
            $change();
        } catch (UniqueConstraintViolationException) {
            throw $refusal;
        }
    }

    private static function fingerprintFor(?string $number, NumberMode $mode): ?string
    {
        return $number !== null && $mode === NumberMode::Full ? IdNumber::fingerprint($number) : null;
    }

    private function account(Document $document): ?Account
    {
        return Account::withTrashed()->find($document->account_id);
    }
}
