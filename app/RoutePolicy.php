<?php
declare(strict_types=1);

namespace Tenyendama\SeoWatch;

final class RoutePolicy
{
    /** @var list<string> */
    private const SUPERUSER_ROUTES = [
        'settings',
        'settings/timezone',
        'users',
        'users/create',
        'users/delete',
        'users/status',
        'users/sessions',
        'users/reset-link',
        'users/reset-mail',
        'users/invite',
        'users/invite-resend',
        'audit',
        'mail/test',
        'oauth/start',
        'oauth/callback',
        'oauth/disconnect',
        'properties/refresh',
        'properties/activate',
        'import/run',
        'maintenance/normalize',
        'improvements/create',
        'improvements/update',
    ];

    public static function requiresSuperuser(string $route): bool
    {
        return in_array($route, self::SUPERUSER_ROUTES, true);
    }
}
