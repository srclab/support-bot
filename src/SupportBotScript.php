<?php

namespace SrcLab\SupportBot;

use Carbon\Carbon;
use SrcLab\SupportBot\Contracts\OnlineConsultant;
use SrcLab\SupportBot\Repositories\SupportScriptExceptionRepository;
use SrcLab\SupportBot\Repositories\SupportScriptRepository;

/** TODO: предусмотреть вариант что dialogId записанный в базе данных будет неактивным ( несуществует ) */
class SupportBotScript
{
    /**
     * @var \Illuminate\Config\Repository|mixed
     */
    protected $config;

    /**
     * @var \SrcLab\SupportBot\Contracts\OnlineConsultant
     */
    protected $online_consultant;

    /**
     * @var \SrcLab\SupportBot\Repositories\SupportScriptRepository
     */
    protected $scripts_repository;

    /**
     * @var \SrcLab\SupportBot\Repositories\SupportScriptExceptionRepository
     */
    protected $scripts_exception_repository;

    /**
     * SupportBotScripts constructor.
     */
    public function __construct()
    {
        $this->config = array_merge(config('support_bot'), app_config('support_bot'));
        $this->online_consultant = app(OnlineConsultant::class, ['config' => $this->config['accounts']]);
        $this->scripts_repository = app(SupportScriptRepository::class);
        $this->scripts_exception_repository = app(SupportScriptExceptionRepository::class);
    }

    /**
     * Планировка или обработка сценария для пользователя.
     *
     * @param int $search_id
     * @return bool
     */
    public function planingOrProcessScriptForUser($search_id)
    {
        /**
         * Проверка фильтра пользователей по id на сайте.
         */
        $only_user_ids = $this->config['scripts']['enabled_for_user_ids'] ?? [];

        if(!empty($only_user_ids) && !in_array($search_id, $only_user_ids)) {
            return false;
        }

        /** @var \SrcLab\SupportBot\Models\SupportScriptModel $script */
        $script = $this->scripts_repository->findBy(['search_id' => $search_id]);

        if(is_null($script)) {
            $this->planningPendingScripts($search_id);
        } else {
            if($script->step > 0) {
                if ($this->processScript($script)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Обработка сценария для пользователя.
     *
     * @param $script
     * @return bool
     */
    public function processScript($script)
    {
        if(empty($this->config['scripts']['clarification']['steps'][$script->step]) || $script->send_message_at > now()) {
            return false;
        }

        /**
         * Получение сообщений с пользователем.
         *
         * TODO: убрать метод сделать напрямую.
         */
        $dialog = $this->getClientDialog($script->search_id, [Carbon::now()->subDays(14), Carbon::now()->endOfDay()]);

        if(!$dialog) {
            return false;
        }

        $messages = $this->online_consultant->getParamFromDialog('messages', $dialog);

        if($script->step == $this->config['scripts']['clarification']['final_step']) {

            /**
             * Отправка пользователю финального сообщения сценария и деактивация сценария для пользователя.
             */
            $result = $this->getFinalMessageAndDeactivateScript($script);

        } else {

            /**
             * Получение последнего сообщения сценария отправленного ботом.
             */
            $client_messages = $this->getClientMessageAfterLastScriptMessage($script, $messages);


            if (!empty($client_messages)) {

                /**
                 * Проверка сообщения отправленного пользователем на соотвествие с одним из вариантов текущего шага.
                 */
                foreach ($this->config['scripts']['clarification']['steps'][$script->step]['variants'] as $variant) {


                    if (preg_match('/' . preg_quote($variant['button']) . '/iu', $client_messages)) {

                        if(!empty($variant['messages'])) {
                            $result = $variant['messages'];
                        }

                        if(!empty($variant['for_operator'])) {
                            /**
                             * Установка несуществующего шага для завершения скрипта.
                             */
                            $script->step = -1;
                            $script->save();

                            /**
                             * TODO: предусмотреть что операторов в сети не будет, тогда выводить сообщение что все операторы не в сети и ответят в 9 часов ( отложенно перекидывать утром ).
                             */
                            $operators = $this->online_consultant->getListOnlineOperatorsIds();
                            $this->online_consultant->redirectClientToChat($script->search_id, $operators[array_rand($operators)]);
                        } elseif (!empty($variant['is_final'])) {
                            /**
                             * Установка несуществующего шага для завершения скрипта.
                             */
                            $script->step = -1;
                            $script->save();

                        } elseif (!empty($variant['next_step'])) {
                            /**
                             * Установка следующего шага и сохранения предидущего.
                             */
                            $script->prev_step = $script->step;
                            $script->step = $variant['next_step'];
                            $buttons = array_column($this->config['scripts']['clarification']['steps'][$script->step]['variants'], 'button');
                        } else{
                            /**
                             * Установка следущего шага финальным.
                             */
                            $script->prev_step = $script->step;
                            $script->step = $this->config['scripts']['clarification']['final_step'];
                        }

                        $script->save();

                        break;

                    }
                }

                /**
                 * Отправка финального сообщения в случае если сообщения пользователя не совпало ни с одним из варианта текущего шага.
                 */
                if (empty($result)) {
                    $result = $this->getFinalMessageAndDeactivateScript($script);
                }

            }
        }

        if(!empty($result)) {

            $client_id = $this->online_consultant->getParamFromDialog('clientId', $dialog);

            /**
             * Отправка сообщений пользователю.
             */
            foreach($result as $message) {
                $this->online_consultant->sendMessage($client_id, $this->replaceMultipleSpacesWithLineBreaks($message));
            }

            if(!empty($buttons)) {
                $this->online_consultant->sendButtonsMessage($client_id, $buttons);
            }

            return true;

        }

        return false;
    }

    /**
     * Запуск сценария для пользователя.
     */
    public function sendStartMessageForUsers()
    {
        /**
         * Проверка является ли текущее время рабочим временем отправки уведомлений.
         */
        if(!$this->checkTime(Carbon::now()->format('H:i'), $this->config['scripts']['send_notification_period']['period_begin'], $this->config['scripts']['send_notification_period']['period_end'])) {
            return;
        }

        $scripts = $this->scripts_repository->getNextScripts();

        if($scripts->isEmpty()) return;

        /** @var \SrcLab\SupportBot\Models\SupportScriptModel $script */
        foreach($scripts as $script) {

            /**
             * Проверка времени последнего сообщения клиента.
             *
             * TODO: раскоментировать после проверки.
             */
            $dialog = $this->online_consultant->getDialogFromClient($script->search_id, [Carbon::now()->subDays(14), Carbon::now()->endOfDay()]);

            /*$datetime_message_client = $this->online_consultant->getDateTimeClientLastMessage($dialog);*/

            /** @var \Carbon\Carbon $datetime_message_client */
            /*if(!empty($datetime_message_client) && $datetime_message_client->diffInHours(Carbon::now()) < 3) {
                $script->send_message_at = $datetime_message_client->addHour(3);
                $script->save();
                continue;
            }*/

            $result = $this->getResultForScript($script, $dialog);

            if (!empty($result)) {

                $script->step++;
                $script->prev_step = 0;
                $script->save();

                $this->online_consultant->sendMessage($result['clientId'], $this->replaceMultipleSpacesWithLineBreaks($result['result']));
                $this->online_consultant->sendButtonsMessage($result['clientId'], $result['buttons']);

            } else {
                $script->delete();
            }
        }
    }

    /**
     * Получение сообщения и ID клиента для сценария.
     *
     * @param \SrcLab\SupportBot\Models\SupportScriptModel $script
     * @param array $dialog
     * @return false|array
     */
    private function getResultForScript($script, array $dialog)
    {
        $client_name = $this->online_consultant->getParamFromDialog('name', $dialog);
        $messages = $this->online_consultant->getParamFromDialog('messages', $dialog);
        
        $operator_messages = $this->online_consultant->findOperatorMessages($messages);
        $client_messages = $this->online_consultant->findClientMessages($messages);

        /**
         * Получение исключений.
         */
        $exceptions = $this->scripts_exception_repository->getAllException();

        if (preg_match( '/' . $this->config['scripts']['select_message'] . '/iu', $operator_messages)) {

            foreach ($exceptions as $exception) {

                if (preg_match('/(?:' . addcslashes($exception->exception, "/") . ')/iu', $client_messages)) {
                    $stop_word = true;
                    break;
                }
            }

            if (empty($stop_word)) {
                $result = $this->insertClientNameInString($this->config['scripts']['clarification']['message'], $client_name);
                $buttons = array_column($this->config['scripts']['clarification']['steps'][1]['variants'], 'button');
            }
        }

        if(empty($result)) {
            return false;
        }

        return [
            'clientId' => $this->online_consultant->getParamFromDialog('clientId', $dialog),
            'result' => $result,
            'buttons' => $buttons
        ];
    }

    /**
     * Получение диалога с клиентом.
     *
     * @param int $search_id
     * @param array $filter
     * @return false|array
     */
    private function getClientDialog($search_id, array $filter)
    {
        /**
         * Получение сообщений.
         */
        $dialog =  $this->online_consultant->getDialogFromClient($search_id, $filter);

        return empty($dialog) ? false : $dialog;
    }

    /**
     * Получить сообщения клиента после последнего отправленного сообщения сценария.
     *
     * @param \SrcLab\SupportBot\Models\SupportScriptModel $script
     * @param array $messages
     * @return bool|string
     */
    private function getClientMessageAfterLastScriptMessage($script, $messages)
    {
        if ($script->step == 1) {

            $select_message = '(?:' . $this->deleteControlCharactersAndSpaces(str_replace(':client_name', '', $this->config['scripts']['clarification']['message'])) . ')';

        } else {

            $select_message = '(?:';

            foreach ($this->config['scripts']['clarification']['steps'][$script->prev_step]['variants'] as $key => $variant) {

                if(empty($variant['messages'])) {
                    continue;
                }

                $variant_messages = [];

                foreach($variant['messages'] as $message) {
                    $variant_messages[] = $this->deleteControlCharactersAndSpaces(preg_quote($message));
                }

                $select_message .= implode('|', $variant_messages);

                if ($key != (count($this->config['scripts']['clarification']['steps'][$script->prev_step]['variants']) - 1)) {
                    $select_message .= '|';
                }
            }

            $select_message .= ')';
        }

        $script_message_id = $this->online_consultant->findMessageFromOperator($select_message, $messages);

        $client_messages = '';

        if (!empty($script_message_id)) {
            $client_messages = $this->online_consultant->getClientMessagesIfNoOperatorMessages($messages, ($script_message_id + 1));

            /**
             * Удаление скрипта в случае если диалог подхватил реальный оператор.
             */
            if($client_messages === false) {
                $script->delete();

                return false;
            }
        }

        return $client_messages;
    }


    /**
     * Получение финального сообщения для сценария и деактивация сценария.
     *
     * @param \SrcLab\SupportBot\Models\SupportScriptModel $script
     * @return string
     */
    private function getFinalMessageAndDeactivateScript($script)
    {
        /**
         * Установка несуществующего шага для завершения скрипта.
         */
        $script->step = -1;
        $script->save();

        return $this->config['scripts']['clarification']['steps'][$this->config['scripts']['clarification']['final_step']]['messages'];
    }

    /**
     * Вставка имени клиента в строку.
     *
     * @param string $string
     * @param string $client_name
     * @return string
     */
    public function insertClientNameInString($string, $client_name)
    {
        return str_replace(':client_name', $client_name, $string);
    }

    /**
     * Замена множественных пробелов на перенос строки.
     *
     * @param string $string
     * @return string
     */
    private function replaceMultipleSpacesWithLineBreaks($string)
    {
        return preg_replace("/\s\s+/", "\n", $string);
    }

    /**
     * Удаление управляющих символов и пробелов из строки.
     *
     * @param string $string
     * @return string
     */
    private function deleteControlCharactersAndSpaces($string)
    {
        return preg_replace('/[\x00-\x1F\x7F\s]/', '', $string);
    }

    /**
     * Планирование отложенных сценариев.
     *
     * @param int $search_id
     */
    private function planningPendingScripts($search_id)
    {
        $this->scripts_repository->addRecord($search_id, now()->addMinutes(1));
    }

    /**
     * Проверка времени на содержание в текущем интвервале.
     *
     * @param string $time
     * @param string $time_begin
     * @param string $time_end
     * @return bool
     */
    private function checkTime($time, $time_begin, $time_end)
    {
        return $time >= $time_begin && $time <= $time_end;
    }
}