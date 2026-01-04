<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Horsefly\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Disable model events if needed (e.g., observers)
        $dispatcher = User::getEventDispatcher();
        User::unsetEventDispatcher();

        User::create([
            'name' => 'admin',
            'email' => 'admin@gmail.com',
            'email_verified_at' => now(),
            'is_admin' => 1,
            'password' => Hash::make('password'),
            'remember_token' => Str::random(10),
        ]);

        // Restore events
        User::setEventDispatcher($dispatcher);
    }
}
