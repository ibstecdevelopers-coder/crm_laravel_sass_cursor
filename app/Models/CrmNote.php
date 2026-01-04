<?php

namespace Horsefly;

use Illuminate\Database\Eloquent\Model;

class CrmNote extends Model
{
    protected $table = 'crm_notes';
    protected $fillable = [
        //'id',
        'crm_notes_uid',
        'user_id',
        'applicant_id',
        'sale_id',
        'details',
        'moved_tab_to',
        'status',
        'created_at',
        'updated_at'
    ];

    public function getFormattedCreatedAtAttribute()
    {
        return $this->created_at ? $this->created_at->format('d M Y, h:i A') : '-';
    }
    public function getFormattedUpdatedAtAttribute()
    {
        return $this->updated_at ? $this->updated_at->format('d M Y, h:i A') : '-';
    }
    public function applicant()
    {
        return $this->belongsTo(Applicant::class, 'applicant_id');
    }
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
