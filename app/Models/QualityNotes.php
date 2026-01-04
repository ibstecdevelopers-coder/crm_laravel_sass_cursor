<?php

namespace Horsefly;

use Illuminate\Database\Eloquent\Model;

class QualityNotes extends Model
{
    protected $table = 'quality_notes';
    protected $fillable = [
        // 'id',
        'quality_notes_uid',
        'user_id',
        'applicant_id',
        'sale_id',
        'details',
        'moved_tab_to',
        'status',
        'created_at',
        'updated_at'
    ];

    public function applicant()
    {
        return $this->belongsTo(Applicant::class, 'applicant_id');
    }
}
