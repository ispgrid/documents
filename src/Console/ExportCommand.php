<?php

namespace Ispgrid\Documents\Console;

use App\Models\Account;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Ispgrid\Documents\CustomerDocuments;
use Ispgrid\Documents\Models\Document;
use Ispgrid\Documents\StoredFile;

/**
 * Readable copies of the documents, for leaving ISPGrid: one folder per customer and an index, documents.csv.
 */
class ExportCommand extends Command
{
    protected $signature = 'documents:export
        {folder : An empty or new folder to write into}
        {--customer= : Only this customer (username)}
        {--include-deleted : Also the hidden (deleted) documents}';

    protected $description = 'Write readable copies of customer documents and a documents.csv index';

    public function handle(StoredFile $files, CustomerDocuments $documents): int
    {
        $folder = rtrim((string) $this->argument('folder'), '/\\');

        if (is_dir($folder) && (File::files($folder) !== [] || File::directories($folder) !== [])) {
            $this->error("{$folder} is not empty. Choose a new or empty folder.");

            return self::FAILURE;
        }

        $accounts = Account::withTrashed()
            ->when($this->option('customer'), fn ($query, string $username) => $query->where('username', $username))
            ->get()->keyBy('id');

        if ($this->option('customer') && $accounts->isEmpty()) {
            $this->error('No customer with that username.');

            return self::FAILURE;
        }

        $rows = Document::query()->with('type')
            ->when($this->option('include-deleted'), fn ($query) => $query->withTrashed())
            ->whereIn('account_id', $accounts->keys())
            ->orderBy('account_id')->orderBy('id')
            ->get();
        $staff = User::query()->whereIn('id', $rows->pluck('uploaded_by')->filter())->pluck('name', 'id');

        File::ensureDirectoryExists($folder, 0700);
        $index = fopen("{$folder}/documents.csv", 'w');
        fputcsv($index, ['customer', 'username', 'type', 'id_number', 'uploaded_at', 'uploaded_by', 'deleted'], escape: '');

        foreach ($rows as $row) {
            $account = $accounts[$row->account_id];
            $directory = "{$folder}/".$this->safe($account->username);
            File::ensureDirectoryExists($directory, 0700);
            $name = $row->trashed() ? "{$row->type->name} (deleted {$row->deleted_at->format('Y-m-d')})" : $row->type->name;
            $target = $this->freeName($directory, $this->safe($name), pathinfo(StoredFile::downloadName('x', 'x', $row->mime), PATHINFO_EXTENSION));
            file_put_contents($target, $files->read($row));

            fputcsv($index, array_map($this->cell(...), [
                (string) $account->fullname,
                $account->username,
                $row->type->name,
                (string) $row->number,
                (string) $row->created_at?->toDateTimeString(),
                (string) ($staff[$row->uploaded_by] ?? ''),
                $row->trashed() ? 'yes' : 'no',
            ]), escape: '');
        }

        fclose($index);
        $documents->log('documents-exported', null, '', null, ['count' => $rows->count()]);
        $this->info("{$rows->count()} documents written to {$folder}. They are readable: keep the folder safe and delete it when done.");

        return self::SUCCESS;
    }

    /**
     * A name that stays inside its folder: no slashes or other refused characters, and never "." or "..".
     */
    private function safe(string $name): string
    {
        $safe = trim(str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '-', $name));

        return $safe === '' || trim($safe, '.') === '' ? 'customer-'.bin2hex($safe) : $safe;
    }

    /**
     * A spreadsheet reads a cell starting with = + - @ (or a tab or return) as a formula; a leading ' keeps it text.
     */
    private function cell(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }

    /**
     * "<name>.<ext>", or "<name> (2).<ext>" and so on when that is taken.
     */
    private function freeName(string $directory, string $name, string $extension): string
    {
        $path = "{$directory}/{$name}.{$extension}";

        for ($n = 2; file_exists($path); $n++) {
            $path = "{$directory}/{$name} ({$n}).{$extension}";
        }

        return $path;
    }
}
