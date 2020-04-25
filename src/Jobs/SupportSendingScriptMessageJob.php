<?php


namespace SrcLab\SupportBot\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class SupportSendingScriptMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Количество раз, которое можно попробовать выполнить задачу.
     *
     * @var int
     */
    public $tries = 1;

    /**
     * Таймаут выполнения задачи.
     *
     * @var int
     */
    public $timeout = 300;

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        app(\SrcLab\SupportBot\SupportBotScript::class)->sendStartMessageForUser();
    }
}