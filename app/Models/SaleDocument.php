<?php

namespace Horsefly;

use Illuminate\Database\Eloquent\Model;

class SaleDocument extends Model
{
    protected $table = 'sale_documents';
    protected $fillable = [
        // 'id',
        'sale_id',
        'user_id',
        'document_name',
        'document_path',
        'document_size',
        'document_extension',
        'created_at',
        'updated_at'
    ];

    /**
     * Get the sale that owns the document.
     */
    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }
}
