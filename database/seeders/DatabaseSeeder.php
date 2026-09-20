<?php

namespace Database\Seeders;

use App\Models\BloodBank;
use App\Models\BloodStock;
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

        // Admin
        User::updateOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test Admin',
                'username' => 'testuser',
                'password' => bcrypt('Ainaa*220'),
                'is_active' => true,
            ]
        )->assignRole('Admin');

        // Normal user (unified donor + seeker)
        User::updateOrCreate(
            ['email' => 'user@example.com'],
            [
                'name' => 'Ayesha Khan',
                'username' => 'user',
                'password' => bcrypt('Ainaa*220'),
                'is_active' => true,
                'full_name' => 'Ayesha Khan',
                'phone' => '03001234567',
                'blood_group' => 'O+',
                'city' => 'Karachi',
                'address' => '123 Main Street, Karachi',
                'age' => 28,
                'gender' => 'Female',
                'weight' => 58.0,
                'status' => 'available',
            ]
        )->assignRole('User');

        // Blood bank
        $bankUser = User::updateOrCreate(
            ['email' => 'bank@example.com'],
            [
                'name' => 'City Blood Bank',
                'username' => 'citybank',
                'password' => bcrypt('Ainaa*220'),
                'is_active' => true,
            ]
        );
        if (!$bankUser->hasRole('Blood Bank')) {
            $bankUser->assignRole('Blood Bank');
        }

        $bank = BloodBank::updateOrCreate(
            ['user_id' => $bankUser->id],
            [
                'bank_name' => 'City Blood Bank',
                'phone' => '03009876543',
                'address' => '456 Hospital Road, Karachi',
                'registration_number' => 'BB-0001',
                'status' => 'active',
            ]
        );

        $groups = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
        foreach ($groups as $group) {
            BloodStock::updateOrCreate(
                ['bank_id' => $bank->id, 'blood_group' => $group],
                ['quantity' => 20]
            );
        }
    }
}