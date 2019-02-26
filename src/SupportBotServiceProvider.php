<?php

namespace Vsesdal\SupportBot;

use Illuminate\Support\ServiceProvider;

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
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        /**
         * Роуты.
         */
        $this->loadRoutesFrom(__DIR__.'/routes.php');

    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton(\Vsesdal\SupportBot\Contracts\OnlineConsultant::class, \Vsesdal\SupportBot\Services\TalkMe::class);
    }

}