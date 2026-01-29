<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\PlanFeature;

class PlanSeeder extends Seeder
{
    public function run()
    {
        /* ===============================
         | STARTER
         =============================== */
        $starter = Plan::create([
            'code' => 'starter',
            'name' => 'Starter',
            'description' => 'Perfect for small teams',
            'status' => 'available',
            'is_active' => true,
        ]);

        $starter->prices()->createMany([
            [
                'period' => 'monthly',
                'price' => 30,
                'currency' => 'usd',
                'per_user' => false,
                'trial_days' => 30,
            ],
            [
                'period' => 'yearly',
                'price' => 300,
                'currency' => 'usd',
                'per_user' => false,
                'trial_days' => 30,
            ],
        ]);

        $starter->features()->createMany([
            ['title' => '30-days free trial', 'sort' => 1],
            ['title' => 'Up to 5 users', 'sort' => 2],
            ['title' => 'Live chat support', 'sort' => 3],
        ]);

        /* ===============================
         | BUSINESS
         =============================== */
        $business = Plan::create([
            'code' => 'business',
            'name' => 'Business',
            'description' => 'For growing teams and companies',
            'status' => 'available',
            'is_active' => true,
        ]);

        $business->prices()->createMany([
            [
                'period' => 'monthly',
                'price' => 99,
                'currency' => 'usd',
                'per_user' => true,
                'min_users' => 5,
                'trial_days' => 14,
            ],
            [
                'period' => 'yearly',
                'price' => 999,
                'currency' => 'usd',
                'per_user' => true,
                'min_users' => 5,
                'trial_days' => 14,
            ],
        ]);

        $business->features()->createMany([
            ['title' => 'Unlimited projects', 'sort' => 1],
            ['title' => 'Team access', 'sort' => 2],
            ['title' => 'Priority support', 'sort' => 3],
        ]);

        /* ===============================
         | ENTERPRISE
         =============================== */
        $enterprise = Plan::create([
            'code' => 'enterprise',
            'name' => 'Enterprise',
            'description' => 'Custom solution for large companies',
            'status' => 'request',
            'is_active' => true,
        ]);

        $enterprise->features()->createMany([
            ['title' => 'Custom pricing', 'sort' => 1],
            ['title' => 'SSO / SAML', 'sort' => 2],
            ['title' => 'Dedicated manager', 'sort' => 3],
        ]);
    }
}