<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Autentica a API de integração pelo header "Authorization: Bearer <token>" e fixa a organização do token. */
class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $plain = (string) $request->bearerToken();
        $token = $plain !== '' ? ApiToken::findActive($plain) : null;

        if ($token === null) {
            return response()->json(['message' => 'Token inválido ou revogado.'], 401);
        }

        // Grava o último uso no máximo uma vez por minuto, para não escrever no banco a cada chamada.
        if ($token->last_used_at === null || $token->last_used_at->lt(now()->subMinute())) {
            $token->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        $request->attributes->set('api_token', $token);

        return $next($request);
    }
}
