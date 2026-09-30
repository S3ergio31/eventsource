<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Dos usuarios, para poder demostrar el aislamiento de canales privados:
     * un evento para uno no debe aparecer en la sesion del otro.
     */
    public function run(): void
    {
        $users = [
            ['name' => 'Ana Demo', 'email' => 'ana@example.com'],
            ['name' => 'Beto Demo', 'email' => 'beto@example.com'],
        ];

        foreach ($users as $user) {
            User::updateOrCreate(
                ['email' => $user['email']],
                [
                    'name' => $user['name'],
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                ],
            );
        }
    }
}
