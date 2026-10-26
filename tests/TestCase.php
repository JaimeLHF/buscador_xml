<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    // No modo paralelo cada processo usa um banco Redis próprio (15, 14, … 2); senão o flushdb de um apaga o estado do outro.
    public function createApplication()
    {
        if ($token = (int) getenv('TEST_TOKEN')) {
            $db = (string) (15 - ($token - 1) % 14);

            foreach (['REDIS_DB', 'REDIS_CACHE_DB'] as $key) {
                putenv("{$key}={$db}");
                $_ENV[$key] = $_SERVER[$key] = $db;
            }
        }

        return parent::createApplication();
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
