<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentica a API de integração pelo header "Authorization: Bearer <token>" e fixa a chave na requisição.
 * Cada chamada fica registrada na conexão: rota, chave da nota, quantas notas saíram, status e IP.
 */
class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $plain = (string) $request->bearerToken();
        $token = $plain !== '' ? ApiToken::findActive($plain) : null;

        if ($token === null) {
            return response()->json(['message' => 'Token inválido ou revogado.'], 401);
        }

        $request->attributes->set('api_token', $token);

        $response = $next($request);

        $token->newQuery()->whereKey($token->id)->update([
            'last_used_at' => now(),
            'ultimo_ip' => $request->ip(),
            'total_chamadas' => DB::raw('total_chamadas + 1'),
        ]);

        $token->acessos()->create([
            'rota' => (string) str($request->route()?->getName() ?? $request->path())->after('api.')->limit(40, ''),
            'chave' => preg_match('/^\d{44}$/', (string) $request->route('chave')) === 1 ? $request->route('chave') : null,
            'quantidade' => $request->attributes->get('api_quantidade'),
            'status' => $response->getStatusCode(),
            'ip' => $request->ip(),
        ]);

        return $response;
    }
}
