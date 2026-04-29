<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        \App\Models\User::create([
            'name' => 'Admin Arabina',
            'email' => 'admin@arabina.com',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'balance' => 10000,
        ]);

        \App\Models\User::create([
            'name' => 'Supervisor 1',
            'email' => 'sv1@arabina.com',
            'password' => bcrypt('password'),
            'role' => 'supervisor',
            'balance' => 0,
        ]);
    }
}
