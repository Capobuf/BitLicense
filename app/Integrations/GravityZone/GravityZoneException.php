<?php

namespace App\Integrations\GravityZone;

use RuntimeException;

class GravityZoneException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $stopSynchronization = false)
    {
        parent::__construct($message);
    }
}
