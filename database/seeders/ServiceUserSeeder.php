<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class ServiceUserSeeder extends Seeder
{
    public function run()
    {
        User::firstOrCreate(
            [
                'name' => 'Storage Worker',
                'email' => 'worker@laravel-nos.test',
                'password' => Hash::make('tukangarsip25')
            ]
        );
    }
}
