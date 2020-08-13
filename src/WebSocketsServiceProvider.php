<?php

namespace BeyondCode\LaravelWebSockets;

use BeyondCode\LaravelWebSockets\Apps\AppProvider;
use BeyondCode\LaravelWebSockets\Dashboard\Http\Controllers\AuthenticateDashboard;
use BeyondCode\LaravelWebSockets\Dashboard\Http\Controllers\DashboardApiController;
use BeyondCode\LaravelWebSockets\Dashboard\Http\Controllers\SendMessage;
use BeyondCode\LaravelWebSockets\Dashboard\Http\Controllers\ShowDashboard;
use BeyondCode\LaravelWebSockets\Dashboard\Http\Middleware\Authorize as AuthorizeDashboard;
use BeyondCode\LaravelWebSockets\Server\Router;
use BeyondCode\LaravelWebSockets\Statistics\Http\Controllers\WebSocketStatisticsEntriesController;
use BeyondCode\LaravelWebSockets\Statistics\Http\Middleware\Authorize as AuthorizeStatistics;
use BeyondCode\LaravelWebSockets\WebSockets\Channels\ChannelManager;
use BeyondCode\LaravelWebSockets\WebSockets\Channels\ChannelManagers\ArrayChannelManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class WebSocketsServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->publishes([
            __DIR__ . '/../config/websockets.php' => base_path('config/websockets.php'),
        ], 'config');

        try {
            if (!Schema::hasTable('websockets_statistics_entries')) {
                $this->publishes([
                    __DIR__ . '/../database/migrations/create_websockets_statistics_entries_table.php.stub' => database_path('migrations/' . date('Y_m_d_His', time()) . '_create_websockets_statistics_entries_table.php'),
                ], 'migrations');
            }

            $this
                ->registerRoutes()
                ->registerDashboardGate();

            $this->loadViewsFrom(__DIR__ . '/../resources/views/', 'websockets');

            $this->commands([
                Console\StartWebSocketServer::class,
                Console\CleanStatistics::class,
                Console\RestartWebSocketServer::class,
            ]);
        } catch (QueryException $e) {
            // Exception raised by composer update
            // Usually happens when doing on CI where no DB exists at start
            // Either way if DB connection not obtained
            // Catching and doing nothing is ignore for composer update
        }
    }

    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/websockets.php', 'websockets');

        $this->app->singleton('websockets.router', function () {
            return new Router();
        });

        $this->app->singleton(ChannelManager::class, function () {
            return config('websockets.channel_manager') !== null && class_exists(config('websockets.channel_manager'))
            ? app(config('websockets.channel_manager')) : new ArrayChannelManager();
        });

        $this->app->singleton(AppProvider::class, function () {
            return app(config('websockets.app_provider'));
        });
    }

    /**
     * @return mixed
     */
    protected function registerRoutes()
    {
        Route::prefix(config('websockets.path'))->group(function () {
            Route::middleware(config('websockets.middleware', [AuthorizeDashboard::class]))->group(function () {
                Route::get('/', ShowDashboard::class);
                Route::get('/api/{appId}/statistics', [DashboardApiController::class, 'getStatistics']);
                Route::post('auth', AuthenticateDashboard::class);
                Route::post('event', SendMessage::class);
            });

            Route::middleware(AuthorizeStatistics::class)->group(function () {
                Route::post('statistics', [WebSocketStatisticsEntriesController::class, 'store']);
            });
        });

        return $this;
    }

    /**
     * @return mixed
     */
    protected function registerDashboardGate()
    {
        Gate::define('viewWebSocketsDashboard', function ($user = null) {
            return app()->environment('local');
        });

        return $this;
    }
}
