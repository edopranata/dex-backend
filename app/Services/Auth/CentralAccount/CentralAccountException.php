<?php

namespace App\Services\Auth\CentralAccount;

use RuntimeException;

/**
 * A login or access check through Account that did not succeed. The reason decides what happens next:
 * `denied` ends the session, `unreachable` keeps it (Account is down, not the user's fault).
 */
class CentralAccountException extends RuntimeException
{
    public const DENIED = 'denied';

    public const UNREACHABLE = 'unreachable';

    public const CONFLICT = 'conflict';

    public function __construct(public readonly string $reason, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $reason);
    }

    public static function denied(string $message = ''): self
    {
        return new self(self::DENIED, $message);
    }

    public static function unreachable(string $message = ''): self
    {
        return new self(self::UNREACHABLE, $message);
    }
}
