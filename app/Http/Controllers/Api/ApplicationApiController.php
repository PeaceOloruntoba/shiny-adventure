<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Application;
use Illuminate\Http\Request;

class ApplicationApiController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $apps = Application::query()
            ->when($user, fn($q) => $q->where('user_id', $user->id))
            ->latest()
            ->paginate(10);

        return response()->json($apps);
    }

    public function show(Request $request, Application $application)
    {
        $this->authorize('view', $application);
        return response()->json($application);
    }
}
