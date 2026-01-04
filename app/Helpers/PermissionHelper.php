<?php

namespace App\Helpers;

class PermissionHelper
{
    public static function firstAllowedRoute($user)
    {
        $map = config('permission_redirects');

        foreach ($map as $permission => $route) {
            if ($user->can($permission)) {
                return route($route);
            }
        }

        // fallback if user has no permissions at all
        return route('no-permission');
    }
}