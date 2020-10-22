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
                'kind' => 'operator',
                'text' => $message,
            ],
        ];

        return (bool) $this->sendRequest('send_message', $data);
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
         * Формирование кнопок.
         */
        $buttons = [];
        foreach($button_names as $button_name) {
            $buttons[] = [[
                    'text' => $button_name,
                    'id' => uniqid()
            ]];
        }

        /**
         * Формирование данных для запроса.
         */
        $data = [
            'chat_id' => $client_id,
            'message' => [
                'kind' => 'keyboard',
                'buttons' => $buttons,
            ],
        ];

        return (bool) $this->sendRequest('send_message', $data);
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
        /**
         * TODO: объединить client_id, search_id в одну структуру
         */
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
                } else {
                    $message = $data['message'];
                }

                if(empty($message)) {
                    return null;
                }

                if($message['kind'] == 'keyboard_response') {
                    $message_text = $message['data']['button']['text'];
                } else {
                    $message_text = $message['text'];
                }

                return empty($message_text) ? null : $message_text;
            case 'messages':
                if($data['event'] == 'new_chat') {
                    return $data['messages'] ?? null;
                } else {
                    return null;
                }
            case 'operator_login':
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
                return $dialog['visitor']['fields']['name'] ?? null;
                break;
            case 'messages':
                return $dialog['messages'];
                break;
            case 'clientId':
                return $dialog['id'];
                break;
            default:
                throw new Exception('Неизвестная переменная для получения из данных диалога.');
        }
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

        $dialog = $this->sendRequest("chat", ['id' => $client_id]);

        $messages = [];
        $all_messages = $dialog['chat']['messages'];

        foreach($all_messages as $key=>$message) {
            if(Carbon::parse($message['created_at']) > $date_end) {
                break;
            }

            if(in_array($message['kind'], ['visitor', 'operator', 'keyboard', 'keyboard_response', 'info']) && Carbon::parse($message['created_at']) >= $date_start) {
                $messages[] = $message;
            }
        }

        $dialog['chat']['messages'] = $messages;

        return $dialog['chat'];
    }

    /**
     * Получение диалогов со списком сообщений за период.
     *
     * @param array $period
     * @return array
     */
    public function getDialogsByPeriod(array $period)
    {
        /**
         * Фомирование временных рамок в нужном формате.
         *
         * @var \Carbon\Carbon $date_start
         * @var \Carbon\Carbon $date_end
         */
        $date_start = $period[0] ?? Carbon::now()->startOfDay();
        $date_end = $period[1] ?? Carbon::now();

        $chats = [];
        $more_chats_available = true;

        while($more_chats_available) {
            $result = $this->sendRequest('chats', ['since' => isset($result['last_ts']) ? $result['last_ts'] : 0]);

            $find_chats = false;

            foreach($result['chats'] as $chat) {

                $created_at = Carbon::parse($chat['created_at']);
                if($created_at >= $date_start && $created_at <= $date_end) {
                    $chats[] = $chat;
                    $find_chats = true;
                }
            }

            if(!empty($chats) && (!$find_chats && $more_chats_available)) {
                $more_chats_available = false;
            }
        }

        /**
         * Фильтрация сообщений по дате в диалоге.
         */
        foreach($chats as $key=>$chat) {
            $messages = [];

            foreach($chat['messages'] as $message) {
                if (Carbon::parse($message['created_at']) > $date_end) {
                    break;
                }

                if (in_array($message['kind'], [
                        'visitor',
                        'operator',
                        'keyboard',
                        'keyboard_response',
                        'info'
                    ]) && Carbon::parse($message['created_at']) >= $date_start) {
                    $messages[] = $message;
                }
            }

            if(empty($messages)) {
                unset($chat[$key]);
            } else {
                $chat[$key]['messages'] = $messages;
            }
        }

        return $chats;
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
            if(!in_array($message['kind'], ['visitor', 'operator', 'keyboard_response'])) {
                continue;
            }

            if($message['kind'] == 'keyboard_response') {
                $text = $this->deleteControlCharactersAndSpaces($message['data']['button']['text']);
            } else {
                $text = $this->deleteControlCharactersAndSpaces($message['message']);
            }

            if (preg_match('/' . $select_message . '/iu', $text)) {
                $message_id = $key;
                //break;
            }
        }

        return $message_id ?? null;
    }

    /**
     * Поиск сообщений оператора.
     *
     * @param array $messages
     * @return array
     */
    public function findOperatorMessages(array $messages)
    {
        $operator_messages = [];

        foreach ($messages as $message) {
            if ($message['kind'] == 'operator') {
                $operator_messages[] = $message['message'] ?? $message['text'];
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
        $client_messages = [];

        foreach ($messages as $message) {
            if ($message['kind'] == 'visitor') {
                $client_messages[] = $message['message'] ?? $message['text'];
            } elseif ($message['kind'] == 'keyboard_response') {
                $client_messages[] = $message['data']['button']['text'];
            }
        }

        return $client_messages;
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
        $chat_id = $this->getParamFromDataWebhook('client_id', $data);

        if (!empty($only_user_ids) && (empty($chat_id) || ! in_array($chat_id, $only_user_ids))) {
            return false;
        }

        return true;
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

        while($i >= 0 && !in_array($dialog['messages'][$i]['kind'], ['visitor', 'file_visitor', 'keyboard_response'])) {
            $message = $dialog['messages'][$i];
            $i--;
        }

        if(!in_array($message['kind'], ['visitor', 'file_visitor', 'keyboard_response'])) {
            return false;
        }

        return Carbon::parse($message['created_at']);
    }

    /**
     * Получение списка ид операторов онлайн.
     *
     * @return array
     */
    public function getListOnlineOperatorsIds()
    {
        $operators = $this->sendRequest('operators', []);

        $online_operators_ids = [];

        foreach($operators as $operator) {
            if(in_array('operator', $operator['roles']) && $operator['status'] == 'online') {
                $online_operators_ids[] = $operator['id'];
            }
        }

        return $online_operators_ids;
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
        $data = [
            'chat_id' => $client_id,
            'operator_id' => $operator_id,
        ];

        return (bool) $this->sendRequest('redirect_chat', $data);

    }

    /**
     * Закрытие чата.
     *
     * @param $client_id
     * @return bool
     */
    public function closeChat($client_id)
    {
        $data = [
            'chat_id' => $client_id
        ];

        return (bool) $this->sendRequest('close_chat', $data);
    }

    /**
     * Проверка был ли передан диалог боту.
     *
     * @param array $dialog
     * @return bool
     */
    public function isDialogRedirectedToBot(array $dialog)
    {
        $messages = $dialog['messages'];
        $redirected_message = "Диалог был передан оператору {$this->config['bot_name']}";

        $result = false;

        for($i = count($messages)-1; $i > 0; $i--) {
            if ($messages[$i]['kind'] == 'info') {
                $text = $messages[$i]['text'] ?? $messages[$i]['message'];

                if ($text == $redirected_message) {
                    $result = true;
                }
            } else {
                break;
            }
        }

        return $result;
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
        $ch = curl_init();

        $methods = [
            'GET' => [
                'chat',
                'chats',
                'operators',
            ],
            'POST' => [
                'send_message',
                'redirect_chat',
                'close_chat'
            ]
        ];

        if(in_array($api_method, $methods['GET'])) {

            /**
             * Подстановка параметров в запрос.
             */
            if(!empty($data)) {
                $api_method .= '?'.http_build_query($data);
            }

            curl_setopt_array($ch, [
                CURLOPT_URL => "https://allputru001.webim.ru/api/v2/{$api_method}",
                CURLOPT_RETURNTRANSFER => 1,
                CURLOPT_USERPWD => $this->config['login'].':'.$this->config['password'],
                CURLOPT_HTTPHEADER => [
                    "Content-Type: application/json",
                ],
            ]);
        } elseif(in_array($api_method, $methods['POST'])) {
            /**
             * Подготовка данных.
             */
            if(!empty($data)) {
                $data = json_encode($data);
            }

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
        } else {
            throw new Exception("[Webim] Неизвестный запрос к Webim ( $api_method )");
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