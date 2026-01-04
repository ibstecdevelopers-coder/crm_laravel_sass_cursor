<?php

namespace Horsefly;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;  

class JobTitle extends Model
{
    use HasFactory;

    protected $table = 'job_titles';
    protected $fillable = [
        'name',
        'type',
        'job_category_id',
        'description',
        'is_active',
        'related_titles'
    ];
    protected $casts = [
        'related_titles' => 'array',
        'is_active' => 'boolean',
    ];

    public function applicants()
    {
        return $this->hasMany(Applicant::class, 'job_title_id');
    }
    public function jobCategory()
    {
        return $this->belongsTo(JobCategory::class, 'job_category_id');
    }
}
