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
 * Opens one document, decrypted, in the browser (pictures and PDFs) for staff with View customer documents. A deleted
 * (hidden) one opens only for staff who may restore it, so they can look before they restore or erase; to everyone
 * else it does not exist. Every view goes to the Activity log, a deleted one marked as such.
 */
final class DocumentFileController
{
    public function __invoke(int $document, StoredFile $files, CustomerDocuments $documents): Response
    {
        abort_unless(auth()->user()?->can(Abilities::VIEW), 403);
        $row = Document::withTrashed()->with('type')->findOrFail($document);
        abort_if($row->trashed() && ! auth()->user()->can(Abilities::RESTORE), 404);
        $account = Account::withTrashed()->findOrFail($row->account_id);

        $documents->log('document-viewed', $account, $row->type->name, auth()->user(), $row->trashed() ? ['deleted' => true] : []);

        return PrivateFile::contentResponse(
            $files->read($row),
            StoredFile::downloadName($account->fullname ?: $account->username, $row->type->name, $row->mime),
            inline: true,
        );
    }
}
