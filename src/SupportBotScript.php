<?php

namespace SrcLab\SupportBot;

use Carbon\Carbon;
use SrcLab\SupportBot\Contracts\OnlineConsultant;
use SrcLab\SupportBot\Repositories\SupportRedirectChatRepository;
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
     * @var \SrcLab\SupportBot\Repositories\SupportRedirectChatRepository
     */
    protected $redirect_chat_repository;

    /**
     * SupportBotScripts constructor.
     */
    public function __construct()
    {
        $this->config = array_merge(config('support_bot'), app_config('support_bot'));
        $this->online_consultant = app(OnlineConsultant::class, ['config' => $this->config['accounts']]);
        $this->scripts_repository = app(SupportScriptRepository::class);
        $this->scripts_exception_repository = app(SupportScriptExceptionRepository::class);
        $this->redirect_chat_repository = app(SupportRedirectChatRepository::class);
    }

    /**
     * Проверка фильтра пользователей по id на сайте.
     *
     * @param int $client_id
     * @return bool
     */
    public function checkEnabledUserIds($client_id)
    {
        $only_user_ids = $this->config['scripts']['enabled_for_user_ids'] ?? [];

        if(!empty($only_user_ids) && !in_array($client_id, $only_user_ids)) {
            return false;
        }

        return true;
    }

    /**
     * Обработка сценария для пользователя если скрипт существует.
     *
     * @param $search_id
     * @return string|bool
     */
    public function handleScriptForUserIfExists($search_id)
    {
        /** @var \SrcLab\SupportBot\Models\SupportScriptModel $script */
        $script = $this->scripts_repository->findBy(['search_id' => $search_id]);

        if(!is_null($script)) {
            if($script->step > 0) {
                if ($this->processScript($script)) {
                    return 'processing';
                }
            } else {
                return 'found';
            }
        }

        return false;
    }

    /**
     * Планирование отложенных сценариев.
     *
     * @param int $search_id
     */
    public function planningPendingScripts($search_id)
    {
        $this->scripts_repository->addRecord($search_id, now()->addMinutes(1));
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
         */
        $dialog = $this->online_consultant->getDialogFromClientByPeriod($script->search_id, [Carbon::now()->subDays(14), Carbon::now()->endOfDay()]);

        if(empty($dialog)) {
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
             * Получение сообщений клиента полученных после последнего сообщения сценария.
             */
            $client_messages = implode('', $this->getClientMessageAfterLastScriptMessage($script, $messages));

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

                            if(!empty($this->config['redirect_chats']['working_hours']['period_begin']) && !empty($this->config['redirect_chats']['working_hours']['period_end']) && !check_current_time($this->config['redirect_chats']['working_hours']['period_begin'], $this->config['redirect_chats']['working_hours']['period_end'])) {

                                $client_id = $this->online_consultant->getParamFromDialog('clientId', $dialog);

                                $this->online_consultant->sendMessage($client_id, $this->config['redirect_chats']['message_not_working_hours']);

                                $this->redirect_chat_repository->addRecord($client_id);

                            } else {
                                $operators_ids = $this->online_consultant->getListOnlineOperatorsIds();

                                if(empty($operators_ids)) {

                                    $client_id = $this->online_consultant->getParamFromDialog('clientId', $dialog);

                                    $this->online_consultant->sendMessage($this->online_consultant->getParamFromDialog('clientId', $dialog), $this->config['redirect_chats']['message_not_operators']);

                                    $this->redirect_chat_repository->addRecord($client_id);
                                } else {
                                    $this->online_consultant->redirectClientToChat($script->search_id, $operators_ids[array_rand($operators_ids)]);
                                }
                            }

                            return true;

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

            /**
             * Установка флага пользователь ответил.
             */
            if(!$script->user_answered) {
                $script->user_answered = true;
                $script->save();
            }

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

            /**
             * Закрытие диалога если сообщение финальное для Webim.
             */
            if($script->step == -1 && $this->config['online_consultant'] == 'webim') {
                $this->online_consultant->closeChat($script->search_id);
            }

            return true;

        }

        return false;
    }

    /**
     * Запуск сценария для пользователей.
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
            $this->startScript($script);
        }
    }

    //****************************************************************
    //*************************** Support ****************************
    //****************************************************************

    /**
     * Запуск сценария для пользователя.
     *
     * @param \SrcLab\SupportBot\Models\SupportScriptModel $script
     */
    private function startScript($script)
    {
        $dialog = $this->online_consultant->getDialogFromClientByPeriod($script->search_id, [Carbon::now()->subDays(14), Carbon::now()->endOfDay()]);

        if(empty($dialog)) {
            $script->delete();
            return;
        }

        /**
         * Проверка находится ли диалог на боте для Webim.
         */
        if($this->config['online_consultant'] == 'webim' && ($dialog['operator_id'] != $this->config['accounts']['webim']['bot_operator_id'] && !$this->online_consultant->isClientRedirectedToBot($dialog))) {
            $script->delete();
            return;
        }

        /**
         * TODO: раскоментировать после проверки.
         */
        /**
         * Проверка времени последнего сообщения клиента.
         */
        /*$datetime_message_client = $this->online_consultant->getDateTimeClientLastMessage($dialog);*/

        /** @var \Carbon\Carbon $datetime_message_client */
        /*if(!empty($datetime_message_client) && $datetime_message_client->diffInHours(Carbon::now()) < 3) {
            $script->send_message_at = $datetime_message_client->addHour(3);
            $script->save();
            return;
        }*/

        $messages = $this->online_consultant->getParamFromDialog('messages', $dialog);
        $client_name = $this->online_consultant->getParamFromDialog('name', $dialog);

        if ($this->checkDialogScriptLaunchConditions($messages)) {
            $result = $this->insertClientNameInString($this->config['scripts']['clarification']['message'], $client_name);
            $buttons = array_column($this->config['scripts']['clarification']['steps'][1]['variants'], 'button');
            $client_id = $this->online_consultant->getParamFromDialog('clientId', $dialog);

            $this->online_consultant->sendMessage($client_id, $this->replaceMultipleSpacesWithLineBreaks($result));
            $this->online_consultant->sendButtonsMessage($client_id, $buttons);

            $script->step++;
            $script->prev_step = 0;
            $script->start_script_at = Carbon::now();
            $script->save();

        } elseif($this->config['online_consultant'] == 'webim') {

            $this->online_consultant->closeChat($script->search_id);
            $script->delete();
        } else {
            
            $script->delete();
        }
    }

    /**
     * Проверка диалога на условия запуска скрипта.
     *
     * @param array $messages
     * @return bool
     */
    protected function checkDialogScriptLaunchConditions(array $messages)
    {
        if(empty($messages)) {
            return false;
        }

        $operator_messages = implode('', $this->online_consultant->findOperatorMessages($messages));
        $client_messages = implode('', $this->online_consultant->findClientMessages($messages));

        /**
         * Получение исключений.
         */
        $exceptions = $this->scripts_exception_repository->getAllException();

        if (!preg_match( '/' . $this->config['scripts']['select_message'] . '/iu', $operator_messages)) {
            return false;
        }

        foreach ($exceptions as $exception) {

            if (preg_match('/(?:' . addcslashes($exception->exception, "/") . ')/iu', $client_messages)) {
                return false;
                break;
            }
        }

        return true;
    }

    /**
     * Получить сообщения клиента после последнего отправленного сообщения сценария.
     *
     * @param \SrcLab\SupportBot\Models\SupportScriptModel $script
     * @param array $messages
     * @return bool|array
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

        $script_message_id = $this->online_consultant->findMessageKey($select_message, $this->online_consultant->findOperatorMessages($messages));

        $client_messages = [];

        /**
         * TODO: проверить работу функции.
         */

        if (!empty($script_message_id)) {

            $messages = array_slice($messages, ($script_message_id + ($this->config['online_consultant'] == 'webim' ? 2 : 1)));

            /**
             * Удаление скрипта в случае если диалог подхватил реальный оператор.
             */
            if(!empty($this->online_consultant->findOperatorMessages($messages))) {
                $script->delete();

                return false;
            } else {
                $client_messages = $this->online_consultant->findClientMessages($messages);
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