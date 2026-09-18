<?php

namespace App\Providers;

use App\Services\Ai\AiProviderInterface;
use App\Services\Ai\PlaceholderAiProvider;
use Illuminate\Support\ServiceProvider;

class SpeedPilotServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Swap in a real provider (e.g. an Anthropic-backed one) by binding it
        // here based on config('speedpilot.ai.provider') - no call-site changes
        // needed in AiRecommendationService or anything that consumes it.
        $this->app->bind(AiProviderInterface::class, function () {
            return match (config('speedpilot.ai.provider')) {
                default => new PlaceholderAiProvider,
            };
        });
    }
}
