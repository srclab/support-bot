<?php

namespace SrcLab\SupportBot\Services\Messengers\Webim;

use SrcLab\SupportBot\Contracts\OnlineConsultant;
use SrcLab\SupportBot\Filters;
use SrcLab\SupportBot\Services\Messengers\Messenger;

class Webim implements OnlineConsultant
{
    /**
     * Конфигурационные данные.
     *
     * @var array
     */
    protected $config;

    /**
     * Webim constructor.
     *
     * @param array $config
     * @throws \Exception
     */
    public function __construct(array $config)
    {
        if (empty($config['webim']) || empty($config['webim']['api_token'])) {
            throw new \Exception('Не установлены конфигурационные данные для обращения к Webim');
        }

        $this->config = $config['webim'];
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
         * Проверка что вебхук сообщает о новом сообщении.
         */
        if(empty($data['event']) || $data['event'] != 'new_message') {
            return false;
        }

        /**
         * Проверка секретки.
         */
        if (! $this->checkSecret($data['secretKey'] ?? null)) {
            Log::warning('[SrcLab\SupportBot] Получен неверный секретный ключ.', $data);

            return false;
        }

        /**
         * Проверка наличия сообщения.
         */
        if (empty($data['message'])) {
            Log::error('[SrcLab\SupportBot] Сообщение не получено.', $data);

            return false;
        }

        //$only_user_ids = $this->config['enabled_for_user_ids'] ?? [];

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
        return true;
    }

    //*****

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
            'chat_id' => $client_id,
            'message' => [
                'kind' => $operator ?? $this->config['default_operator'],
                'message' => $message,
            ],
        ];

        return (bool) $this->sendRequest('send_message', $data);
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
                return $data['chat_id'] ?? null;
            case 'search_id':
                return $data['chat_id'] ?? null;
            case 'message_text':
                return $data['message']['text'] ?? null;
            default:
                return null;
        }
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
            CURLOPT_URL => "https://allputru001.webim.ru/api/bot/v2/{$api_method}",
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_HTTPHEADER => [
                "Content-Type: application/json",
                "Authorization: Token {$this->config['api_token']}",
            ]
        ]);

        $response = curl_exec($ch);

        $http_code = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));

        curl_close($ch);

        if (!$response || $http_code != 200) {
            Log::error('[Webim] Ошибка выполнения запроса к Webim. Метод API: '.$api_method.', Данные: ( '.json_encode($data).' )', ['http_code' => $http_code, 'response' => $response]);
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

    public function getMessagesFromClient($client_id, array $period = [])
    {
        // TODO: Implement getMessagesFromClient() method.
    }

    public function getMessages(array $period)
    {
        // TODO: Implement getMessages() method.
    }

    public function getOperatorMessages($client_id, array $period)
    {
        // TODO: Implement getOperatorMessages() method.
    }

    public function checkEnabledUserIds(array $only_user_ids, array $data)
    {
        if (! empty($only_user_ids) && (empty($data['chat_id']) || ! in_array($data['chat_id'], $only_user_ids))) {
            return false;
        }

        return true;
    }
}