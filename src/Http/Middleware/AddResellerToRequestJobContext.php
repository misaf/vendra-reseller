<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Factory;
use Illuminate\Http\Request;
use Misaf\VendraReseller\Models\ResellerUser;
use Misaf\VendraSupport\Context\ContextKeys;
use Misaf\VendraSupport\Context\RequestJobContext;
use Misaf\VendraTenant\Models\Tenant;
use Symfony\Component\HttpFoundation\Response;

final readonly class AddResellerToRequestJobContext
{
    public function __construct(private Factory $auth) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $resellerUser = $this->auth->guard('reseller')->user();
        $tenant = Tenant::current();
        $resellerId = $resellerUser instanceof ResellerUser
            ? $resellerUser->reseller_id
            : ($tenant instanceof Tenant ? $tenant->reseller_id : null);

        (new RequestJobContext(
            metadata: [ContextKeys::RESELLER_ID => $resellerId],
        ))->add();

        return $next($request);
    }
}
