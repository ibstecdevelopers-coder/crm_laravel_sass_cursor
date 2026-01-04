<?php

namespace Horsefly;

use Illuminate\Database\Eloquent\Model;

class History extends Model
{
    protected $table = 'history';
    protected $fillable = [
        // 'id',
        'history_uid',
        'user_id',
        'applicant_id',
        'sale_id',
        'stage',
        'sub_stage',
        'status',
        'created_at',
        'updated_at'
    ];

    public function applicant()
    {
        return $this->belongsTo(Applicant::class, 'applicant_id');
    }
}
