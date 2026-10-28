<?php

namespace SchemaSketch\Laravel\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use SchemaSketch\Laravel\SchemaSketchServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [SchemaSketchServiceProvider::class];
    }
}
