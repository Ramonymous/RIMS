<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        // Reset permission cache
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Create permissions if not exist
        Permission::firstOrCreate(['name' => 'manage']);
        Permission::firstOrCreate(['name' => 'receiver']);
        Permission::firstOrCreate(['name' => 'outgoer']);

        // Create role admin if not exists
        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        
        // Assign all permissions to admin role
        $adminRole->syncPermissions(['manage', 'receiver', 'outgoer']);

        // Create admin user (idempotent)
        $user = User::firstOrCreate(
            ['email' => 'me@r-dev.asia'],
            [
                'name' => 'Rama Pamungkas',
                'password' => Hash::make('Rama123'), // ganti di prod
            ]
        );

        // Assign role
        if (! $user->hasRole('admin')) {
            $user->assignRole($adminRole);
        }
    }
}
