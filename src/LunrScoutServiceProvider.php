<?php

declare(strict_types=1);

namespace IllumaLaw\ScoutLunr;

use IllumaLaw\ScoutLunr\Engines\LunrEngine;
use Laravel\Scout\EngineManager;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

/**
 * and search operations through {@see LunrEngine}.
 */
final class LunrScoutServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('scout-lunr')
            ->hasConfigFile();
    }

    public function packageBooted(): void
    {
        $this->app->make(EngineManager::class)->extend('lunr', function (): LunrEngine {
            return new LunrEngine(
                workerUrl: (string) config('scout-lunr.worker_url'),
                secret: (string) config('scout-lunr.secret'),
                timeout: (int) config('scout-lunr.timeout', 25),
            );
        });
    }
}
