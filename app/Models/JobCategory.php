<?php

namespace Horsefly;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class JobCategory extends Model
{
    use HasFactory;

    protected $table = 'job_categories';
    protected $fillable = [
        'name',
        'description',
        'is_active'
    ];
    public function applicants()
    {
        return $this->hasMany(Applicant::class, 'job_category_id');
    }
    public function jobTitles()
    {
        return $this->hasMany(JobTitle::class, 'job_category_id');
    }
}
