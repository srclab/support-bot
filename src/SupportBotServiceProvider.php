<?php

namespace SrcLab\SupportBot;

use Illuminate\Support\ServiceProvider;
use SrcLab\SupportBot\Commands\ClearExceptionScriptsCache;
use SrcLab\SupportBot\Commands\ScriptProcessUserResponses;
use SrcLab\SupportBot\Services\Messengers\TalkMe\TalkMe;
use SrcLab\SupportBot\Services\Messengers\Webim\Webim;

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

        /**
         * Команды.
         */
        if ($this->app->runningInConsole()) {
            $this->commands([
                ClearExceptionScriptsCache::class,
                ScriptProcessUserResponses::class
            ]);
        }
    }
}