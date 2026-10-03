<?php

namespace App\Http\Controllers;

use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class IdentityController extends Controller
{
    public function me(Request $request, TenantContext $context): mixed
    {
        $actor = $request->attributes->get('trusted_actor');

        return $context->run($actor->tenantId, fn () => response()->json(['data' => DB::table('users')->where('id', $actor->id)->first(['id', 'tenant_id', 'employee_id', 'display_name'])]));
    }
}
