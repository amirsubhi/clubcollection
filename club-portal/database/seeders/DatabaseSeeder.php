<?php

namespace Database\Seeders;

use App\Models\Club;
use App\Models\ExpenseCategory;
use App\Models\FeeRate;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Super Admin
        User::create([
            'name'     => 'Super Admin',
            'email'    => 'superadmin@clubportal.com',
            'password' => Hash::make('Admin@123'),
            'role'     => 'super_admin',
        ]);

        // Sample club
        $club = Club::create([
            'name'      => 'Recreation Club',
            'email'     => 'recreation@company.com',
            'is_active' => true,
        ]);

        // Default fee rates
        $rates = [
            'gm'        => 50.00,
            'agm'       => 30.00,
            'manager'   => 20.00,
            'executive' => 10.00,
            'non_exec'  => 5.00,
        ];

        foreach ($rates as $level => $amount) {
            FeeRate::create([
                'club_id'        => $club->id,
                'job_level'      => $level,
                'monthly_amount' => $amount,
                'effective_from' => now()->startOfMonth()->toDateString(),
                'effective_to'   => null,
            ]);
        }

        // Default expense categories
        $categories = [
            'Event',
            'Food & Beverage',
            'Supplies',
            'Decoration',
            'Transportation',
            'Printing & Stationery',
            'Maintenance',
            'Miscellaneous',
        ];

        foreach ($categories as $name) {
            ExpenseCategory::create([
                'club_id' => $club->id,
                'name'    => $name,
            ]);
        }
    }
}
