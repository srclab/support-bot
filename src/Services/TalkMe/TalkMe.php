<?php

namespace SrcLab\SupportBot\Services\TalkMe;

use Carbon\Carbon;
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
        if(empty($config) || empty($config['api_token']) || empty($config['default_operator'])) {
            throw new \Exception('Не установлены конфигурационные данные для обращения к TalkMe');
        }

        $this->config = $config;
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
        $data = array_except($filter, 'period');

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

}