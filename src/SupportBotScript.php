<?php

namespace SrcLab\SupportBot;

use Carbon\Carbon;
use SrcLab\OnlineConsultant\Contracts\OnlineConsultant;
use SrcLab\SupportBot\Repositories\SupportRedirectChatRepository;
use SrcLab\SupportBot\Repositories\SupportScriptAnswerRepository;
use SrcLab\SupportBot\Repositories\SupportScriptExceptionRepository;
use SrcLab\SupportBot\Repositories\SupportScriptRepository;
use SrcLab\SupportBot\Support\Traits\SupportBotStatistic;
use Illuminate\Support\Facades\Log;

// todo: если добавятся проекты в вебим - при редиректе на оператора проверять его отдел
class SupportBotScript
{
    use SupportBotStatistic;

    /**
     * @var \Illuminate\Config\Repository|mixed
     */
    protected $config;

    /**
     * @var \SrcLab\OnlineConsultant\Contracts\OnlineConsultant
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
     * @var \SrcLab\SupportBot\Repositories\SupportScriptAnswerRepository
     */
    protected $scripts_answer_repository;

    /**
     * @var \Illuminate\Contracts\Cache\Repository $cache
     */
    protected $cache;

    /**
     * SupportBotScripts constructor.
     */
    public function __construct()
    {
        $this->config = array_merge(config('support_bot'), app_config('support_bot'));
        $this->online_consultant = app(OnlineConsultant::class);
        $this->scripts_repository = app(SupportScriptRepository::class);
        $this->scripts_exception_repository = app(SupportScriptExceptionRepository::class);
        $this->redirect_chat_repository = app(SupportRedirectChatRepository::class);
        $this->scripts_answer_repository = app(SupportScriptAnswerRepository::class);
        $this->cache = app('cache');
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
     * @param int $search_id
     * @param array $data
     * @return string|bool
     */
    public function handleScriptForUserIfExists($search_id, $data)
    {
        /** @var \SrcLab\SupportBot\Models\SupportScriptModel $script */
        $script = $this->scripts_repository->findBy(['search_id' => $search_id]);

        if(!is_null($script)) {
            if($script->step > 0) {
                if ($this->processScript($script, $data)) {
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
        $this->scripts_repository->addRecord($search_id, now()->addHour($this->config['scripts']['delay_before_running']));
    }

    /**
     * Обработка сценария для пользователя.
     *
     * @param \SrcLab\SupportBot\Models\SupportScriptModel $script
     * @param array $data
     * @return bool
     */
    public function processScript($script, array $data)
    {
        if(empty($this->config['scripts']['clarification']['steps'][$script->step]) || $script->send_message_at > now()) {
            return false;
        }

        $last_message = $this->online_consultant->getParamFromDataWebhook('message_text', $data);
        $client_id = $this->online_consultant->getParamFromDataWebhook('client_id', $data);

        if($script->step == $this->config['scripts']['clarification']['final_step']) {

            /**
             * Запись комментария к преидущему ответу пользователя для статистики.
             */
            if(!empty($last_message)) {

                /**
                 * Поиск записи об ответе пользователя на предфинальный вопрос.
                 */
                $user_answer = $this->scripts_answer_repository->getLastUserAnswer($client_id);

                if (!empty($user_answer)) {
                    $user_answer->comment = $last_message;
                    $user_answer->save();
                }
            }

            /**
             * Отправка пользователю финального сообщения сценария и деактивация сценария для пользователя.
             */
            $result = $this->getFinalMessageAndDeactivateScript($script);

        } else {

            if (!empty($last_message)) {

                /**
                 * Поиск выбранного варианта ответа.
                 */
                foreach ($this->config['scripts']['clarification']['steps'][$script->step]['variants'] as $variant) {
                    if ($variant['button'] == $last_message) {
                        /**
                         * Запись ответа пользователя для статистики.
                         */
                        if(!$this->scripts_answer_repository->isUserAnswered($client_id, $variant)) {
                            $user_answer = $this->scripts_answer_repository->new();

                            $user_answer->client_id = $client_id;
                            $user_answer->answer_option_id = $variant['id'];
                            $user_answer->created_at = Carbon::now();

                            $user_answer->save();
                        }

                        if(!empty($variant['messages'])) {
                            $result = $variant['messages'];
                        }

                        /**
                         * Перевод пользователя на оператора.
                         */
                        if(!empty($variant['for_operator'])) {
                            /**
                             * Установка несуществующего шага для завершения скрипта.
                             */
                            $script->step = -1;
                            $script->save();

                            /**
                             * Отложенный перевод пользователя на оператора.
                             */
                            if(!empty($this->config['redirect_chats']['not_working_hours']['period_begin']) && !empty($this->config['redirect_chats']['not_working_hours']['period_end']) && check_current_time($this->config['redirect_chats']['not_working_hours']['period_begin'], $this->config['redirect_chats']['not_working_hours']['period_end'])) {

                                $this->sendMessageAndIncrementStatistic($client_id, $this->config['redirect_chats']['message_not_working_hours']);

                                $this->redirect_chat_repository->addRecord($client_id);

                            } else {

                                /**
                                 * Перенаправление пользователя.
                                 */
                                $this->redirectUser($client_id);
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
                        } else {
                            /**
                             * Установка следущего шага финальным.
                             */
                            $script->prev_step = $script->step;
                            $script->step = $this->config['scripts']['clarification']['final_step'];
                        }

                        $script->save();
                    }
                }

                /**
                 * Редирект на оператора в случае если сообщения пользователя не совпало ни с одним из варианта текущего шага.
                 */
                if (empty($result)) {

                    /**
                     * Перенаправление пользователя.
                     */
                    $this->redirectUser($client_id, false);

                    /**
                     * Удаление скрипта.
                     */
                    $script->delete();

                    return false;
                }
            } elseif($this->online_consultant->getOnlineConsultantName() == 'webim' && !empty($messages)) {
                $message = array_pop($messages);

                /**
                 * Редирект на оператора в случае если сообщения пользователя было файлом.
                 */
                if($message['kind'] == 'file_visitor') {
                    /**
                     * Перенаправление пользователя.
                     */
                    $this->redirectUser($client_id, false);

                    /**
                     * Удаление скрипта.
                     */
                    $script->delete();

                    return false;
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

            /**
             * Отправка сообщений пользователю.
             */
            foreach($result as $message) {
                $this->sendMessageAndIncrementStatistic($client_id, $this->replaceMultipleSpacesWithLineBreaks($message));
            }

            /**
             * Отправка кнопок пользователю.
             */
            if(!empty($buttons)) {
                $this->sendButtonMessageAndIncrementStatistic($client_id, $buttons);
            }

            /**
             * Закрытие диалога.
             */
            if($script->step == -1 && $this->online_consultant->isCloseChatFunction()) {
                $this->online_consultant->closeChat($script->search_id);
            }

            return true;

        }

        return false;
    }

    /**
     * Получение ответа клиента на вопрос на шаге.
     *
     * @param \SrcLab\SupportBot\Models\SupportScriptModel $script
     * @param int $step
     * @param array $client_messages
     * @return null|array
     */
    public function getAnswerUserOnStep($script, $step, $client_messages)
    {
        $client_messages = implode('', $client_messages);

        /**
         * Поиск ответа пользователя на вопрос указанного шага.
         */
        foreach ($this->config['scripts']['clarification']['steps'][$step]['variants'] as $variant) {
            if (preg_match('/' . preg_quote($variant['button']) . '/iu', $client_messages)) {
                return $variant;
            }
        }

        return null;
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

        /**
         * Получение пачки скриптов на запуск.
         *
         * @var \Illuminate\Database\Eloquent\Collection $scripts
         */
        $scripts = $this->scripts_repository->getNextScripts();

        if($scripts->isEmpty()) return;

        /** @var \SrcLab\SupportBot\Models\SupportScriptModel $script */
        foreach($scripts as $script) {
            $this->startScript($script);
        }
    }

    /**
     * Получение сообщений клиента после сообщения сценария.
     *
     * @param \SrcLab\SupportBot\Models\SupportScriptModel $script
     * @param int $step
     * @param array $messages
     * @return bool|array
     */
    public function getScriptMessagesIndex($script, $step, $messages)
    {
        if($step > 0) {
            foreach ($this->config['scripts']['clarification']['steps'][$step]['variants'] as $key => $variant) {

                if(empty($variant['messages'])) {
                    continue;
                }

                foreach($variant['messages'] as $message) {
                    $select_message[] = $this->deleteControlCharactersAndSpaces(preg_quote($message, '/'));
                }
            }

            $select_message = implode('|', $select_message);
        } else {
            $select_message = $this->deleteControlCharactersAndSpaces(str_replace(':client_name', '', $this->config['scripts']['clarification']['message']));
        }

        /**
         * Поиск индекса сообщения от сценария в массиве.
         */
        $script_message_index = $this->online_consultant->findMessageKey("(?:{$select_message})", $this->online_consultant->findOperatorMessages($messages));

        return $script_message_index ?? false;
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
         * Проверка не закрыт ли диалог.
         */
        if($this->online_consultant->isChatClosed($dialog)) {
            $script->delete();
            return;
        }

        /**
         * Проверка находится ли диалог на боте для Webim.
         */
        if($this->online_consultant->getOnlineConsultantName() == 'webim'
            && (!$this->online_consultant->isDialogOnTheBot($dialog)
                && !$this->online_consultant->isClientRedirectedToBot($dialog))
        ) {
            $script->delete();
            return;
        }

        /**
         * Проверка времени последнего сообщения клиента.
         */
        $datetime_message_client = $this->online_consultant->getDateTimeLastMessage($dialog);

        /** @var \Carbon\Carbon $datetime_message_client */
        if(!empty($datetime_message_client) && $datetime_message_client->diffInMinutes(Carbon::now()) < ($this->config['scripts']['delay_before_running'] * 60 - 1)) {
            $script->send_message_at = $datetime_message_client->addHours($this->config['scripts']['delay_before_running']);
            $script->save();
            return;
        }

        $messages = $this->online_consultant->getParamFromDialog('messages', $dialog);
        $client_name = $this->online_consultant->getParamFromDialog('name', $dialog);
        $client_id = $this->online_consultant->getParamFromDialog('client_id', $dialog);

        /**
         * Запуск скрипта.
         */
        if ($this->checkDialogScriptLaunchConditions($messages)) {
            $result = $this->insertClientNameInString($this->config['scripts']['clarification']['message'], $client_name);
            $buttons = array_column($this->config['scripts']['clarification']['steps'][1]['variants'], 'button');

            /**
             * Отправка сообщения.
             */
            $this->sendMessageAndIncrementStatistic($client_id, $this->replaceMultipleSpacesWithLineBreaks($result));

            /**
             * Отправка кнопок.
             */
            $this->sendButtonMessageAndIncrementStatistic($client_id, $buttons);

            $script->step++;
            $script->prev_step = 0;
            $script->start_script_at = Carbon::now();
            $script->save();
        } else {

            /**
             * Закрытие чата.
             */
            if($this->online_consultant->isCloseChatFunction() && !$this->redirect_chat_repository->isExistRecord($client_id)) {
                $this->online_consultant->closeChat($script->search_id);
            }

            $script->delete();
        }
    }

    /**
     * Проверка диалога на условия запуска скрипта.
     *
     * @param array $messages
     * @return bool
     */
    private function checkDialogScriptLaunchConditions(array $messages)
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

        /**
         * Проверка на сообщения от оператора соотвествующее условиям сценария.
         */
        if (!preg_match( '/' . $this->config['scripts']['select_message'] . '/iu', $operator_messages)) {
            return false;
        }

        /**
         * Проверка исключений при которых скрипт реализовывать не нужно.
         */
        foreach ($exceptions as $exception) {

            if (preg_match('/(?:' . addcslashes($exception->exception, "/") . ')/iu', $client_messages)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Перенаправление пользователя на доступного оператора, либо отложенное перенаправление.
     *
     * @param int $client_id
     * @param bool $notification
     */
    private function redirectUser($client_id, $notification = true)
    {
        /**
         * Получение диалога.
         */
        $dialog = $this->online_consultant->getDialogFromClientByPeriod($client_id);

        /**
         * Получение списка операторов онлайн.
         */
        $operators_ids = $this->getOnlineOperatorsListToRedirect([$this->online_consultant->getParamFromDialog('operator_id', $dialog)]);

        if(empty($operators_ids)) {

            $client_id = $this->online_consultant->getParamFromDialog('client_id', $dialog);

            /**
             * Отправка сообщения пользователю.
             */
            if(!empty($notification)) {
                $this->sendMessageAndIncrementStatistic($this->online_consultant->getParamFromDialog('client_id', $dialog), $this->config['redirect_chats']['message_not_operators']);
            }

            /**
             * Создания записи об отложенном редиректе пользователя на оператора.
             */
            $this->redirect_chat_repository->addRecord($client_id);

        } else {
            /**
             * Редирект пользователя на оператора.
             */
            $this->online_consultant->redirectDialogToOperator($dialog, $operators_ids[array_rand($operators_ids)]);
        }
    }

    /**
     * Получение списка операторов онлайн с указанными исключениями.
     *
     * @param array $exclude_ids
     * @return array
     */
    private function getOnlineOperatorsListToRedirect($exclude_ids = [])
    {
        $operators_ids = $this->online_consultant->getListOnlineOperatorsIds();

        /**
         * Исключение указанных операторов со списка.
         */
        if(!empty($exclude_ids)) {
            $operators_ids = array_diff($operators_ids, $exclude_ids);
        }

        /**
         * Исключение операторов указанных в конфига со списка.
         */
        if(!empty($this->config['redirect_chats']['except_operators_ids'])) {
            $operators_ids = array_diff($operators_ids, $this->config['redirect_chats']['except_operators_ids']);
        }

        return $operators_ids;
    }

    /**
     * Получение финального сообщения для сценария и деактивация сценария.
     *
     * @param \SrcLab\SupportBot\Models\SupportScriptModel $script
     * @return array
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
    private function insertClientNameInString($string, $client_name)
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
