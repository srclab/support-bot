<?php

namespace SrcLab\SupportBot\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use SrcLab\OnlineConsultant\Contracts\OnlineConsultant;
use Carbon\Carbon;
use SrcLab\SupportBot\Repositories\SupportRedirectChatRepository;
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
        /** @var \SrcLab\OnlineConsultant\Contracts\OnlineConsultant $online_consultant */
        $online_consultant = app(OnlineConsultant::class);

        /**
         * Проверка есть ли у текущего чата функция закрытия чата.
         */
        if(!$online_consultant->isCloseChatFunction()) {
            return;
        }

        /** @var \SrcLab\SupportBot\Repositories\SupportRedirectChatRepository $support_redirect_chat_repository */
        $support_redirect_chat_repository = app(SupportRedirectChatRepository::class);

        /** @var \SrcLab\SupportBot\Repositories\SupportScriptRepository $script_repository */
        $script_repository = app(SupportScriptRepository::class);

        /** @var \Illuminate\Database\Eloquent\Collection $unanswered_scripts */
        $unanswered_scripts = $script_repository->getNextUnansweredScripts();

        /** @var \SrcLab\SupportBot\Models\SupportScriptModel $unanswered_script */
        foreach($unanswered_scripts as $unanswered_script) {

            $chat_closing_time = config('support_bot.scripts.chat_closing_time') ?? 24;
            $dialog = $online_consultant->getDialogFromClientByPeriod($unanswered_script->search_id, [Carbon::now()->subHours($chat_closing_time), Carbon::now()->endOfDay()]);

            if(empty($dialog)) {
                $unanswered_script->delete();
                Log::error("[SrcLab\SupportBot|SupportCloseChatScriptJob] Диалога/клиента с id {$unanswered_script->search_id} не существует.");
                return;
            }

            if($online_consultant->isBot() && $online_consultant->isDialogOnTheBot($dialog) || !$online_consultant->isBot()) {

                /**
                 * Если диалог находится в очереди на редирект не закрывать его.
                 */
                if($support_redirect_chat_repository->isExistRecord($online_consultant->getParamFromDialog('client_id', $dialog))) {
                    $unanswered_script->delete();
                    return;
                }

                $messages = $online_consultant->getParamFromDialog('messages', $dialog);

                /**
                 * Получение последнего сообщения от клиента или оператора.
                 */
                if(!empty($messages)) {
                    do {
                        $message = array_pop($messages);
                    } while (!empty($message) && !in_array($online_consultant->getParamFromMessage('who_send', $message), [
                        'operator',
                        'client'
                    ]));
                }

                /**
                 * Если сообщение от клиента/оператора было отправлено раньше срока закрытия удалить запись о редиректе.
                 */
                if(!empty($message) && $online_consultant->getParamFromMessage('created_at', $message)->diffInHours(Carbon::now()) < $chat_closing_time) {
                    $unanswered_script->delete();
                    return;
                }

                /**
                 * Закрытие чата.
                 */
                $online_consultant->closeChat($unanswered_script->search_id);

                /**
                 * Удаление скрипта.
                 */
                $unanswered_script->delete();
            } else {
                /**
                 * Обнуление сценария в случае если диалог подхватил оператор.
                 */
                $unanswered_script->delete();
            }
        }


    }
}
