<?php

namespace Horsefly;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
class Office extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'offices';
    protected $fillable = [
        'id',
        'office_uid',
        'user_id',
        'office_name',
        'office_type',
        'office_website',
        'office_postcode',
        'office_notes',
        'office_lat',
        'office_lng',
        'created_at',
        'updated_at',
        'status',
        'created_at',
        'updated_at',
    ];
    protected $hidden = [
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    public function getFormattedOfficeNameAttribute()
    {
        return ucwords(strtolower($this->office_name));
    }
    public function getFormattedPostcodeAttribute()
    {
        return strtoupper($this->office_postcode ?? '-');
    }
        public function getFormattedCreatedAtAttribute()
    {
        return $this->created_at ? $this->created_at->format('d M Y, h:i A') : '-';
    }
    public function getFormattedUpdatedAtAttribute()
    {
        return $this->updated_at ? $this->updated_at->format('d M Y, h:i A') : '-';
    }
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
    public function sales()
    {
        return $this->hasMany(Sale::class, 'office_id');
    }
    public function units()
    {
        return $this->hasMany(Unit::class, 'office_id');
    }
    public function contact()
    {
        return $this->morphMany(Contact::class, 'contactable');
    }
    public function audits()
    {
        return $this->morphMany(Audit::class, 'auditable');
    }
    public function module_note()
    {
        return $this->morphMany(ModuleNote::class, 'module_noteable');
    }

}
