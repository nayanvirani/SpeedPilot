<?php

namespace App\Providers;

use Anthropic\Client as AnthropicClient;
use App\Services\Ai\AiProviderInterface;
use App\Services\Ai\AnthropicAiProvider;
use App\Services\Ai\PlaceholderAiProvider;
use Illuminate\Support\ServiceProvider;

class SpeedPilotServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Swap in a real provider by setting AI_PROVIDER=anthropic and
        // AI_PROVIDER_API_KEY - no call-site changes needed in
        // AiRecommendationService or anything that consumes it. Falls back to
        // the placeholder whenever the key isn't configured, not just when
        // the provider name doesn't match.
        $this->app->bind(AiProviderInterface::class, function () {
            $apiKey = config('speedpilot.ai.api_key');

            if (config('speedpilot.ai.provider') === 'anthropic' && $apiKey) {
                return new AnthropicAiProvider(
                    new AnthropicClient(apiKey: $apiKey),
                    config('speedpilot.ai.model'),
                );
            }

            return new PlaceholderAiProvider;
        });
    }
}
