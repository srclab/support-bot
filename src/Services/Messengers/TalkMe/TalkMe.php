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
        list($answer_index, $answer) = $this->getAnswer($data);

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
     * Получение списка сообщений.
     *
     * @param array $filter
     * @return array
     */
    public function getMessages(array $filter)
    {
        /**
         * Фомирование временных рамок в нужном формате.
         * Если указанный период больше 14 дней, разбивка на подзапросы.
         * @var \Carbon\Carbon $date_start
         * @var \Carbon\Carbon $date_end
         */
        $date_start = $filter['period'][0] ?? Carbon::now()->startOfDay();
        $date_end = $filter['period'][1] ?? Carbon::now();

        /**
         * Основные параметры запроса, если они были переданы.
         */
        $data = Arr::except($filter, 'period');

        if($date_end->diffInDays($date_start) <= 14) {

            $data['dateRange'] = [
                'start' => $date_start->toDateString(),
                'stop' => $date_end->toDateString(),
            ];

            $messages = $this->sendRequest('message', $data);

            return $messages === false ? [] : $messages;

        } else {

            $messages = [];

            do {

                $data['dateRange'] = [
                    'start' => $date_start->toDateString(),
                    'stop' => $date_end->toDateString(),
                ];

                $result = $this->sendRequest('message', $data);

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
     * Проверка секретки.
     *
     * @param string $request_secret
     * @return bool
     */
    public function checkSecret($request_secret)
    {
        return empty($this->config['webhook_secret']) || $this->config['webhook_secret'] == $request_secret;
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

        return true;
    }

    /**
     * Проверка наличия оператора.
     *
     * @param array $data
     * @return bool
     */
    public function checkOperator(array $data)
    {
        /**
         * Проверка наличия оператора.
         */
        if(empty($data['operator']['login'])) {
            Log::error('[SrcLab\SupportBot] Не найден оператор.', $data);
            return false;
        }

        return true;
    }

    public function getMessagesFromClient($client_id, array $period = [])
    {
        // TODO: Implement getMessagesFromClient() method.
    }

    /**
     * Получение параметра из данных вебхука.
     *
     * @param string $param
     * @param array $data
     * @return int|null
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
            default:
                return null;
        }
    }

    public function getOperatorMessages($client_id, array $period)
    {
        $filter = [
            'period' => [$period['from'], $period['to']],
            'client' => [
                'searchId' => $client_id,
            ]
        ];

        $today_messages = $this->getMessages($filter);

        $messages = [];

        foreach ($today_messages as $case) {
            foreach ($case['messages'] as $message) {
                if($message['whoSend'] != 'client') {
                    $messages[] = $message['text'];
                }
            }
        }

        return $message;
    }

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
}