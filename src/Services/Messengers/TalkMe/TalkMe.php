<?php

namespace SrcLab\SupportBot\Services\Messengers\TalkMe;

use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use SrcLab\SupportBot\Contracts\OnlineConsultant;

class TalkMe implements OnlineConsultant
{
    /**
     * Конфигурационные данные.
     *
     * @var array
     */
    protected $config;

    /**
     * TalkMe constructor.
     *
     * @param array $config
     * @throws \Exception
     */
    public function __construct(array $config)
    {
        if(empty($config['talkme']) || empty($config['talkme']['api_token']) || empty($config['talkme']['default_operator'])) {
            throw new \Exception('Не установлены конфигурационные данные для обращения к TalkMe');
        }

        $this->config = $config['talkme'];
    }

    /**
     * Обработка новых обращений по вебхуку.
     *
     * @param array $data
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function processWebhook(array $data)
    {
        if(!empty($this->config['scripts']['enabled']) && !empty($data['client']['searchId'])) {
            /**
             * Планирование отложенного сценария для удержания пользователя.
             */
            if($this->support_bot_scripts->planingOrProcessScriptForUser($data['client']['searchId'])) return;
        }

        /**
         * Автоответчик.
         */
        if($this->autoResponder($data)) {
            return;
        }

        /**
         * Бот отключен.
         */
        if(!$this->config['enabled']) {
            return;
        }

        /**
         * Проверка полученных данных, определение возможности сформировать ответ.
         */
        if(!$this->checkWebhookData($data)) {
            return;
        }

        /**
         * Проверка периода активности бота.
         */
        if(!$this->checkActivePeriod()) {
            return;
        }

        /**
         * Удаление отложенных сообщений, если пользователь написал что-либо после приветствия.
         */
        if($this->config['deferred_answer_after_welcome'] ?? false) {
            $this->messages_repository->deleteDeferredMessagesByClient($data['client']['clientId']);
        }

        /**
         * Формирование автоответа.
         */
        [$answer_index, $answer] = $this->getAnswer($data);

        if(empty($answer)) {
            return;
        }

        /**
         * Отправка сообщения.
         */
        $this->sendMessage($data['client']['clientId'], $answer, $data['operator']['login']);

        /**
         * Если ответ это простое приветствие, добавление отложенного сообщения "Чем я могу вам помочь?"
         */
        if(($this->config['deferred_answer_after_welcome'] ?? false) && preg_match('/^(?:Здравствуйте|Привет|Добрый вечер|Добрый день)[.!)\s]?$/iu', $data['message']['text'])) {
            $this->messages_repository->addRecord($data['client']['clientId'], $data['operator']['login'], 'Чем я могу вам помочь?', now()->addMinutes(2));
        }

        /**
         * Увеличение счетчика отправленных сообщений для статистики.
         */
        $this->sentMessagesAnalyse();

        /**
         * Запись информации о том, что ответ уже отправлялся сегодня.
         */
        $this->writeJustSentAnswerToday($answer_index, $data['client']['clientId']);

    }

    /**
     * Получение списка сообщений за период.
     *
     * @param array $period
     * @return array
     */
    public function getDialogsByPeriod(array $period)
    {
        /**
         * Фомирование временных рамок в нужном формате.
         * Если указанный период больше 14 дней, разбивка на подзапросы.
         * @var \Carbon\Carbon $date_start
         * @var \Carbon\Carbon $date_end
         */
        $date_start = $period[0] ?? Carbon::now()->startOfDay();
        $date_end = $period[1] ?? Carbon::now();

        if($date_end->diffInDays($date_start) <= 14) {

            $data['dateRange'] = [
                'start' => $date_start->toDateString(),
                'stop' => $date_end->toDateString(),
            ];

            $messages = $this->sendRequest('message', []);

            return $messages === false ? [] : $messages;

        } else {

            $messages = [];

            do {

                $data['dateRange'] = [
                    'start' => $date_start->toDateString(),
                    'stop' => $date_end->toDateString(),
                ];

                $result = $this->sendRequest('message', []);

                if($result === false) break;

                $messages = array_merge($messages, $result);

            } while($date_start->addDays(14) < $date_end);

            return $messages;
        }

    }

    /**
     * Отправка сообщения клиенту.
     *
     * @param string $client_id
     * @param string $message
     * @param string $operator
     * @return bool
     */
    public function sendMessage($client_id, $message, $operator = null)
    {
        /**
         * Формирование данных для запроса.
         */
        $data = [
            'client' => [
                'id' => $client_id,
            ],
            'operator' => [
                'login' => $operator ?? $this->config['default_operator'],
            ],
            'message' => [
                'text' => $message,
            ],
        ];

        return (bool)$this->sendRequest('messageToClient', $data);
    }

    /**
     * Отправка сообщения с кнопками клиенту.
     *
     * @param string $client_id
     * @param array $button_names
     * @param string $operator
     * @return bool
     */
    public function sendButtonsMessage($client_id, $button_names, $operator = null)
    {
        /**
         * Формирование сообщения с вариантами ответа из за отсуствия кнопок.
         */
        $message = 'Пожалуйста выберите и напишите один из вариантов ответа:\n';

        foreach($button_names as $key=>$button_name) {
            $message .=  "{$key}.{$button_name}\n";
        }

        /**
         * Формирование данных для запроса.
         */
        $data = [
            'client' => [
                'id' => $client_id,
            ],
            'operator' => [
                'login' => $operator ?? $this->config['default_operator'],
            ],
            'message' => [
                'text' => $message,
            ],
        ];

        return (bool)$this->sendRequest('messageToClient', $data);
    }

    /**
     * Проверка полученных данных в вебхуке, определение возможности сформировать ответ.
     *
     * @param array $data
     * @return bool
     */
    public function checkWebhookData(array $data)
    {
        /**
         * Проверка секретки.
         */
        if(!$this->checkSecret($data['secretKey'] ?? null)) {
            Log::warning('[SrcLab\SupportBot] Получен неверный секретный ключ.', $data);
            return false;
        }

        /**
         * Проверка наличия сообщения.
         */
        if(empty($data['message'])) {
            Log::error('[SrcLab\SupportBot] Сообщение не получено.', $data);
            return false;
        }

        /**
         * Проверка наличия оператора.
         */
        if(empty($data['operator']['login'])) {
            Log::error('[SrcLab\SupportBot] Не найден оператор.', $data);
            return false;
        }

        return true;
    }

    /**
     * Получение диалога с клиентом.
     *
     * @param int $client_id
     * @param array $period
     * @return array
     */
    public function getDialogFromClientByPeriod($client_id, array $period = [])
    {
        /**
         * Фомирование временных рамок в нужном формате.
         *
         * @var \Carbon\Carbon $date_start
         * @var \Carbon\Carbon $date_end
         */
        $date_start = $period[0] ?? Carbon::now()->startOfDay();
        $date_end = $period[1] ?? Carbon::now();

        $result = $this->getDialogsByPeriod([
            'period' => [$date_start, $date_end],
            'client' => [
                'searchId' => $client_id
            ],
        ]);

        return array_shift($result);
    }

    /**
     * Получение параметра из данных вебхука.
     *
     * @param string $param
     * @param array $data
     * @return mixed
     */
    public function getParamFromDataWebhook($param, array $data)
    {
        switch($param) {
            case 'client_id':
                return $data['client']['clientId'] ?? null;
            case 'search_id':
                return $data['client']['searchId'] ?? null;
            case 'message_text':
                return $data['message']['text'] ?? null;
            case 'operator_login':
                return $data['operator']['login'] ?? null;
            case 'messages':
                return null;
            default:
                throw new Exception('Неизвестная переменная для получения из данных webhook.');
        }
    }

    /**
     * Получение параметров диалога.
     *
     * @param string $param
     * @param array $dialog
     * @return mixed
     */
    public function getParamFromDialog($param, array $dialog)
    {
        switch($param) {
            case 'name':
                return $dialog['name'] ?? null;
                break;
            case 'messages':
                return $dialog['messages'];
                break;
            case 'clientId':
                return $dialog['clientId'];
                break;
            default:
                throw new Exception('Неизвестная переменная для получения из данных диалога.');
        }
    }

    /**
     * Проверка фильтра пользователей по id на сайте.
     *
     * @param array $only_user_ids
     * @param array $data
     * @return bool
     */
    public function checkEnabledUserIds(array $only_user_ids, array $data)
    {
        if(!empty($only_user_ids)
            && (empty($data['client']['customData']['user_id'])
                || !in_array($data['client']['customData']['user_id'], $only_user_ids))
        ) {
            return false;
        }

        return true;
    }

    /**
     * Поиск ключа сообщения в массиве сообщений.
     *
     * @param string $select_message
     * @param array $messages
     * @return int|null
     */
    public function findMessageKey($select_message, array $messages)
    {
        /**
         * TODO: вернуть break; после проверки.
         */
        foreach ($messages as $key => $message) {
            if (preg_match('/' . $select_message . '/iu', $this->deleteControlCharactersAndSpaces($message['text']))) {
                $message_id = $key;
                //break;
            }
        }

        return $message_id ?? null;
    }

    /**
     * Поиск сообщений оператора.
     * TODO: проверить метод ( добавил messageType ).
     *
     * @param array $messages
     * @return array
     */
    public function findOperatorMessages(array $messages)
    {
        $operator_messages = [];

        foreach ($messages as $message) {
            if ($message['whoSend'] == 'operator' && (empty($messages[$i]['messageType']) || !empty($messages[$i]['messageType']) && ($messages[$i]['messageType'] != 'comment' && $messages[$i]['messageType'] != 'autoMessage'))) {
                $operator_messages[] = $message['message'];
            }
        }

        return $operator_messages;
    }

    /**
     * Поиск сообщений от клиента.
     *
     * @param array $messages
     * @return array
     */
    public function findClientMessages(array $messages)
    {
        $client_message = [];

        foreach ($messages as $message) {
            if ($message['whoSend'] == 'client') {
                $client_message[] = $message['message'];
            }
        }

        return $client_message;
    }

    /**
     * Получение даты и времени последнего сообщения клиента.
     *
     * @param array $dialog
     * @return \Carbon\Carbon|false
     */
    public function getDateTimeClientLastMessage($dialog)
    {
        $i = count($dialog['messages'])-1;
        $message = $dialog['messages'][$i];

        while($i >= 0 && $dialog['messages'][$i]['whoSend'] != 'client') {
            $message = $dialog['messages'][$i];
            $i--;
        }

        if($message['whoSend'] != 'client') {
            return false;
        }

        return Carbon::parse($message['dateTime']);
    }

    /**
     * Получение списка ид операторов онлайн.
     * TODO: реализовать метод.
     *
     * @return array
     */
    public function getListOnlineOperatorsIds()
    {
        return [];
    }

    /**
     * Перевод чата на оператора.
     *
     * @param int $client_id
     * @param int $operator_id
     * @return bool
     */
    public function redirectClientToChat($client_id, $operator_id)
    {
        return true;
    }

    //****************************************************************
    //*************************** Support ****************************
    //****************************************************************

    /**
     * Отправка запроса к api.
     *
     * @param string $api_method
     * @param array $data
     * @return bool|array
     */
    protected function sendRequest($api_method, array $data = [])
    {
        /**
         * Подготовка данных.
         */
        if(!empty($data)) {
            $data = json_encode($data);
        }

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => "https://lcab.talk-me.ru/api/chat/{$this->config['api_token']}/{$api_method}",
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json']
        ]);

        $response = curl_exec($ch);

        $http_code = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));

        curl_close($ch);

        if (!$response || $http_code != 200) {
            Log::error('[TalkMe] Ошибка выполнения запроса к TalkMe. Метод API: '.$api_method.', Данные: ( '.json_encode($data).' )', ['http_code' => $http_code, 'response' => $response]);
            return false;
        }

        /**
         * Парсинг данных.
         */
        $response = json_decode($response, true);

        if(empty($response['ok'])) {
            Log::error('[TalkMe] Ошибка выполнения запроса к TalkMe. Метод API: '.$api_method.', Данные: ( '.json_encode($data).' )', ['response' => $response]);
            return false;
        }

        return $response['data'] ?? true;
    }

    /**
     * Проверка секретки.
     *
     * @param string $request_secret
     * @return bool
     */
    protected function checkSecret($request_secret)
    {
        return empty($this->config['webhook_secret']) || $this->config['webhook_secret'] == $request_secret;
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
}