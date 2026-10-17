<?php

namespace Ispgrid\Documents\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One customer's file of one type. Delete hides it (a soft delete); only Erase removes it. The file on the disk is
 * encrypted, and so is a stored ID number.
 *
 * @property int $id
 * @property int $account_id
 * @property int $document_type_id
 * @property string $path
 * @property string $original_name
 * @property string $mime
 * @property int $size
 * @property ?string $number
 * @property ?string $number_fingerprint
 * @property ?int $uploaded_by
 * @property ?int $deleted_by
 */
class Document extends Model
{
    use SoftDeletes;

    protected $connection = 'documents';

    protected $fillable = ['account_id', 'document_type_id', 'path', 'original_name', 'mime', 'size', 'number', 'number_fingerprint', 'uploaded_by', 'deleted_by'];

    protected $hidden = ['number', 'number_fingerprint', 'path'];

    protected function casts(): array
    {
        return [
            'account_id' => 'integer',
            'document_type_id' => 'integer',
            'size' => 'integer',
            'uploaded_by' => 'integer',
            'deleted_by' => 'integer',
            'number' => 'encrypted',
        ];
    }

    /**
     * @return BelongsTo<DocumentType, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id');
    }
}
