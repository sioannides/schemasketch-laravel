<?php

namespace SchemaSketch\Laravel\Tests\Fixtures\Models;

class NotAModel
{
    public function __construct()
    {
        throw new \LogicException('must never be constructed');
    }
}
