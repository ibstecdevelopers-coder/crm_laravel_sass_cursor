<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AllowedIpsSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('allowed_ips')->insert([
            ['ip_prefix' => '192.168.110'],
            ['ip_prefix' => '127.0.0'],
        ]);
    }
}