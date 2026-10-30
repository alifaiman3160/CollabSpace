<?php

namespace App\Providers;

use App\Support\Marking\AiTrace;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;

class MarkingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AiTrace::class);
    }

    public function boot(): void
    {
        // See AiTrace: records a reply's status, Retry-After and body, only while
        // a suggestion request has a window open.
        Http::globalResponseMiddleware(fn ($response) => $this->app->make(AiTrace::class)->observe($response));
    }
}
