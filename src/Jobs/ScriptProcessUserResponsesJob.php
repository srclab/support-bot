<?php

namespace SrcLab\SupportBot\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use SrcLab\SupportBot\Repositories\SupportScriptAnswerRepository;
use SrcLab\SupportBot\Repositories\SupportScriptRepository;
use SrcLab\SupportBot\SupportBotScript;
use SrcLab\OnlineConsultant\Contracts\OnlineConsultant;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class ScriptProcessUserResponsesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Количество раз, которое можно попробовать выполнить задачу.
     *
     * @var int
     */
    public $tries = 1;

    /**
     * @var int
     */
    private $offset = 0;

    /**
     * ScriptProcessUserResponsesJob constructor.
     *
     * @param int $offset
     */
    public function __construct($offset)
    {
        $this->offset = $offset;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        /** @var \SrcLab\SupportBot\Repositories\SupportScriptAnswerRepository $script_answer_repository */
        $script_answer_repository = app(SupportScriptAnswerRepository::class);

        /** @var \SrcLab\OnlineConsultant\Contracts\OnlineConsultant $online_consultant */
        $online_consultant = app(OnlineConsultant::class);

        /** @var \SrcLab\SupportBot\SupportBotScript $support_script */
        $support_script = app(SupportBotScript::class);

        /** @var \Illuminate\Database\Eloquent\Collection $completed_scripts */
        $completed_scripts = app(SupportScriptRepository::class)->getNextCompletedScripts($this->offset);

        if($completed_scripts->isNotEmpty()) {

            /** @var \SrcLab\SupportBot\Models\SupportScriptModel $completed_script */
            foreach ($completed_scripts as $completed_script) {
                /**
                 * Получение диалога клиента.
                 */
                $dialog = $online_consultant->getDialogFromClientByPeriod($completed_script->search_id, [$completed_script->start_script_at]);

                $messages = $online_consultant->getParamFromDialog('messages', $dialog);

                for ($step = 1; $step <= 3; $step++) {

                    /**
                     * Получение индекса сообщения от бота на текущий шаг.
                     */
                    $script_message_index = $support_script->getScriptMessagesIndex($completed_script, ($step - 1), $messages);
                    

                    if(!empty($script_message_index)) {

                        if($step == 3 && empty($messages[$script_message_index - 1])) {
                            continue;
                        }

                        /**
                         * Получение сообщений клиента после сценарного сообщения.
                         */
                        $offset = ($step == 3) ? 1 : 2;
                        $step_messages = array_slice($messages, ($script_message_index + $offset));
                        $client_messages = $online_consultant->findClientMessages($step_messages);

                        /**
                         * Получение выбранного пользователем варианта.
                         */
                        $variant = $support_script->getAnswerUserOnStep($completed_script, ($step < 3) ? $step : 2, ($step == 3)
                            ? [$online_consultant->getParamFromMessage('message_text', $messages[$script_message_index - 1])]
                            : $client_messages
                        );

                        /**
                         * Запись ответа в базу данных.
                         */
                        if (! empty($variant)) {
                            $user_answer = $script_answer_repository->getUserAnswer($completed_script->client_id, $variant);

                            if (empty($user_answer)) {
                                $user_answer = $script_answer_repository->new();

                                $user_answer->client_id = $completed_script->search_id;
                                $user_answer->answer_option_id = $variant['id'];
                                $user_answer->created_at = Carbon::now();

                                if ($step == 3 && !empty($client_messages)) {
                                    $user_answer->comment = implode('<Br />', $client_messages);
                                }

                                $user_answer->save();
                            } elseif($step == 3 && !empty($client_messages)) {
                                $user_answer->comment = implode('<Br />', $client_messages);
                                $user_answer->save();
                            }
                        }
                    }
                }
            }

            /**
             * Запуск очереди на обработку следующей пачки реализованных сценариев.
             */
            $config = array_merge(config('support_bot'), app_config('support_bot'));

            if(empty($config['scripts']['job_queue'])) {
                Log::error('[SrcLab\SupportBot|Job] В конфиге отсутствует название очереди для скриптов');
            }

            ScriptProcessUserResponsesJob::dispatch($this->offset+5)->onQueue($config['scripts']['job_queue']);
        }


    }
}