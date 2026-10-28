<?php

namespace SchemaSketch\Laravel\Api;

use RuntimeException;

class ApiException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0, public readonly array $data = [])
    {
        parent::__construct($message);
    }
}
