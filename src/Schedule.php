<?php

namespace SrcLab\SupportBot;

use Illuminate\Console\Scheduling\Schedule as SystemSchedule;

use SrcLab\SupportBot\Jobs\SupportSendingScriptMessageJob as SupportBotScriptJobCron;
use SrcLab\SupportBot\Jobs\SupportCloseChatScriptJob as SupportBotCloseChatScriptJobCron;
use SrcLab\SupportBot\Jobs\SupportRedirectChatJob as SupportBotRedirectChatJobCron;
use SrcLab\SupportBot\Jobs\SupportWebimDialogsGetParamSinceJob as SupportBotWebimDialogsGetParamSinceJobCron;
use SrcLab\SupportBot\SupportBot as SupportBotCron;

use Illuminate\Support\Facades\Log;

class Schedule
{
    /**
     * Планировщик.
     *
     * @param \Illuminate\Console\Scheduling\Schedule $schedule
     * @param string $queue
     */
    public static function schedule(SystemSchedule $schedule, $queue)
    {
        $config = array_merge(config('support_bot'), app_config('support_bot'));

        /**
         * Отправка отложенных сообщений бота поддержки.
         */
        $schedule->call(function () {
            try {
                app(SupportBotCron::class)->sendAnswers(true);
            } catch (Throwable $e) {
                Log::error('[SrcLab\SupportBot|ScheduleTask] SupportBotCron -> sendAnswers', $e);
            }
        })->everyMinute();

        /**
         * Запуск скриптов бота поддержки.
         */
        $schedule->call(function () use($queue) {
            try {
                SupportBotScriptJobCron::dispatch()->onQueue($queue);
            } catch (Throwable $e) {
                Log::error('[SrcLab\SupportBot|ScheduleTask] SupportBotScriptJobCron -> dispatch -> onQueue', $e);
            }
        })->everyFiveMinutes();

        /**
         * Отложенный редирект пользователя на оператора.
         */
        $schedule->call(function () use($queue) {
            try {
                SupportBotRedirectChatJobCron::dispatch()->onQueue($queue);
            } catch (Throwable $e) {
                Log::error('[SrcLab\SupportBot|ScheduleTask] SupportBotRedirectChatJobCron -> dispatch -> onQueue', $e);
            }
        })->everyTenMinutes();

        if($config['online_consultant'] == 'webim') {
            /**
             * Закрытие чата при бездействии.
             */
            $schedule->call(function () use($queue) {
                try {
                    SupportBotCloseChatScriptJobCron::dispatch()->onQueue($queue);
                } catch (Throwable $e) {
                    Log::error('[SrcLab\SupportBot|ScheduleTask] SupportBotCloseChatScriptJobCron -> dispatch -> onQueue', $e);
                }
            })->everyTenMinutes();

            /**
             * Сбор параметра since для получения диалогов.
             */
            $schedule->call(function () use($queue) {
                try {
                    SupportBotWebimDialogsGetParamSinceJobCron::dispatch()->onQueue($queue);
                } catch (Throwable $e) {
                    Log::error('[SrcLab\SupportBot|ScheduleTask] SupportBotWebimDialogsGetParamSinceJobCron -> dispatch -> onQueue', $e);
                }
            })->everyTenMinutes();
        }
    }
}