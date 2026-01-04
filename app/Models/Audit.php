<?php

namespace Horsefly;

use Illuminate\Database\Eloquent\Model;

class Audit extends Model
{
    protected $table = 'audits';
    protected $fillable = [
        // 'id',
        'user_id',
        'data',
        'message',
        'auditable_id',
        'auditable_type',
        'created_at',
        'updated_at'
    ];
    protected $casts = [
        'data' => 'array',
    ];

    // public function auditable()
    // {
    //     return $this->morphTo();
    // }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
