<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if (!Role::where('name', 'Admin')->exists()) {
            $this->call([
                RoleAndPermissionSeeder::class,
            ]);
        }

        User::updateOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test User',
                'username' => 'testuser',
                'password' => bcrypt('Ainaa*220'),
                'is_active' => true,
            ]
        )->assignRole('Admin');
    }
}