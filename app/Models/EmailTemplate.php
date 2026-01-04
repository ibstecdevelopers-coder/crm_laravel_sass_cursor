<?php

namespace Horsefly;

use Illuminate\Database\Eloquent\Model;

class EmailTemplate extends Model
{
    protected $table = 'email_templates';
    protected $fillable = [
        'title',
        'slug',
        'from_email',
        'subject',
        'template',
        'is_active',
    ];
}
