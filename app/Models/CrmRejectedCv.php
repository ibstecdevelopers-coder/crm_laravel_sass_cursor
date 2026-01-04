<?php

namespace Horsefly;

use Illuminate\Database\Eloquent\Model;

class CrmRejectedCv extends Model
{
    protected $table = 'crm_rejected_cv';
    protected $fillable = [
        //'id',
        'crm_rejected_cv_uid',
        'applicant_id',
        'user_id',
        'crm_note_id',
        'sale_id',
        'reason',
        'crm_rejected_cv_note',
        'status',
        'created_at',
        'updated_at'
    ];
}
