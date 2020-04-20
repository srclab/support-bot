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
     * @param $clientId
     */
    public function planingOrProcessScriptForUser($clientId)
    {
        $script = $this->scripts_repository->findBy(['client_id' => $clientId]);

        if(is_null($script)) {
            $this->planningPendingScripts($clientId);
        } else {
            if($script->step == 1) {
                $script->delete();
            } else {
                $this->processScript($script);
            }
        }
    }

    /**
     * Обработка сценария для пользователя.
     *
     * @param \SrcLab\SupportBot\Models\SupportScriptModel $script
     */
    public function processScript($script)
    {
        if(empty($this->config['scripts']['clarfication']['steps'][$script->step])) return;

        if($script->send_message_at > now()) return;

        $messages = $this->getClientDialog($script->client_id, Carbon::now()->subDay(1), Carbon::now()->endOfDay(), $dialog);

        if(!$messages) {
            return false;
        }

        if($script->step == $this->config['scripts']['clarfication']['final_step']) {

            $result = $this->getFinalMessageAndDeleteScript($script);

        } else {

            $client_messages = $this->getClientMessageAfterLastScriptMessage($script, $messages);

            if (strlen($client_messages) > 0) {

                if (!empty($this->config['scripts']['clarfication']['steps'][$script->step]['is_final'])) {

                    $result = $this->config['scripts']['clarfication']['steps'][$script->step]['message'];

                    /**
                     * Установка несуществующего шага для завершения скрипта.
                     */
                    $script->step = 10;
                    $script->save();

                } else {

                    foreach ($this->config['scripts']['clarfication']['steps'][$script->step]['variants'] as $variant) {
                        if (preg_match('/' . $variant['select_message'] . '/iu', $client_messages)) {

                            $result = $variant['message'];

                            if (empty($variant['next_step'])) {
                                $script->step = $this->config['scripts']['clarfication']['final_step'];
                            } else {
                                $script->step++;
                            }

                            $script->save();

                            break;

                        }
                    }

                    if (empty($result)) {
                        $result = $this->getFinalMessageAndDeleteScript($script);
                    }

                }

            }
        }

        if(!empty($result)) {

            $this->online_consultant->sendMessage($script->client_id, $this->replaceMultipleSpacesWithLineBreaks($result));

        }
    }

    /**
     * Запуск сценария для пользователя.
     */
    public function sendStartMessageForUser()
    {
        $script = $this->scripts_repository->getNextScriptForUser();

        if(is_null($script)) return;

        if($script->step == 0) {

            $result = $this->getResultForNotificationScript($script);

            if(!empty($result)) {
                $script->send_message_at = now()->addDay(14);
            }

        } else {

            $result = $this->getResultForClarificationScript($script);

        }

        if(!empty($result)) {

            $script->step++;

            $script->save();

            $this->online_consultant->sendMessage($script->client_id, $this->replaceMultipleSpacesWithLineBreaks($result));

        }
    }

    /**
     * Получение сообщения для сценария уточнения.
     *
     * @param \SrcLab\SupportBot\Models\SupportScriptModel $script
     * @return false|string
     */
    private function getResultForClarificationScript($script)
    {
        $messages = $this->getClientDialog($script->client_id, Carbon::now()->subDay(14), Carbon::now()->endOfDay(), $dialog);

        $client_name = $dialog['name'];

        if(empty($messages)) {
            $result = $this->insertClientNameInString($this->config['scripts']['clarfication']['message'], $client_name);
        } else {

            foreach ($messages as $key => $message) {
                if ($message['whoSend'] == 'operator') {

                    if ($this->deleteControlCharactersAndSpaces($message['text']) == $this->deleteControlCharactersAndSpaces($this->config['scripts']['notification']['message'])) {
                        $script_message_id = $key;
                        break;
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
                $result = $this->insertClientNameInString($this->config['scripts']['clarfication']['message'], $client_name);
            }
        }

        if(empty($result)) {

            $script->delete();

            return false;
        }

        return $result;
    }

    /**
     * Получение сообщения для сценария уведомления.
     *
     * @param \SrcLab\SupportBot\Models\SupportScriptModel $script
     * @return false|string
     */
    private function getResultForNotificationScript($script)
    {
        $messages = $this->getClientDialog($script->client_id, Carbon::now()->subDay(1), Carbon::now()->endOfDay(), $dialog);

        if(!$messages) {
            return false;
        }

        /**
         * Проверка на разницу последнего сообщения с текущим временем в 3 часа при отправке уведомления.
         */
        $now = Carbon::now();
        $last_message_datetime = Carbon::parse(end($messages)['dateTime']);

        if($now->diffInHours($last_message_datetime) < 3) {

            $script->send_message_at = $last_message_datetime->addHour(3);
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

                if (preg_match('/(?:' . $exception->exception . ')/iu', $client_messages)) {
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

        return $result;
    }

    /**
     * Получение диалога с клиентом.
     *
     * @param string $clientId
     * @param \Carbon\Carbon $start_date
     * @param \Carbon\Carbon $end_date
     * @param array $data
     * @return false|array
     */
    private function getClientDialog($clientId, $start_date, $end_date, &$data)
    {
        /**
         * Получение сообщений.
         */
        $filter = [
            'period' => [$start_date, $end_date],
            'client' => [
                'clientId' => $clientId,
            ],
        ];

        $data = $this->online_consultant->getMessages($filter);

        if(!count($data)) {
            return false;
        }

        $data = array_shift($data);

        return $data['messages'];
    }

    /**
     * Получить сообщения клиента после последнего отправленного сообщения сценария.
     *
     * @param \SrcLab\SupportBot\Models\SupportScriptModel $script
     * @param array $messages
     * @return string
     */
    private function getClientMessageAfterLastScriptMessage($script, $messages)
    {
        if ($script->step == 2) {

            $select_message = '(?:' . $this->deleteControlCharactersAndSpaces($this->config['scripts']['clarfication']['select_message']) . ')';

        } else {

            $prev_step = $script->step-1;

            $select_message = '(?:';

            foreach ($this->config['scripts']['clarfication']['steps'][$prev_step]['variants'] as $key => $variant) {

                $select_message .= $this->deleteControlCharactersAndSpaces(quotemeta($variant['message']));

                if ($key != array_key_last($this->config['scripts']['clarfication']['steps'][$prev_step]['variants'])) {
                    $select_message .= '|';
                }
            }

            $select_message .= ')';
        }

        foreach ($messages as $key => $message) {
            if ($message['whoSend'] == 'operator') {

                if (preg_match('/' . $select_message . '/iu', $this->deleteControlCharactersAndSpaces($message['text']))) {
                    $script_message_id = $key;
                    break;
                }
            }
        }

        $client_messages = '';

        if (!empty($script_message_id)) {
            for ($i = ($script_message_id + 1); $i < (array_key_last($messages) + 1); $i++) {

                if ($messages[$i]['whoSend'] == 'client') {

                    $client_messages .= $messages[$i]['text'];
                } else {

                    /**
                     * Удаление скрипта в случае если диалог подхватил реальный оператор.
                     */
                    $script->delete();

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
        $script->step = 10;

        $script->save();

        return $this->config['scripts']['clarfication']['steps'][$this->config['scripts']['clarfication']['final_step']]['message'];
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
     * @param int $clientId
     */
    private function planningPendingScripts($clientId)
    {
        $this->scripts_repository->addRecord($clientId, now()->addHour(3));
    }
}