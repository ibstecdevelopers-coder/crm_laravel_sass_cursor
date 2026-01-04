<?php

namespace Horsefly;

use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    protected $fillable = [
        'user_id',
        'sale_id',
        'applicant_id',
        'type',
        'message',
        'status',
        'notify_by',   // 👈 added here
    ];
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
    public function applicant()
    {
        return $this->belongsTo(Applicant::class, 'applicant_id');
    }
    public function sale()
    {
        return $this->belongsTo(Sale::class, 'sale_id');
    }
}
