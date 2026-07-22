<?php
declare(strict_types=1);

namespace Tenyendama\SeoWatch;

final class RoutePolicy
{
    /** @var list<string> */
    private const SUPERUSER_ROUTES = [
        'settings',
        'users',
        'users/create',
        'users/delete',
        'oauth/start',
        'oauth/callback',
        'oauth/disconnect',
        'properties/refresh',
        'properties/activate',
        'import/run',
        'maintenance/normalize',
    ];

    public static function requiresSuperuser(string $route): bool
    {
        return in_array($route, self::SUPERUSER_ROUTES, true);
    }
}
