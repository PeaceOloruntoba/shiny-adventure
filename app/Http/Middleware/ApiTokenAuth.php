<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiTokenAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken() ?: $request->query('api_token');
        if (!$token) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }
        $user = User::where('api_token', $token)->first();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }
        auth()->login($user);
        return $next($request);
    }
}
