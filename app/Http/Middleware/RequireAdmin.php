<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->session()->get('aidrama.admin',false)) {
            return redirect()->route('login');
        }
        return $next($request);
    }
}
