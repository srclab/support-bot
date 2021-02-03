<?php

namespace SrcLab\SupportBot\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use SrcLab\OnlineConsultant\Contracts\OnlineConsultant;
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

        if(!empty($config['redirect_chats']['not_working_hours']['period_begin']) && !empty($config['redirect_chats']['redirect_period_begin']) && check_current_time($config['redirect_chats']['not_working_hours']['period_begin'], $config['redirect_chats']['redirect_period_begin'])) {
            return;
        }

        $operators_ids = $online_consultant->getListOnlineOperatorsIds();

        /**
         * Получение разрешенных операторов.
         */
        if(!empty($config['redirect_chats']['except_operators_ids'])) {
            $operators_ids = array_diff($operators_ids, $config['redirect_chats']['except_operators_ids']);
        }

        if(!empty($operators_ids)) {
            $redirects = $support_redirect_chat_repository->getNextPart();

            /** @var \SrcLab\SupportBot\Models\SupportRedirectChatModel $redirect */
            foreach($redirects as $redirect) {

                $dialog = $online_consultant->getDialogFromClientByPeriod($redirect->client_id);
                $operator_id = $online_consultant->getParamFromDialog('operator_id', $dialog);

                if($online_consultant->isBot() && !$online_consultant->isDialogOnTheBot($dialog)) {
                    $redirect->delete();
                    continue;
                } elseif(!$online_consultant->isBot()) {
                    if(in_array($operator_id, $operators_ids)) {
                        $redirect->delete();
                        continue;
                    }
                }

                $online_consultant->redirectDialogToOperator($dialog, $operators_ids[array_rand($operators_ids)]);

                $redirect->delete();
            }
        }
    }
}
