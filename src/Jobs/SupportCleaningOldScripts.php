<?php

namespace SrcLab\SupportBot\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use SrcLab\OnlineConsultant\Contracts\OnlineConsultant;
use Carbon\Carbon;
use SrcLab\SupportBot\Repositories\SupportScriptRepository;

class SupportCleaningOldScripts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Количество раз, которое можно попробовать выполнить задачу.
     *
     * @var int
     */
    public $tries = 1;

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        /** @var \SrcLab\SupportBot\Repositories\SupportScriptRepository; $support_script_repository */
        $support_script_repository = app(SupportScriptRepository::class);

        $scripts = $support_script_repository->getNextScriptsWeekAgo();

        foreach($scripts as $script) {
            $script->delete();
        }
    }
}
