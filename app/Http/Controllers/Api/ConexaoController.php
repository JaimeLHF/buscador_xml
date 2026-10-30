<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Quem é esta chave: o sistema que cola a chave chama aqui para confirmar a qual empresa ficou ligado. */
class ConexaoController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var ApiToken $token */
        $token = $request->attributes->get('api_token');

        return response()->json(['data' => [
            'conexao' => $token->name,
            'criada_em' => $token->created_at->toIso8601String(),
            // Chave antiga, sem empresa: vale para a organização inteira.
            'escopo' => $token->company_id ? 'empresa' : 'organizacao',
            'empresas' => $token->companies()->orderBy('razao_social')->get()->map(fn (Company $c) => [
                'cnpj' => $c->cnpj,
                'razao_social' => $c->razao_social,
                'nome_fantasia' => $c->nome_fantasia,
                'uf' => $c->uf->value,
            ])->all(),
        ]]);
    }
}
