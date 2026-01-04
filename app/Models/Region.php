<?php

namespace Horsefly;

use Illuminate\Database\Eloquent\Model;

class Region extends Model
{
    protected $table = 'regions';
    protected $fillable = [
        'name',
        'districts_code'
    ];
}
