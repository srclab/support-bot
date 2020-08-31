<?php

namespace SrcLab\SupportBot;

use Illuminate\Support\ServiceProvider;
use SrcLab\SupportBot\Commands\ClearExceptionScriptsCache;

class SupportBotServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        /**
         * Публикация необходимых файлов.
         */
        $this->publishes([
            __DIR__.'/../config/support_bot.php' => config_path('support_bot.php'),
        ]);

        /**
         * Миграции.
         */
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        /**
         * Роуты.
         */
        $this->loadRoutesFrom(__DIR__.'/routes.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                ClearExceptionScriptsCache::class
            ]);
        }

    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton(\SrcLab\SupportBot\Contracts\OnlineConsultant::class, \SrcLab\SupportBot\Services\TalkMe\TalkMe::class);
    }

}