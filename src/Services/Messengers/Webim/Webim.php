<?php

namespace SrcLab\SupportBot\Services\Messengers\Webim;

use Carbon\Carbon;
use SrcLab\SupportBot\Contracts\OnlineConsultant;
use SrcLab\SupportBot\Filters;
use SrcLab\SupportBot\Services\Messengers\Messenger;
use Illuminate\Support\Facades\Log;
use Exception;

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
        if (empty($config['webim']) || empty($config['webim']['api_token']) || empty($config['webim']['login']) || empty($config['webim']['password'])) {
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
        if(empty($data['event']) || !in_array($data['event'], ['new_message', 'new_chat'])) {
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
        if (empty($this->getParamFromDataWebhook('message_text', $data))) {
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
        return true;
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
            'chat_id' => $client_id,
            'message' => [
                'kind' => 'OPERATOR',
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
     * @return mixed
     */
    public function getParamFromDataWebhook($param, array $data)
    {
        switch($param) {
            case 'client_id':
                if($data['event'] == 'new_chat') {
                    return $data['chat']['id'] ?? null;
                } else {
                    return $data['chat_id'] ?? null;
                }
            case 'search_id':
                if($data['event'] == 'new_chat') {
                    return $data['chat']['id'] ?? null;
                } else {
                    return $data['chat_id'] ?? null;
                }
            case 'message_text':
                if($data['event'] == 'new_chat') {
                    $message = array_pop($data['messages']);
                    if(empty($message)) {
                        return null;
                    }

                    return $message['text'] ?? null;
                } else {
                    return $data['message']['text'] ?? null;
                }
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
                return $dialog['visitor']['fields']['name'] ?? null;
                break;
            case 'messages':
                return $dialog['messages'];
                break;
            default:
                throw new Exception('Неизвестная переменная для получения из данных диалога.');
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

        if(strpos($api_method, 'chat') !== false) {
            curl_setopt_array($ch, [
                CURLOPT_URL => "https://allputru001.webim.ru/api/v2/{$api_method}",
                CURLOPT_RETURNTRANSFER => 1,
                CURLOPT_USERPWD => $this->config['login'].':'.$this->config['password'],
                CURLOPT_HTTPHEADER => [
                    "Content-Type: application/json",
                ],
            ]);
        } else {
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
        }

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

        return $response ?? true;
    }

    /**
     *
     *
     * @param int $client_id
     * @param array $period
     * @return array|mixed
     */
    public function getDialogFromClient($client_id, array $period = [])
    {
        /**
         * Фомирование временных рамок в нужном формате.
         *
         * @var \Carbon\Carbon $date_start
         * @var \Carbon\Carbon $date_end
         */
        $date_start = $filter['period'][0] ?? Carbon::now()->startOfDay();
        $date_end = $filter['period'][1] ?? Carbon::now();

        $dialog = $this->sendRequest("chat?id={$client_id}", []);

        $messages = [];
        $all_messages = $dialog['chat']['messages'];

        foreach($all_messages as $key=>$message) {
            if(Carbon::parse($message['created_at']) > $date_end) {
                break;
            }

            if($date_start <= Carbon::parse($message['created_at']) && in_array(['VISITOR', 'OPERATOR'], $message['kind'])) {
                $messages[] = $message;
            }
        }

        $dialog['chat']['messages'] = $messages;

        return $messages['chat'];
    }

    /**
     * Поиск сообщения от оператора.
     *
     * @param string $select_message
     * @param array $messages
     * @return int|null
     */
    public function findMessageFromOperator($select_message, array $messages)
    {
        foreach ($messages as $key => $message) {
            if ($message['kind'] == 'OPERATOR') {

                if (preg_match('/' . $select_message . '/iu', $this->deleteControlCharactersAndSpaces($message['message']))) {
                    $script_message_id = $key;
                    break;
                }
            }
        }

        return $script_message_id ?? null;
    }

    /**
     * Получение сообщений клиента если нет сообщений оператора.
     *
     * @param array $messages
     * @param int $offset
     * @return false|string
     */
    public function getClientMessagesIfNoOperatorMessages(array $messages, $offset = 0)
    {
        $client_messages = '';

        for ($i = ($offset + 1); $i < count($messages); $i++) {

            if ($messages[$i]['kind'] == 'VISITOR') {

                $client_messages .= $messages[$i]['message'];
            } else {

                return false;
                break;

            }
        }

        return $client_messages;
    }

    /**
     * Проверка отправил ли клиент сообщение после сообщения оператора.
     *
     * @param string $message_text
     * @param array $messages
     * @return bool
     */
    public function isClientSentMessageAfterOperatorMessage($message_text, array $messages)
    {
        $is_client_sent_message = false;

        foreach ($messages as $key => $message) {
            if ($message['kind'] == 'OPERATOR') {

                if ($this->deleteControlCharactersAndSpaces($message['text']) == $this->deleteControlCharactersAndSpaces($message_text)) {
                    $script_message_id = $key;
                    break;
                }
            }
        }

        if (!empty($script_message_id)) {
            for ($i = ($script_message_id + 1); $i < count($messages); $i++) {

                if ($messages[$i]['kind'] == 'VISITOR') {
                    $is_client_sent_message = true;
                }

            }
        }

        return $is_client_sent_message;
    }

    /**
     * Поиск сообщений оператора.
     *
     * @param array $messages
     * @return array
     */
    public function findOperatorMessages(array $messages)
    {
        $operator_messages = '';

        foreach ($messages as $message) {
            if ($message['kind'] == 'OPERATOR') {
                $operator_messages .= $message['message'];
            }
        }

        return $operator_messages;
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