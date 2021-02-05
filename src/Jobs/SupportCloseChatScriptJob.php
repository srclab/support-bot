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
        $online_consultant = app(OnlineConsultant::class);
        if(!$online_consultant->isCloseChatFunction()) {
            return;
        }

        $script_repository = app(SupportScriptRepository::class);
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
                 * Если недавно были сообщения от клиента или оператора не закрывать диалог.
                 */
                $messages = $online_consultant->getParamFromDialog('messages', $dialog);

                if(!empty($messages)) {
                    do {
                        $message = array_pop($messages);
                    } while (!empty($message) && !in_array($online_consultant->getParamFromMessage('who_send', $message), [
                        'operator',
                        'client'
                    ]));
                }

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
                $unanswered_script->step = 0;
                $unanswered_script->send_message_at = Carbon::now()->addHour(3);
                $unanswered_script->user_answered = false;
                $unanswered_script->start_script_at = null;

                $unanswered_script->save();
            }
        }


    }
}
