<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\Role;
use App\Models\User;

class InitialSeeder extends Seeder
{
    public function run()
    {
        echo "=== Seeding roles ===\n";

        /* ============================
         | CREATE ROLES
         ============================ */
        $roles = [
            1 => 'owner',
            2 => 'admin',
            3 => 'manager',
            4 => 'user',
        ];

        foreach ($roles as $id => $name) {
            Role::updateOrCreate(
                ['id' => $id],
                ['name' => $name]
            );
        }

        echo "Roles created.\n";


        /* ============================
         | CREATE USERS
         ============================ */

        echo "=== Seeding users ===\n";

        $users = [
            [
                'name' => 'Project Owner',
                'email' => 'owner@lookdesk.ai',
                'password' => Hash::make('owner123'),
                'role_id' => 1,
            ],
            [
                'name' => 'Admin',
                'email' => 'admin@lookdesk.ai',
                'password' => Hash::make('admin123'),
                'role_id' => 2,
            ],
            [
                'name' => 'Manager',
                'email' => 'manager@lookdesk.ai',
                'password' => Hash::make('manager123'),
                'role_id' => 3,
            ],
            [
                'name' => 'User',
                'email' => 'user@lookdesk.ai',
                'password' => Hash::make('user123'),
                'role_id' => 4,
            ],
        ];

        foreach ($users as $data) {
            User::updateOrCreate(
                ['email' => $data['email']],
                $data
            );
        }

        echo "Users created.\n";
    }
}