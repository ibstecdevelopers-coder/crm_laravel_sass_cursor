<?php

namespace Horsefly;

use Illuminate\Database\Eloquent\Model;

class CVNote extends Model
{
    protected $table = 'cv_notes';
    protected $fillable = [
        // 'id',
        'cv_uid',
        'user_id',
        'sale_id', 
        'applicant_id',
        'details',
        'status',
        'created_at',
        'updated_at'
    ];
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
    public function sale()
    {
        return $this->belongsTo(Sale::class, 'sale_id', 'id');
    }

}
