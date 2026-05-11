<?php

namespace App\Providers;

use App\Services\GalleryMetadataExtractionService;
use App\Services\GeminiGenerateContentClient;
use App\Services\GeminiPostCategorizationService;
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
        $this->app->singleton(GalleryMetadataExtractionService::class);
        $this->app->singleton(GeminiGenerateContentClient::class);
        $this->app->singleton(GeminiPostCategorizationService::class);
    }
}
