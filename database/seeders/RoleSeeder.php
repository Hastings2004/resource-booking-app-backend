<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        Role::firstOrCreate(['name' => 'admin', 'display_name' => 'Administrator']);
        Role::firstOrCreate(['name' => 'staff', 'display_name' => 'Staff Member']);
        Role::firstOrCreate(['name' => 'student', 'display_name' => 'Student']);
    }
}