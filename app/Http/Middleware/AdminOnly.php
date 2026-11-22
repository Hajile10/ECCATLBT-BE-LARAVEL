<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
class AdminOnly { public function handle(Request $request, Closure $next) { return (new LibraryRole)->handle($request, $next, 'admin'); } }
