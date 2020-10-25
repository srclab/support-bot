<?php

namespace SrcLab\SupportBot\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use SrcLab\SupportBot\Contracts\OnlineConsultant;
use SrcLab\SupportBot\Repositories\SupportRedirectChatRepository;

class SupportRedirectChatJob implements ShouldQueue
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
        $config = array_merge(config('support_bot'), app_config('support_bot'));
        $online_consultant = app(OnlineConsultant::class);
        $support_redirect_chat_repository = app(SupportRedirectChatRepository::class);

        if(!empty($config['redirect_chats']['working_hours']['period_begin']) && !empty($config['redirect_chats']['working_hours']['period_end']) && !check_current_time($config['redirect_chats']['working_hours']['period_begin'], $config['redirect_chats']['working_hours']['period_end'])) {
            return;
        }

        $operators_ids = $online_consultant->getListOnlineOperatorsIds();

        if(!empty($operators_ids)) {
            $redirects = $support_redirect_chat_repository->getNextPart();

            /** @var \SrcLab\SupportBot\Models\SupportRedirectChatModel $redirect */
            foreach($redirects as $redirect) {

                /**
                 * Проверка находится ли диалог на боте Webim.
                 */
                if($config['online_consultant'] == 'webim') {
                    $dialog = $online_consultant->getDialogFromClientByPeriod($redirect->client_id);

                    if($dialog['operator_id'] != $config['accounts']['webim']['bot_operator_id']) {
                        continue;
                    }
                }

                $online_consultant->redirectClientToChat($redirect->client_id, $operators_ids[array_rand($operators_ids)]);

                $redirect->delete();
            }
        }
    }
}