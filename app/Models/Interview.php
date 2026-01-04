<?php

namespace Horsefly;

use Illuminate\Database\Eloquent\Model;

class Interview extends Model
{
    protected $table = 'interviews';
    protected $fillable = [
        // 'id',
        'interview_uid',
        'user_id',
        'applicant_id',
        'sale_id',
        'schedule_date',
        'schedule_time',
        'status',
        'created_at',
        'updated_at',
    ];
}
