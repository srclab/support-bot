<?php

namespace SrcLab\SupportBot;

use Carbon\Carbon;
use SrcLab\SupportBot\Contracts\OnlineConsultant;
use SrcLab\SupportBot\Repositories\SupportScriptExceptionRepository;
use SrcLab\SupportBot\Repositories\SupportScriptRepository;

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
        $this->online_consultant = app(OnlineConsultant::class, ['config' => $this->config['accounts']['talk_me']]);
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
            if($script->step == 1) {
                $script->delete();
            } else {
                if($this->processScript($script)) {
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
        if(empty($this->config['scripts']['clarification']['steps'][$script->step])) return;

        if($script->send_message_at > now()) return;

        /**
         * Получение сообщений с пользователем.
         */
        $dialog = $this->getClientDialog($script->search_id, Carbon::now()->subDays(2), Carbon::now()->endOfDay());

        if(!$dialog) {
            return false;
        }

        $dialog = array_shift($dialog);

        $messages = $dialog['messages'];

        if($script->step == $this->config['scripts']['clarification']['final_step']) {

            /**
             * Отправка пользователю финального сообщения сценария и деактивация сценария для пользователя.
             */
            $result = $this->getFinalMessageAndDeleteScript($script);

        } else {

            /**
             * Получение последнего сообщения сценария отправленного ботом.
             */
            $client_messages = $this->getClientMessageAfterLastScriptMessage($script, $messages);

            if (!empty($client_messages)) {

                /**
                 * Отправка сообщения и деактивация сценария для пользователя в случае если шаг является финальным.
                 */
                if (!empty($this->config['scripts']['clarification']['steps'][$script->step]['is_final'])) {

                    $result = $this->config['scripts']['clarification']['steps'][$script->step]['message'];

                    /**
                     * Установка несуществующего шага для завершения скрипта.
                     */
                    $script->delete();

                } else {

                    /**
                     * Проверка сообщения отправленного пользователем на соотвествие с одним из вариантов текущего шага.
                     */
                    foreach ($this->config['scripts']['clarification']['steps'][$script->step]['variants'] as $variant) {
                        if (preg_match('/' . $variant['select_message'] . '/iu', $client_messages)) {

                            $result = $variant['message'];

                            if (empty($variant['next_step'])) {
                                /**
                                 * Установка следущего шага финальным.
                                 */
                                $script->prev_step = $script->step;
                                $script->step = $this->config['scripts']['clarification']['final_step'];
                            } else {
                                /**
                                 * Установка следующего шага и сохранения предидущего.
                                 */
                                $script->prev_step = $script->step;
                                $script->step++;
                            }

                            $script->save();

                            break;

                        }
                    }

                    /**
                     * Отправка финального сообщения в случае если сообщения пользователя не совпало ни с одним из варианта текущего шага.
                     */
                    if (empty($result)) {
                        $result = $this->getFinalMessageAndDeleteScript($script);
                    }

                }

            }
        }

        if(!empty($result)) {

            /**
             * Отправка сообщения пользователю.
             */
            $this->online_consultant->sendMessage($dialog['clientId'], $this->replaceMultipleSpacesWithLineBreaks($result));

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
        if(!$this->checkTime(Carbon::now()->format('H:i'), $this->config['scripts']['send_notification_period']['period_begin'], $this->config['scripts']['send_notification_period']['period_begin'])) return;

        $scripts = $this->scripts_repository->getNextScripts();

        if($scripts->isEmpty()) return;

        /** @var \SrcLab\SupportBot\Models\SupportScriptModel $script */
        foreach($scripts as $script) {

            if ($script->step == 0) {

                $result = $this->getResultForNotificationScript($script);

                if (!empty($result)) {
                    $script->send_message_at = $this->getDateTimeCorrectionForWorkingHours(now()->addMinutes(1));
                }

            } else {

                $result = $this->getResultForClarificationScript($script);

            }

            if (!empty($result)) {

                $script->step++;

                $script->save();

                $this->online_consultant->sendMessage($result['clientId'], $this->replaceMultipleSpacesWithLineBreaks($result['result']));

            }
        }
    }

    /**
     * Получение сообщения и ID клиента для сценария уточнения.
     *
     * @param \SrcLab\SupportBot\Models\SupportScriptModel $script
     * @return false|array
     */
    private function getResultForClarificationScript($script)
    {
        $dialog = $this->getClientDialog($script->search_id, Carbon::now()->subDays(14), Carbon::now()->endOfDay());

        if(!$dialog) {
            return false;
        }

        $dialog = array_shift($dialog);

        $client_name = $dialog['name'];

        $messages = $dialog['messages'];

        if(empty($messages)) {
            $result = $this->insertClientNameInString($this->config['scripts']['clarification']['message'], $client_name);
        } else {

            foreach ($messages as $key => $message) {
                if ($message['whoSend'] == 'operator') {

                    if ($this->deleteControlCharactersAndSpaces($message['text']) == $this->deleteControlCharactersAndSpaces($this->config['scripts']['notification']['message'])) {
                        $script_message_id = $key;
                        //break;
                    }
                }
            }

            if (!empty($script_message_id)) {
                for ($i = ($script_message_id + 1); $i < (array_key_last($messages) + 1); $i++) {

                    if ($messages[$i]['whoSend'] == 'client') {
                        $is_client_sent_message = true;
                    }

                }
            }

            if (empty($is_client_sent_message)) {
                $result = $this->insertClientNameInString($this->config['scripts']['clarification']['message'], $client_name);
            }
        }

        if(empty($result)) {

            $script->delete();

            return false;
        }

        return ['clientId' => $dialog['clientId'], 'result' => $result];
    }

    /**
     * Получение сообщения и ID клиента для сценария уведомления.
     *
     * @param \SrcLab\SupportBot\Models\SupportScriptModel $script
     * @return false|array
     */
    private function getResultForNotificationScript($script)
    {
        $dialog = $this->getClientDialog($script->search_id, Carbon::now()->subDays(1), Carbon::now()->endOfDay());

        if(!$dialog) {
            return false;
        }

        $dialog = array_shift($dialog);

        $messages = $dialog['messages'];

        /**
         * Проверка на разницу последнего сообщения с текущим временем в 3 часа при отправке уведомления.
         */
        $now = Carbon::now();
        $last_message_datetime = Carbon::parse(end($messages)['dateTime']);

        if($now->diffInMinutes($last_message_datetime) < 1) {

            $script->send_message_at = $this->getDateTimeCorrectionForWorkingHours($last_message_datetime->addMinutes(1));
            $script->save();

            return false;
        }

        /**
         * Получение исключений.
         */
        $exceptions = $this->scripts_exception_repository->getAllException();

        $operator_messages = '';
        $client_messages = '';

        foreach ($messages as $message) {
            if ($message['whoSend'] == 'operator') {
                $operator_messages .= $message['text'];
            } else {
                $client_messages .= $message['text'];
            }
        }

        if (preg_match( '/' . $this->config['scripts']['select_message'] . '/iu', $operator_messages)) {

            foreach ($exceptions as $exception) {

                if (preg_match('/(?:' . addcslashes($exception->exception, "/") . ')/iu', $client_messages)) {
                    $stop_word = true;
                    break;
                }
            }

            if (empty($stop_word)) {
                $result = $this->config['scripts']['notification']['message'];
            }
        }

        if(empty($result)) {

            $script->delete();

            return false;
        }

        return ['clientId' => $dialog['clientId'], 'result' => $result];
    }

    /**
     * Получение диалога с клиентом.
     *
     * @param int $search_id
     * @param \Carbon\Carbon $start_date
     * @param \Carbon\Carbon $end_date
     * @return false|array
     */
    private function getClientDialog($search_id, $start_date, $end_date)
    {
        /**
         * Получение сообщений.
         */
        $filter = [
            'period' => [$start_date, $end_date],
            'client' => [
                'searchId' => $search_id,
            ],
        ];

        $dialog =  $this->online_consultant->getMessages($filter);

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
        if ($script->step == 2) {

            $select_message = '(?:' . $this->deleteControlCharactersAndSpaces($this->config['scripts']['clarification']['select_message']) . ')';

        } else {

            $select_message = '(?:';

            foreach ($this->config['scripts']['clarification']['steps'][$script->prev_step]['variants'] as $key => $variant) {

                $select_message .= $this->deleteControlCharactersAndSpaces(quotemeta($variant['message']));

                if ($key != array_key_last($this->config['scripts']['clarification']['steps'][$script->prev_step]['variants'])) {
                    $select_message .= '|';
                }
            }

            $select_message .= ')';
        }

        foreach ($messages as $key => $message) {
            if ($message['whoSend'] == 'operator') {

                if (preg_match('/' . $select_message . '/iu', $this->deleteControlCharactersAndSpaces($message['text']))) {
                    $script_message_id = $key;
                    //break;
                }
            }
        }

        $client_messages = '';

        if (!empty($script_message_id)) {
            for ($i = ($script_message_id + 1); $i < (array_key_last($messages) + 1); $i++) {

                if ($messages[$i]['whoSend'] == 'client') {

                    $client_messages .= $messages[$i]['text'];
                } elseif(empty($messages[$i]['messageType']) || !empty($messages[$i]['messageType']) && ($messages[$i]['messageType'] != 'comment' && $messages[$i]['messageType'] != 'autoMessage')) {

                    /**
                     * Удаление скрипта в случае если диалог подхватил реальный оператор.
                     */
                    $script->delete();

                    return false;
                    break;

                }

            }
        }

        return $client_messages;
    }


    /**
     * Получение финального сообщения для сценария и удаление сценария.
     *
     * @param \SrcLab\SupportBot\Models\SupportScriptModel $script
     * @return string
     */
    private function getFinalMessageAndDeleteScript($script)
    {
        /**
         * Установка несуществующего шага для завершения скрипта.
         */
        $script->delete();

        return $this->config['scripts']['clarification']['steps'][$this->config['scripts']['clarification']['final_step']]['message'];
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
     * Планирование отложенных сценариев.
     *
     * @param int $search_id
     */
    private function planningPendingScripts($search_id)
    {
        $this->scripts_repository->addRecord($search_id, $this->getDateTimeCorrectionForWorkingHours(now()->addMinutes(1)));
    }

    /**
     * Корректировка даты-времени под рабочие часы отправки уведомлений.
     *
     * @param \Carbon\Carbon $datetime
     */
    private function getDateTimeCorrectionForWorkingHours(Carbon $datetime)
    {
        $time = $datetime->format('H:i');

        $time_begin = $this->config['scripts']['send_notification_period']['period_begin'];
        $time_end = $this->config['scripts']['send_notification_period']['period_begin'];

        if(!$this->checkTime($time, $time_begin, $time_end)) {

            if($time > $time_end) {
                $datetime->addDays(1);
            }

            $time_begin = explode(':', $time_begin);

            $datetime->hour = $time_begin[0];
            $datetime->minute = $time_begin[1];
        }

        return $datetime;
    }

    /**
     * Проверка времени на содержание в текущем интвервале.
     *
     * @param \Carbon\Carbon $time
     * @param string $time_begin
     * @param string $time_end
     * @return bool
     */
    private function checkTime($time, $time_begin, $time_end)
    {
        return $time >= $time_begin && $time <= $time_end;
    }
}