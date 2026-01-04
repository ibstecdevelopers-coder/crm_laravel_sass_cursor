<?php

namespace Horsefly;

use Illuminate\Database\Eloquent\Model;

class SentEmail extends Model
{
    protected $table = 'sent_emails';
    protected $fillable = [
        // 'id',
        'user_id',
        'applicant_id',
        'sale_id',
        'action_name',
        'sent_from',
        'sent_to',
        'cc_emails',
        'subject',
        'title',
        'template',
        'status',
        'created_at',
        'updated_at'
    ];
}
