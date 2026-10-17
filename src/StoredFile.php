<?php

namespace Ispgrid\Documents;

use finfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Ispgrid\Documents\Models\Document;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Document files on the add-on disk, encrypted with the app key under a random name: documents/<customer id>/<40
 * random characters>.
 */
final class StoredFile
{
    public const DISK = 'addons';

    /**
     * Encrypts and stores an upload, then deletes Livewire's readable temporary copy.
     *
     * @return array{path: string, mime: string, size: int, original_name: string}
     */
    public function put(int $accountId, UploadedFile $file): array
    {
        $bytes = (string) $file->get();
        $path = "documents/{$accountId}/".Str::random(40);
        Storage::disk(self::DISK)->put($path, Crypt::encryptString($bytes));

        if ($file instanceof TemporaryUploadedFile) {
            $file->delete();
        }

        return [
            'path' => $path,
            'mime' => (string) (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes),
            'size' => strlen($bytes),
            'original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
        ];
    }

    public function read(Document $document): string
    {
        return Crypt::decryptString((string) Storage::disk(self::DISK)->get($document->path));
    }

    public function erase(string $path): void
    {
        Storage::disk(self::DISK)->delete($path);
    }
}
