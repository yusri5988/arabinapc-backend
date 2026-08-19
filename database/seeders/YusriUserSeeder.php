<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class YusriUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['phone' => '0193663437'],
            [
                'name' => 'Yusri',
                'password' => Hash::make('123456'),
                'role' => 'admin',
                'balance' => 0,
            ]
        );
    }
}
