<?php

declare(strict_types=1);

namespace Atannex\Matrix;

use Atannex\Matrix\Console\PruneCommand;
use Atannex\Matrix\Console\RecountCommand;
use Atannex\Matrix\Console\SignalsCommand;
use Atannex\Matrix\Console\StatsCommand;
use Atannex\Matrix\Contracts\ResolvesActors;
use Atannex\Matrix\Listeners\ClaimGuestActivity;
use Atannex\Matrix\Signals\BookmarkSignal;
use Atannex\Matrix\Signals\ClickSignal;
use Atannex\Matrix\Signals\CommentSignal;
use Atannex\Matrix\Signals\FollowSignal;
use Atannex\Matrix\Signals\RatingSignal;
use Atannex\Matrix\Signals\ReactionSignal;
use Atannex\Matrix\Signals\ReadSignal;
use Atannex\Matrix\Signals\Registry;
use Atannex\Matrix\Signals\ReportSignal;
use Atannex\Matrix\Signals\ReviewSignal;
use Atannex\Matrix\Signals\ShareSignal;
use Atannex\Matrix\Signals\ViewSignal;
use Atannex\Matrix\Support\ActorResolver;
use Atannex\Matrix\Support\Counters;
use Atannex\Matrix\Support\Moderation;
use Atannex\Matrix\Support\Presenter;
use Atannex\Matrix\Support\Throttle;
use Atannex\Matrix\Support\Tokens;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class MatrixServiceProvider extends ServiceProvider
{
    /**
     * The built-in signals, in recount order.
     *
     * @var array<int, class-string<Signals\Signal>>
     */
    public const SIGNALS = [
        ViewSignal::class,
        ReadSignal::class,
        ClickSignal::class,
        ShareSignal::class,
        ReactionSignal::class,
        RatingSignal::class,
        ReviewSignal::class,
        CommentSignal::class,
        BookmarkSignal::class,
        FollowSignal::class,
        ReportSignal::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/matrix.php', 'matrix');

        $this->app->singleton(Registry::class, fn () => new Registry(array_map(fn (string $class) => new $class, self::SIGNALS)));
        $this->app->singleton(Counters::class);
        $this->app->singleton(Moderation::class);
        $this->app->singleton(Throttle::class);
        $this->app->singleton(Tokens::class, fn ($app) => new Tokens((string) $app['config']->get('app.key')));
        $this->app->singleton(ResolvesActors::class, ActorResolver::class);
        $this->app->singleton(Matrix::class);
        $this->app->singleton(Presenter::class);

        // Built-in signals resolve to their registered instance.
        foreach (self::SIGNALS as $class) {
            $this->app->bind($class, fn ($app) => $app->make(Matrix::class)->signal($class));
        }
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if (config('matrix.routes.enabled', true)) {
            Route::prefix((string) config('matrix.routes.prefix', 'matrix'))
                ->name((string) config('matrix.routes.name', 'matrix.'))
                ->middleware((array) config('matrix.routes.middleware', ['web']))
                ->group(__DIR__.'/../routes/matrix.php');
        }

        if (config('matrix.claim_on_login', true)) {
            $this->app['events']->listen(Login::class, ClaimGuestActivity::class);
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/matrix.php' => config_path('matrix.php')], 'matrix-config');
            $this->publishes([__DIR__.'/../database/migrations' => database_path('migrations')], 'matrix-migrations');
            $this->publishes([__DIR__.'/../resources/js/matrix.js' => resource_path('js/vendor/matrix.js')], 'matrix-js');

            $this->commands([
                RecountCommand::class,
                StatsCommand::class,
                SignalsCommand::class,
                PruneCommand::class,
            ]);
        }
    }
}
