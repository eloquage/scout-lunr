<?php

declare(strict_types=1);

namespace Tests;

use IllumaLaw\ScoutLunr\LunrScoutServiceProvider;
use Laravel\Scout\ScoutServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            ScoutServiceProvider::class,
            LunrScoutServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app): void
    {
        config()->set('scout-lunr.worker_url', 'https://lunr.test');
        config()->set('scout-lunr.secret', 'test-secret');
        config()->set('scout-lunr.timeout', 5);
        config()->set('scout.driver', 'lunr');
    }
}
