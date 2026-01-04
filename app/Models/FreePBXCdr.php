<?php

namespace Horsefly;

use Illuminate\Database\Eloquent\Model;

class FreePBXCdr extends Model
{
    protected $connection = 'freepbx'; // Use the FreePBX connection
    protected $table = 'cdr'; // FreePBX CDR table
    public $timestamps = false; // No created_at / updated_at

    protected $fillable = [
        'calldate', 'clid', 'src', 'dst', 'dcontext',
        'channel', 'dstchannel', 'lastapp', 'lastdata',
        'duration', 'billsec', 'disposition', 'amaflags',
        'accountcode', 'uniqueid', 'userfield'
    ];
}
