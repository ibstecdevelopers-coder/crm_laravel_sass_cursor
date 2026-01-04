<?php

namespace Horsefly;

use Illuminate\Database\Eloquent\Model;

class SmtpSetting extends Model
{
    protected $table = 'smtp_settings';

    protected $fillable = [
        'from_name',
        'from_address',
        'mailer',
        'host',
        'port',
        'username',
        'password',
        'encryption',
        'is_active',
    ];

    public $timestamps = false;

    /**
     * Get the SMTP settings.
     *
     * @return array
     */
    public static function getSettings()
    {
        return self::first();
    }
}
