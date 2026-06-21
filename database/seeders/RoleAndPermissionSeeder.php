<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Models\User;

class RoleAndPermissionSeeder extends Seeder
{
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // ===================== PERMISSIONS =====================
        $permissions = [
            // Admin
            'dashboard', 'users.manage', 'users.create', 'users.edit', 'users.delete',
            'donations.manage', 'donations.approve', 'donations.reject',
            'assessments.manage', 'assessments.create', 'assessments.view',
            'blood-banks.recommend',

            // Donor
            'donor.profile.view', 'donor.profile.edit',
            'donor.donations.create', 'donor.donations.view',

            // Blood Bank
            'bank.profile.view', 'bank.profile.edit',
            'bank.stock.manage', 'bank.stock.view',
            'bank.donations.view',

            // Seeker
            'seeker.profile.view', 'seeker.profile.edit',
            'seeker.requests.create', 'seeker.requests.view',

            // Common
            'notifications.view',
        ];

        foreach ($permissions as $permission) {
            Permission::create(['name' => $permission]);
        }

        // ===================== ROLES =====================
        $adminRole = Role::create(['name' => 'Admin']);
        $adminRole->givePermissionTo(Permission::all());

        $donorRole = Role::create(['name' => 'Donor']);
        $donorRole->givePermissionTo([
            'donor.profile.view', 'donor.profile.edit',
            'donor.donations.create', 'donor.donations.view',
            'notifications.view',
        ]);

        $bankRole = Role::create(['name' => 'Blood Bank']);
        $bankRole->givePermissionTo([
            'bank.profile.view', 'bank.profile.edit',
            'bank.stock.manage', 'bank.stock.view',
            'bank.donations.view',
            'notifications.view',
        ]);

        $seekerRole = Role::create(['name' => 'Seeker']);
        $seekerRole->givePermissionTo([
            'seeker.profile.view', 'seeker.profile.edit',
            'seeker.requests.create', 'seeker.requests.view',
            'notifications.view',
        ]);

        // ===================== DEFAULT ADMIN =====================
        $admin = User::create([
            'name' => 'Quratulain',
            'username' => 'dev.quratulain',
            'email' => 'dev.quratulain@gmail.com',
            'password' => bcrypt('Ainaa*220'),
            'is_active' => true,
        ]);
        $admin->assignRole('Admin');

        $this->command->info('Roles, Permissions, and Admin User created successfully!');
    }
}
