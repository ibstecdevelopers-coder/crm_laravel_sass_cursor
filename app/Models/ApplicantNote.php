<?php

namespace Horsefly;

use Illuminate\Database\Eloquent\Model;

class ApplicantNote extends Model
{
    protected $table = 'applicant_notes';
    protected $fillable = [
        // 'id',
        'note_uid',
        'user_id',
        'applicant_id',
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
