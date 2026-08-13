<?php

declare(strict_types=1);

/*
 * Copyright @w33bvgl
 */

namespace App\Http\Middleware;

use MoonShine\Laravel\Http\Middleware\Authenticate;
use MoonShine\Laravel\MoonShineAuth;
use MoonShine\Permissions\Models\MoonshineUser;

class AutoLoginMiddleware extends Authenticate
{
    protected function authenticate($request, array $guards): void
    {
        $guard = MoonShineAuth::getGuard();

        if (! $guard->check()) {
            $user = MoonshineUser::query()->first();

            if ($user !== null) {
                $guard->login($user);
            }
        }

        parent::authenticate($request, $guards);
    }
}
