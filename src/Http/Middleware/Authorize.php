<?php

namespace HalilCosdu\Slower\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class Authorize
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(Gate::forUser($request->user())->allows('viewSlower'), 403);

        return $next($request);
    }
}
