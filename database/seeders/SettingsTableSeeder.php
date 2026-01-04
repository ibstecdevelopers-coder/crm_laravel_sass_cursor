<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SettingsTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $settings = [
            [
                'key'   => 'site_name',
                'value' => 'CRM',
                'type'  => 'string',
                'group' => 'general',
            ],
            [
                'key'   => 'google_map_api_url',
                'value' => '',
                'type'  => 'string',
                'group' => 'google_maps',
            ],
            [
                'key'   => 'google_map_api_key',
                'value' => '',
                'type'  => 'string',
                'group' => 'google_maps',
            ],
            [
                'key'   => 'email_notifications',
                'value' => 'true',
                'type'  => 'boolean',
                'group' => 'notifications',
            ],
            [
                'key'   => 'sms_notifications',
                'value' => 'true',
                'type'  => 'boolean',
                'group' => 'notifications',
            ],
            [
                'key'   => 'sms_api_url',
                'value' => '',
                'type'  => 'string',
                'group' => 'sms',
            ],
            [
                'key'   => 'sms_port',
                'value' => '',
                'type'  => 'string',
                'group' => 'sms',
            ],
            [
                'key'   => 'sms_username',
                'value' => '',
                'type'  => 'string',
                'group' => 'sms',
            ],
            [
                'key'   => 'sms_password',
                'value' => '',
                'type'  => 'string',
                'group' => 'sms',
            ],
            
        ];

        foreach ($settings as $setting) {
            DB::table('settings')->updateOrInsert(
                ['key' => $setting['key']], // prevent duplicates
                [
                    'value' => $setting['value'],
                    'type'  => $setting['type'],
                    'group' => $setting['group'],
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }
}
