<?php

namespace Database\Seeders;

use Horsefly\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            UserSeeder::class,
            DeploymentIpSeeder::class,
            PermissionsTableSeeder::class,
            RoleSeeder::class,
            JobCategoriesSeeder::class,
            JobSourcesSeeder::class,
        ]);
    }
}
