<?php

declare(strict_types=1);

namespace Haccp\Support;

final class SessionCookie
{
    public static function value(string $token, bool $secure, int $maxAge = 604800): string
    {
        return sprintf(
            'haccp_session=%s; Path=/; Max-Age=%d; HttpOnly; SameSite=Strict%s',
            rawurlencode($token),
            $maxAge,
            $secure ? '; Secure' : '',
        );
    }

    public static function clear(bool $secure): string
    {
        return self::value('', $secure, 0) . '; Expires=Thu, 01 Jan 1970 00:00:00 GMT';
    }
}
