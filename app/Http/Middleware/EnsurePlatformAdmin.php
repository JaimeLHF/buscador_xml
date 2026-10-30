<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Painel /admin: quem não é operador da plataforma recebe 404, sem saber que o painel existe. */
class EnsurePlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        abort_unless($user?->isPlatformAdmin(), 404);

        return $next($request);
    }
}
