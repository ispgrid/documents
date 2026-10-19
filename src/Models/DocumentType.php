<?php

namespace Ispgrid\Documents\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A kind of document the ISP keeps (Aadhaar card, Agreement), written by the ISP in its settings section.
 *
 * @property int $id
 * @property string $name
 * @property ?string $help
 * @property NumberMode $number_mode
 * @property int $position
 * @property bool $active
 */
class DocumentType extends Model
{
    protected $connection = 'documents';

    protected $fillable = ['name', 'help', 'number_mode', 'position', 'active'];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'position' => 'integer',
            'number_mode' => NumberMode::class,
        ];
    }

    /**
     * Every document of this type, hidden ones included.
     *
     * @return HasMany<Document, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class)->withTrashed();
    }
}
