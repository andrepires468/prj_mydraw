<?php

namespace App\Providers;

use App\Services\ImageMetadataExtractor;
use App\Services\LoraMetadataEnricher;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton(ImageMetadataExtractor::class);
        $this->app->singleton(LoraMetadataEnricher::class);
    }
}
