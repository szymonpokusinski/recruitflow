<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class LocalIntegrationAccess
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(in_array($request->ip(), ['127.0.0.1', '::1'], true), 403, 'Integracje są dostępne wyłącznie lokalnie.');
        abort_if(config('widgets.read_only_preview') && ! $request->isMethod('GET'), 403, 'Podgląd tylko do odczytu. Zapisy i synchronizacja są wyłączone.');

        return $next($request);
    }
}
