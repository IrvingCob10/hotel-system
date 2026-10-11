<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Hotel;
use App\Models\License;
use App\Models\Module;
use App\Models\Plan;
use App\Models\User;

class DashboardController extends Controller
{
    public function index()
    {
        $stats = [
            'customers' => Customer::count(),
            'hotels' => Hotel::count(),
            'users' => User::count(),
            'licenses' => License::count(),
            'plans' => Plan::count(),
            'modules' => Module::count(),
        ];

        return view('admin.dashboard', compact('stats'));
    }
}