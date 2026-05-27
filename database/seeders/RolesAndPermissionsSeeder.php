<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Models\Admin;
use Illuminate\Support\Facades\Hash;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run()
    {
        // Create Permissions
        Permission::create(['name' => 'manage admins', 'guard_name' => 'admin']);
        Permission::create(['name' => 'view dashboard', 'guard_name' => 'admin']);

        // Create Roles
        $superAdminRole = Role::create(['name' => 'super_admin', 'guard_name' => 'admin']);
        $adminRole = Role::create(['name' => 'admin', 'guard_name' => 'admin']);

        // Assign Permissions to Roles
        $superAdminRole->givePermissionTo(Permission::all());
        $adminRole->givePermissionTo(['view dashboard']);

        // Create Super Admin User
        $superAdmin = Admin::updateOrCreate(
            ['email' => 'superadmin@aethyrtech.ai'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('password123')
            ]
        );
        $superAdmin->assignRole('super_admin');
    }
}
