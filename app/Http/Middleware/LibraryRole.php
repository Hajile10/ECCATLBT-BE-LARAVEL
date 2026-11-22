<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
class LibraryRole {
    public function handle(Request $request, Closure $next, string $role) {
        $u=$request->user();
        if (!$u || !$u->active || ($role==='admin' ? $u->role!=='admin' : !in_array($u->role,['admin','librarian'])))
            return response()->json(['message'=>'Forbidden'],403);
        return $next($request);
    }
}
