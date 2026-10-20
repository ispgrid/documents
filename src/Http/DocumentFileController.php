<?php

namespace Ispgrid\Documents\Http;

use App\Models\Account;
use App\Support\Addons\PrivateFile;
use Ispgrid\Documents\Abilities;
use Ispgrid\Documents\CustomerDocuments;
use Ispgrid\Documents\Models\Document;
use Ispgrid\Documents\StoredFile;
use Symfony\Component\HttpFoundation\Response;

/**
 * Opens one current document, decrypted, in the browser (pictures and PDFs) for staff with View customer documents.
 * Every view goes to the Activity log.
 */
final class DocumentFileController
{
    public function __invoke(int $document, StoredFile $files, CustomerDocuments $documents): Response
    {
        abort_unless(auth()->user()?->can(Abilities::VIEW), 403);
        $row = Document::query()->with('type')->findOrFail($document);
        $account = Account::withTrashed()->findOrFail($row->account_id);

        $documents->log('document-viewed', $account, $row->type->name, auth()->user());

        return PrivateFile::contentResponse(
            $files->read($row),
            StoredFile::downloadName($account->fullname ?: $account->username, $row->type->name, $row->mime),
            inline: true,
        );
    }
}
