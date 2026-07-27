<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;

class Authenticate extends Middleware
{
    protected function redirectTo($request)
    {
        // 🔥 kalau API → jangan redirect
        if ($request->is('api/*')) {
            return null;
        }

        // default behavior (kalau web)
        return route('login');
    }
}