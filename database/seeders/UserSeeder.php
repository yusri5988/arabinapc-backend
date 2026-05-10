<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::create([
            'name' => 'Admin Arabina',
            'phone' => '0123456789',
            'password' => bcrypt('123456'),
            'role' => 'admin',
            'balance' => 10000,
        ]);
    }
}
