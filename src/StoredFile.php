<?php

namespace Ispgrid\Documents;

use finfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Ispgrid\Documents\Models\Document;

/**
 * Document files on the add-on disk, encrypted with the app key under a random name: documents/<customer id>/<40
 * random characters>.
 */
final class StoredFile
{
    public const DISK = 'addons';

    /**
     * Encrypts and stores an upload. Livewire's readable temporary copy is left for the caller to delete once the
     * document's row is saved.
     *
     * @return array{path: string, mime: string, size: int, original_name: string}
     */
    public function put(int $accountId, UploadedFile $file): array
    {
        $bytes = (string) $file->get();

        if (! ContentRule::allowed($bytes)) {
            throw new InvalidArgumentException('Only JPG, PNG or PDF files are stored.');
        }

        $path = "documents/{$accountId}/".Str::random(40);
        Storage::disk(self::DISK)->put($path, Crypt::encryptString($bytes));

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

    /**
     * The name the browser offers: "<customer>_<type>.<ext>", without characters a file system refuses.
     */
    public static function downloadName(string $customer, string $typeName, string $mime): string
    {
        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'application/pdf' => 'pdf',
            default => 'bin',
        };

        return str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '-', "{$customer}_{$typeName}").'.'.$extension;
    }

    public function erase(string $path): void
    {
        Storage::disk(self::DISK)->delete($path);
    }
}
