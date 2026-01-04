<?php

namespace Horsefly;

use Illuminate\Database\Eloquent\Model;

class ModuleNote extends Model
{
    protected $table = 'module_notes';
    protected $fillable = [
        // 'id',
        'module_note_uid',
        'user_id',
        'module_noteable_id',
        'module_noteable_type',
        'details',
        'status',
        'created_at',
        'updated_at'
    ];
    public function module_noteable()
    {
        return $this->morphTo();
    }
    // protected static function boot()
    // {
    //     parent::boot();

    //     static::created(function ($note) {
    //         // Generate MD5 hash of the newly assigned ID
    //         $note->module_note_uid = md5($note->id);
    //         $note->save(); // Save after assigning UID
    //     });
    // }

}
