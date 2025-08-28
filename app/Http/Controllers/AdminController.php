<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\User;

class AdminController extends Controller
{
    public function dashboard()
    {
        $stats = [
            'users' => User::count(),
            'applications' => Application::count(),
            'earnings_cents' => (int) Application::sum('amount_cents'),
        ];

        return view('admin.dashboard', compact('stats'));
    }
}
