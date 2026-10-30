<?php

namespace App\Providers;

use App\Models\Group;
use App\Support\Ai\AiManager;
use App\Support\Ai\AiProvider;
use App\Support\GroupFiles;
use App\View\Composers\NavigationComposer;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One manager per request, so the provider is built once. The interface
        // resolves to whichever provider config/ai.php selects; it throws, with
        // a readable reason, if none is set up, which is why features go through
        // AiGateway (never throws) rather than injecting the interface directly.
        $this->app->singleton(AiManager::class);
        $this->app->bind(AiProvider::class, fn ($app) => $app->make(AiManager::class)->provider());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // One shell, three navs — see DESIGN-NOTES.md.
        View::composer('components.layouts.app', NavigationComposer::class);

        /*
        | Chat uploads are files on disk, and no foreign key cascade can reach
        | them. Hanging this off the model event rather than off one controller
        | means every route to deleting a group — the lecturer's Delete button,
        | a subject being deleted with its groups, a seeder, tinker — disposes
        | of the files too. Runs inside the caller's transaction.
        */
        Group::deleting(function (Group $group): void {
            app(GroupFiles::class)->purge($group);
        });
    }
}
