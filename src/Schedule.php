<?php

namespace SrcLab\SupportBot;

use Illuminate\Console\Scheduling\Schedule as SystemSchedule;

use SrcLab\SupportBot\Jobs\SupportSendingScriptMessageJob as SupportBotScriptJobCron;
use SrcLab\SupportBot\Jobs\SupportCloseChatScriptJob as SupportBotCloseChatScriptJobCron;
use SrcLab\SupportBot\Jobs\SupportRedirectChatJob as SupportBotRedirectChatJobCron;
use SrcLab\SupportBot\SupportBot as SupportBotCron;

use SrcLab\OnlineConsultant\Contracts\OnlineConsultant;
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
        $online_consultant = app(OnlineConsultant::class);

        /**
         * Отправка отложенных сообщений бота поддержки.
         *
         * TODO: после проверки everyMinute
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
         *
         * TODO: после проверки everyFiveMinutes
         */
        $schedule->call(function () use($queue) {
            try {
                SupportBotScriptJobCron::dispatch()->onQueue($queue);
            } catch (Throwable $e) {
                Log::error('[SrcLab\SupportBot|ScheduleTask] SupportBotScriptJobCron -> dispatch -> onQueue', $e);
            }
        })->everyMinute();

        /**
         * Отложенный редирект пользователя на оператора.
         *
         * TODO: после проверки everyTenMinutes
         */
        $schedule->call(function () use($queue) {
            try {
                SupportBotRedirectChatJobCron::dispatch()->onQueue($queue);
            } catch (Throwable $e) {
                Log::error('[SrcLab\SupportBot|ScheduleTask] SupportBotRedirectChatJobCron -> dispatch -> onQueue', $e);
            }
        })->everyMinute();

        if($online_consultant->isCloseChatFunction()) {
            /**
             * Закрытие чата при бездействии.
             *
             * TODO: после проверки everyTenMinutes
             */
            $schedule->call(function () use($queue) {
                try {
                    SupportBotCloseChatScriptJobCron::dispatch()->onQueue($queue);
                } catch (Throwable $e) {
                    Log::error('[SrcLab\SupportBot|ScheduleTask] SupportBotCloseChatScriptJobCron -> dispatch -> onQueue', $e);
                }
            })->everyMinute();
        }
    }
}