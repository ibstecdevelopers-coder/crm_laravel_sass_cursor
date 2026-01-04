<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class JobCategoriesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run()
    {
        $categories = [
            [
            'name' => 'Nurse',
            'description' => 'Nurse',
            'is_active' => true,
            ],
            [
            'name' => 'Non Nurse',
            'description' => 'Non Nurse',
            'is_active' => true,
            ],
            [
            'name' => 'Speicialist',
            'description' => 'Speicialist',
            'is_active' => true,
            ],
            [
            'name' => 'Chef',
            'description' => 'Chef',
            'is_active' => true,
            ],
            [
            'name' => 'Nursery',
            'description' => 'Nursery',
            'is_active' => true,
            ]
        ];

        foreach ($categories as $category) {
            DB::table('job_categories')->insert([
                'name' => $category['name'],
                'description' => $category['description'],
                'is_active' => $category['is_active'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}