<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCurrentOrganization
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->current_organization_id !== null && $user->belongsToOrganization($user->current_organization_id)) {
            return $next($request);
        }

        $organization = $user->organizations()->oldest('organization_user.created_at')->first();

        if ($organization === null) {
            return redirect()->route('organizations.create');
        }

        $user->switchOrganization($organization);

        return $next($request);
    }
}
