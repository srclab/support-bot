<?php

namespace SrcLab\SupportBot\Services\Messengers\Webim;

use Carbon\Carbon;
use Illuminate\Support\Arr;
use SrcLab\SupportBot\Contracts\OnlineConsultant;
use SrcLab\SupportBot\Filters;
use SrcLab\SupportBot\Repositories\SupportWebimDialogsListSinceParamRepository;
use SrcLab\SupportBot\Services\Messengers\Messenger;
use Illuminate\Support\Facades\Log;
use Exception;
use SrcLab\SupportBot\SupportBot;

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
        if (empty($config['webim']) || empty($config['webim']['subdomain']) || empty($config['webim']['api_token']) || empty($config['webim']['login']) || empty($config['webim']['password'])) {
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
         * TODO: сделано для проверки, удалить при запуске на прод.
         */
        if($data['event'] == 'new_chat') {
            if(strstr($data['visitor']['fields']['name'], 'Test4556') === false) {
                return false;
            }
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

        return $this->sendRequest('send_message', $data);
    }

    /**
     * Отправка сообщения с кнопками клиенту.
     *
     * @param string $client_id
     * @param array $button_names
     * @param string $operator
     * @return bool
     */
    public function sendButtonsMessage($client_id, array $button_names, $operator = null)
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
        switch($param) {
            case 'client_id':
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
            case 'messages':
                return $dialog['messages'];
            case 'client_id':
            case 'search_id':
                return $dialog['id'];
            case 'operator_id':
                return $dialog['operator_id'];
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
     * Получение диалогов за период.
     *
     * @param $since
     * @return array|bool
     * @throws \Exception
     */
    public function getDialogsBySince($since)
    {
        return $this->sendRequest('chats', ['since' => $since]);
    }

    /**
     * Получение периода среди диалогов ( самый новый и самый старый диалог ).
     *
     * @param array $dialogs
     * @return array
     */
    public function getPeriodByDialogs($dialogs)
    {
        $period_from = Carbon::parse($dialogs[0]['created_at']);
        $period_to = Carbon::parse($dialogs[0]['created_at']);

        foreach($dialogs as $chat) {

            $created_at = Carbon::parse($chat['created_at']);
            $last_message_at = Carbon::parse(array_pop($chat['messages'])['created_at']);

            if($created_at < $period_from) {
                $period_from = $created_at;
            }

            if($last_message_at > $period_to) {
                $period_to = $last_message_at;
            }
        }

        return [
            'from' => $period_from,
            'to' => $period_to,
        ];
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
         * @var \Carbon\Carbon $now
         * @var \Carbon\Carbon $date_start
         * @var \Carbon\Carbon $date_end
         */
        $now = Carbon::now();
        $date_start = $period[0] ?? $now->startOfDay();
        $date_end = $period[1] ?? $now;

        $webim_dialog_list_since_param_repository = app(SupportWebimDialogsListSinceParamRepository::class);
        $sinces = $webim_dialog_list_since_param_repository->getByPeriod($date_start, $date_end);

        /** @var \Illuminate\Support\Collection $chats */
        $chats = collect([]);

        foreach($sinces as $since) {
            $result = $this->getDialogsBySince($since->since);
            $chats = $chats->merge($result['chats']);
        }

        /**
         * Проверка наличия новых диалогов в Webim.
         */
        if($date_end >= $now || $sinces->isEmpty() || empty($result)) {

            if($sinces->isEmpty() || empty($result)) {
                $last_ts = $webim_dialog_list_since_param_repository->getLast()->last_ts;
            }

            do {
                $result = $this->getDialogsBySince($result['last_ts'] ?? $last_ts);

                $chats = $chats->merge($result['chats']);
            } while($result['more_chats_available']);
        }

        $chats = $chats->unique('id');

        /**
         * Фильтрация сообщений по дате в диалоге.
         */

        $chats = $chats->map(function($chat) use($date_start, $date_end) {
            $messages = [];

            foreach($chat['messages'] as $message) {
                $message['created_at'] = Carbon::parse($message['created_at']);

                if ($message['created_at'] >= $date_start && $message['created_at'] <= $date_end) {
                    $messages[] = $message;
                }
            }

            $chat['messages'] = $messages;

            return $chat;
        });

        return $chats->toArray();
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
            if (preg_match('/' . $select_message . '/iu', $this->deleteControlCharactersAndSpaces($message))) {
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

        foreach ($messages as $key=>$message) {
            if ($message['kind'] == 'operator') {
                $operator_messages[$key] = $message['message'] ?? $message['text'];
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

        foreach ($messages as $key=>$message) {
            if ($message['kind'] == 'visitor') {
                $client_messages[$key] = $message['message'] ?? $message['text'];
            } elseif ($message['kind'] == 'keyboard_response') {
                $client_messages[$key] = $message['data']['button']['text'];
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
     * Получение даты и времени последнего сообщения клиента или оператора в диалоге.
     *
     * @param array $dialog
     * @return \Carbon\Carbon
     */
    public function getDateTimeLastMessage($dialog)
    {
        $i = count($dialog['messages'])-1;
        $message = $dialog['messages'][$i];

        while($i >= 0 && !in_array($dialog['messages'][$i]['kind'], ['visitor', 'file_visitor', 'keyboard_response', 'operator'])) {
            $message = $dialog['messages'][$i];
            $i--;
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
     * @param array $dialog
     * @param mixed $to_operator
     * @return bool
     */
    public function redirectDialogToOperator(array $dialog, $to_operator)
    {
        $data = [
            'chat_id' => $dialog['id'],
            'operator_id' => $to_operator,
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
     * Проверка был ли клиент передан боту.
     *
     * @param array $dialog
     * @return bool
     */
    public function isClientRedirectedToBot($dialog)
    {
        $messages = $dialog['messages'];

        if(empty($messages)) {
            return false;
        }

        for($i = count($messages)-1; $i > 0; $i--) {
            if ($messages[$i]['kind'] == 'info') {

                if(preg_match('/Диалог был передан оператору (.*)/m', $messages[$i]['message'], $operator)) {
                    if($operator[1] == $this->config['bot_operator_name']) {
                        return true;
                    }

                    break;
                }
            }

            break;
        }

        return false;
    }

    /**
     * Поиск сообщений оператора и группировка по дате отправку.
     *
     * @param array $dialogs
     * @return \Illuminate\Support\Collection
     */
    public function findOperatorMessagesAndGroupBySentAt(array $dialogs)
    {
        $messages = array_reduce(Arr::pluck($dialogs, 'messages'), 'array_merge', []);

        $messages = collect($messages);

        $messages = $messages->where('kind', 'operator');

        return $messages->groupBy(function ($value, $key) {
            return Carbon::parse($value['created_at'])->toDateString();
        });
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
                CURLOPT_URL => "https://{$this->config['subdomain']}.webim.ru/api/v2/{$api_method}",
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
                CURLOPT_URL => "https://{$this->config['subdomain']}.webim.ru/api/bot/v2/{$api_method}",
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
