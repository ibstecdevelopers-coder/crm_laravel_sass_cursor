<?php

namespace Horsefly;

use Illuminate\Database\Eloquent\Model;
use Horsefly\Audit;

class SaleNote extends Model
{
    protected $table = 'sale_notes';
    protected $fillable = [
        // 'id',
        'sales_notes_uid',
        'sale_id',
        'user_id',
        'sale_note',
        'status',
        'created_at',
        'updated_at'
    ];

    public function sale()
    {
        return $this->belongsTo(Sale::class, 'sale_id');
    }
}
