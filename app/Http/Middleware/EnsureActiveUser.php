<?php

namespace App\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;

class EnsureActiveUser
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->user() && ! $request->user()->fresh()?->canAccessPanel(Filament::getPanel('admin'))) {
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            abort(403, 'This account is inactive or has no application access.');
        }

        return $next($request);
    }
}
