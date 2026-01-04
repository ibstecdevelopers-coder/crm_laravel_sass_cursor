<?php

namespace Horsefly;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class Sale extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'sales';
    protected $fillable = [
        'id',
        'sale_uid',
        'user_id',
        'office_id',
        'unit_id',
        'job_category_id',
        'job_title_id',
        'job_type',
        'position_type',
        'sale_postcode',
        'lat',
        'lng',
        'cv_limit',
        'timing',
        'experience',
        'salary',
        'benefits',
        'qualification',
        'sale_notes',
        'job_description',
        'status',
        'is_on_hold',
        'is_re_open',
        'created_at',
        'updated_at'
    ];

    public function getFormattedPostcodeAttribute()
    {
        return strtoupper($this->sale_postcode ?? '-');
    }
    public function getFormattedCreatedAtAttribute()
    {
        return $this->created_at ? $this->created_at->format('d M Y, h:i A') : '-';
    }
    public function getFormattedUpdatedAtAttribute()
    {
        return $this->updated_at ? $this->updated_at->format('d M Y, h:i A') : '-';
    }
    public function jobCategory()
    {
        return $this->belongsTo(JobCategory::class, 'job_category_id');
    }
    public function jobTitle()
    {
        return $this->belongsTo(JobTitle::class, 'job_title_id');
    }
    public function unit()
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }
    public function office()
    {
        return $this->belongsTo(Office::class, 'office_id');
    }
    public function documents()
    {
        return $this->hasMany(SaleDocument::class);
    }
    public function saleNotes()
    {
        return $this->hasMany(SaleNote::class, 'sale_id', 'id');
    }
    public function audits()
    {
        return $this->morphMany(Audit::class, 'auditable');
    }
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
    public function active_cvs()
    {
        return $this->hasMany(CVNote::class, 'sale_id', 'id')->where('status', 1);
    }
    // public function updated_by_audits()
    // {
    //     return $this->morphMany(Audit::class, 'auditable')->with('user')
    //         ->where('message', 'like', '%has been updated%');
    // }
    // public function created_by_audit()
    // {
    //     return $this->morphOne(Audit::class, 'auditable')->with('user')
    //         ->where('message', 'like', '%has been created%');
    // }
    public function updated_by_audits()
    {
        return $this->morphMany(Audit::class, 'auditable')
            ->where('message', 'like', '%has been updated%')
            ->with('user');
    }

    public function created_by_audit()
    {
        return $this->morphOne(Audit::class, 'auditable')
            ->where('message', 'like', '%has been created%')
            ->with('user');
    }
}
