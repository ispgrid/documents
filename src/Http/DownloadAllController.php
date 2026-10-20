<?php

namespace Ispgrid\Documents\Http;

use App\Models\Account;
use App\Support\Addons\PrivateFile;
use Ispgrid\Documents\Abilities;
use Ispgrid\Documents\CustomerDocuments;
use Ispgrid\Documents\Models\Document;
use Ispgrid\Documents\StoredFile;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use ZipArchive;

/**
 * One zip of a customer's current documents, decrypted, for an audit hand-over. Logged.
 */
final class DownloadAllController
{
    public const MAX_BYTES = 60 * 1024 * 1024;

    public function __invoke(int $account, StoredFile $files, CustomerDocuments $documents): Response
    {
        abort_unless(auth()->user()?->can(Abilities::VIEW), 403);
        $customer = Account::withTrashed()->findOrFail($account);
        $name = $customer->fullname ?: $customer->username;
        $rows = Document::query()->with('type')->where('account_id', $customer->id)->get();
        abort_if($rows->isEmpty(), 404);
        // The files are decrypted in memory; past this, documents:export on the server is the way.
        abort_if($rows->sum('size') > self::MAX_BYTES, 413, __('These documents are too large for one download. Use documents:export on the server.'));

        $path = (string) tempnam(sys_get_temp_dir(), 'documents');

        try {
            $zip = new ZipArchive;

            if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('The zip could not be created.');
            }

            foreach ($rows as $row) {
                $zip->addFromString(StoredFile::downloadName($name, $row->type->name, $row->mime), $files->read($row));
            }

            $zip->close();
            $contents = (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }

        $documents->log('documents-downloaded', $customer, $rows->pluck('type.name')->implode(', '), auth()->user(), ['count' => $rows->count()]);

        return PrivateFile::contentResponse($contents, str_replace(['/', '\\'], '-', $name).'_documents.zip');
    }
}
