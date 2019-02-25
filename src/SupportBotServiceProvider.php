<?php

namespace Vsesdal\SupportBot;

use Illuminate\Support\ServiceProvider;

class SupportBotServiceProvider extends ServiceProvider
{
    /**
     * Indicates if loading of the provider is deferred.
     *
     * @var bool
     */
    protected $defer = true;

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
        $this->app->bind( \Vsesdal\SupportBot\Contracts\OnlineConsultant::class, \Vsesdal\SupportBot\SupportBot::class);
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return [
            'Vsesdal\SupportBot\Contracts\OnlineConsultant',
        ];
    }

}