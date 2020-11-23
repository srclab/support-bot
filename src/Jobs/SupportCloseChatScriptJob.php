<?php

namespace SrcLab\SupportBot\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use SrcLab\SupportBot\Contracts\OnlineConsultant;
use Carbon\Carbon;
use SrcLab\SupportBot\Repositories\SupportScriptRepository;

class SupportCloseChatScriptJob implements ShouldQueue
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
        $script_repository = app(SupportScriptRepository::class);
        $online_consultant = app(OnlineConsultant::class);
        $unanswered_scripts = $script_repository->getNextUnansweredScripts();
        $config = array_merge(config('support_bot'), app_config('support_bot'));

        if($config['online_consultant'] != 'webim') {
            return;
        }

        foreach($unanswered_scripts as $unanswered_script) {

            $dialog = $online_consultant->getDialogFromClientByPeriod($unanswered_script->search_id);

            if($dialog['operator_id'] == $config['accounts']['webim']['bot_operator_id']) {
                $online_consultant->closeChat($unanswered_script->client_id);
            } else {
                /**
                 * Обнуление сценария в случае если диалог подхватил оператор.
                 *
                 * TODO: поставить 3 часа после проверки
                 */
                $unanswered_script->step = 0;
                $unanswered_script->send_message_at = Carbon::now()->addMinute(10);
                $unanswered_script->user_answered = false;
                $unanswered_script->start_script_at = null;

                $unanswered_script->save();
            }
        }


    }
}
