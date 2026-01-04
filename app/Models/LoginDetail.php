<?php

namespace Horsefly;

use Illuminate\Database\Eloquent\Model;

class LoginDetail extends Model
{
    protected $table = 'login_details';
    protected $fillable = [
        'user_id',
        'ip_address',
        'login_at',
        'logout_at'
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
