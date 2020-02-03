<?php

namespace SrcLab\SupportBot;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use SrcLab\SupportBot\Contracts\OnlineConsultant;
use Throwable;

class SupportBot

{
    /**
     * @var \SrcLab\SupportBot\SupportAutoAnsweringRepository
     */
    protected $messages_repository;

    /**
     * @var \SrcLab\SupportBot\Contracts\OnlineConsultant
     */
    protected $online_consultant;

    /**
     * @var \Illuminate\Config\Repository|mixed
     */
    protected $config;

    /**
     * @var \Illuminate\Contracts\Cache\Repository $cache
     */
    protected $cache;

    /**
     * SupportAutoAnswering constructor.
     */
    public function __construct()
    {
        $this->config = array_merge(config('support_bot'), app_config('support_bot'));
        $this->messages_repository = app(SupportAutoAnsweringRepository::class);
        $this->online_consultant = app(OnlineConsultant::class, ['config' => $this->config['accounts']['talk_me']]);
        $this->cache = app('cache');
    }

    /**
     * Обработка новых обращений по вебхуку.
     *
     * @param array $data
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function processWebhook(array $data)
    {
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
         * Проверка периода активности бота.
         */
        if(!$this->checkActivePeriod()) {
            return;
        }

        /**
         * Проверка полученных данных, определение возможности сформировать ответ.
         */
        if(!$this->checkWebhookData($data)) {
            return;
        }

        /**
         * Удаление отложенных сообщений, если пользователь написал что-либо после приветствия.
         */
        $this->messages_repository->deleteDeferredMessagesByClient($data['client']['clientId']);

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
        if($answer == 'Здравствуйте! 🤗') {
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
     * Отложенная отправка автоответов.
     *
     * @param bool $deferred
     */
    public function sendAnswers($deferred = false)
    {
        /**
         * Получение пачки сообщений для отправки.
         */
        if($deferred) {
            $messages = $this->messages_repository->getNextDeferredPart();
        } else {
            $messages = $this->messages_repository->getNextSendingPart();
        }

        if($messages->isEmpty()) return;

        /**
         * Удаление сообщений из очереди.
         */
        $this->messages_repository->deleteWhereIn('id', $messages->pluck('id')->toArray());

        /**
         * Отправка сообщений.
         */
        foreach ($messages as $message) {
            $this->online_consultant->sendMessage($message->client_id, $message->message, $message->operator);
        }
    }

    /**
     * Получение накопленной статистики по количеству отправленных сообщений.
     *
     * @return array
     */
    public function getSentMessagesStatistic()
    {
        $cache_key = 'SupportBot:SentMessagesByDays';
        $cache_days = $this->config['sent_messages_analyse']['cache_days'];

        /**
         * Получение количества отправленных ботом сообщений по дням.
         */
        $data = $this->cache->get($cache_key, []);

        if(empty($data)) {
            return $data;
        }

        /**
         * Получение чатов за последние N дней (количество дней хранимых в кеше).
         */
        $cache_days = $cache_days > 14 ? 14 : $cache_days;

        $filter = [
            'period' => [now()->subDays($cache_days-1)->startOfDay(), Carbon::now()->endOfDay()],
        ];

        $messages = $this->online_consultant->getMessages($filter);

        if(empty($messages)) {
            Log::error('[SrcLab\SupportBot] Не удалось получить сообщения для составления статистики отпраки.');
            return [];
        }

        /**
         * Подсчет и добавление общего количества сообщений от менеджеров по дням.
         */
        $messages = array_reduce(array_pluck($messages, 'messages'), 'array_merge', []);

        $messages = collect($messages);

        $messages = $messages->where('whoSend', 'operator');

        $messages_by_days = $messages->groupBy(function ($value, $key) {
            return Carbon::parse($value['dateTime'])->toDateString();
        });

        $result_data = [];

        foreach ($data as $date => $count) {
            $result_data[] = [
                'date' => $date,
                'total' => count($messages_by_days[$date] ?? []),
                'from_bot' => $count,
            ];
        }

        return $result_data;
    }

    //****************************************************************
    //************************** Support *****************************
    //****************************************************************

    /**
     * Получение ответа на обращение.
     *
     * @param array $data
     * @return array
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    protected function getAnswer(array $data)
    {
        /**
         * Определение автоответа на обращение.
         */
        $answers = $this->config['auto_answers'];

        $message = $data['message']['text'];
        $result_answer = '';
        $answer_index = -1;
        $default_result = [-1, ''];

        foreach ($answers as $question => $answer) {

            $answer_index++;

            if(preg_match('/'.$question.'/iu', $message)) {
                $result_answer = $answer;
                break;
            }

        }

        if(empty($result_answer)) {
            return $default_result;
        }

        /**
         * Если сегодня уже отправляли такой ответ.
         */
        if($this->isJustSentAnswerToday($answer_index, $data['client']['clientId'])) {
            return $default_result;
        }

        /**
         * Проверка ответа фильтрами.
         */
        if(!app(Filters::class)->checkFilters($message, $answer_index)) {
            return $default_result;
        }

        /**
         * Добавление приветствия, если автоответ сформирован
         * и это не приветствие или ответ из исключающего массива.
         */

        /**
         * Сегодня уже здоровались.
         */
        $already_said_hello = $this->alreadySaidHello($data);

        $answer_is_greeting = $result_answer == $this->config['greeting_phrase'];

        /**
         * Если получена фраза приветствия и уже здоровались, то ничего отвечать не нужно.
         */
        if($already_said_hello && $answer_is_greeting) {
            return $default_result;
        }

        /**
         * Если сегодня еще не здоровались и это не фраза приветствия или ответ из исключающего массива,
         * добавление приветствия в начало фразы.
         */
        if(!$already_said_hello && !$answer_is_greeting && !in_array($result_answer, $this->config['answers_without_greeting'])) {
            $result_answer = $this->config['greeting_phrase'] . "\n\n" . $result_answer;
        }

        return [$answer_index, $result_answer];
    }

    /**
     * Проверка периода автивности.
     *
     * @return bool
     */
    protected function checkActivePeriod()
    {
        /**
         * Получение установленного периода.
         */
        $period = $this->config['active_period'];

        /**
         * Если период не задан, то бот активен.
         */
        if(empty($period)) {
            return true;
        }

        return $this->checkCurrentTime($period['day_beginning'], $period['day_end']);
    }

    /**
     * Проверка полученных данных в вебхуке, определение возможности сформировать ответ.
     *
     * @param array $data
     * @param bool $check_operator
     * @return bool
     */
    protected function checkWebhookData(array $data, $check_operator = true)
    {
        /**
         * Проверка секретки.
         */
        if(!$this->online_consultant->checkSecret($data['secretKey'] ?? null)) {
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
        if($check_operator && empty($data['operator']['login'])) {
            Log::error('[SrcLab\SupportBot] Не найден оператор.', $data);
            return false;
        }

        /**
         * Проверка фильтра пользователей по id на сайте.
         */
        $only_user_ids = $this->config['enabled_for_user_ids'] ?? [];

        if(!empty($only_user_ids)
            && (empty($data['client']['customData']['user_id'])
                || !in_array($data['client']['customData']['user_id'], $only_user_ids))
        ) {
            return false;
        }

        return true;
    }

    /**
     * Проверка на факт приветсвия клиента за текущий день.
     *
     * @param array $data
     * @return bool
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function alreadySaidHello(array $data)
    {
        $cache_key = 'SupportBot:AlreadySaidHello:'.$data['client']['searchId'];

        /**
         * В кеше есть запись о том, что сегодня уже здоровались.
         */
        if($this->cache->has($cache_key)) {
            return true;
        }

        /**
         * Получение сообщений за сегодняшний день и поиск приветствия с нашей стороны.
         */
        $filter = [
            'period' => [Carbon::now()->startOfDay(), Carbon::now()->endOfDay()],
            'client' => [
                'searchId' => $data['client']['searchId'],
            ]
        ];

        $today_messages = $this->online_consultant->getMessages($filter);

        if(empty($today_messages)) {
            $this->cache->set($cache_key, 1, now()->endOfDay());
            return false;
        }

        foreach ($today_messages as $case) {
            foreach ($case['messages'] as $message) {
                if($message['whoSend'] != 'client' && preg_match('/(?:Здравствуйте|Добрый день|Доброе утро|Добрый вечер)/iu', $message['text'])) {
                    return true;
                }
            }
        }

        /**
         * Запись в кеш факта приветствия.
         */
        $this->cache->set($cache_key, 1, now()->endOfDay());

        return false;
    }

    /**
     * Увеличение счетчика отправленных сообщений для статистики.
     */
    protected function sentMessagesAnalyse()
    {
        try {

            /**
             * Анализ сообщений отключен.
             */
            if (!$this->config['sent_messages_analyse']['enabled']) {
                return;
            }

            /**
             * Получение данных из кеша.
             */
            $cache_key = 'SupportBot:SentMessagesByDays';
            $cache_days = $this->config['sent_messages_analyse']['cache_days'];

            $data = $this->cache->get($cache_key, []);

            /**
             * Проход по дням, удаление старой информации.
             */
            if (!empty($data)) {

                $date_border = now()->subDays($cache_days)->toDateString();

                foreach ($data as $date => $count) {
                    if ($date < $date_border) {
                        unset($data[$date]);
                    }
                }

            }

            /**
             * Инкремент счетчика текущего дня.
             */
            $current_date = now()->toDateString();

            $data[$current_date] = ($data[$current_date] ?? 0) + 1;

            /**
             * Сохранение данных.
             */
            $this->cache->set($cache_key, $data, now()->addDays($cache_days));

        } catch (Throwable $e) {
            Log::error('[SrcLab\SupportBot] Ошибка анализатора отправленных сообщений');
        }
    }

    /**
     * Добавление ответа в очередь на отправку или мгновенная отправка на основании текущего режима работы.
     *
     * @param int $client_id
     * @param string $message
     * @param string $operator
     */
    protected function sendMessage($client_id, $message, $operator)
    {
        if($this->config['answering_mode'] == 'sync') {
            $this->online_consultant->sendMessage($client_id, $message, $operator);
        } else {
            $this->messages_repository->addRecord($client_id, $operator, $message);
        }
    }

    /**
     * Автоответчик.
     *
     * @param array $data
     * @return bool
     */
    protected function autoResponder(array $data)
    {
        /**
         * Автоответчик выключен.
         */
        $auto_responder_config = $this->config['auto_responder'];

        if(empty($auto_responder_config['enabled'])) {
            return false;
        }

        /**
         * Проверка периода активности автоответчика.
         */
        if(empty($auto_responder_config['period_begin']) || empty($auto_responder_config['period_end'])) {
            return false;
        }

        if(!$this->checkCurrentTime($auto_responder_config['period_begin'], $auto_responder_config['period_end'])) {
            return false;
        }

        /**
         * Проверка полученных данных, определение возможности сформировать ответ.
         */
        if(!$this->checkWebhookData($data, false) || empty($auto_responder_config['message'])) {
            return false;
        }

        /**
         * Проверка типа полученного сообщения. Для реального сообщения от пользователя тип должен быть = null.
         */
        if(!empty($data['message']['messageType'])) {
            return false;
        }

        /**
         * В инстаграм не отвечать.
         */
        if(!empty($data['client']['source']['type']['id']) && $data['client']['source']['type']['id'] == 'instagram') {
            return true;
        }

        /**
         * Сегодня уже был отправлен автоответ.
         */
        $cache_key = 'SupportBot:TodayJustSentAutoRespond';

        $just_sent_clients = $this->cache->get($cache_key, []);

        if(!empty($just_sent_clients[$data['client']['clientId']])) {
            return true;
        }

        /**
         * Отправка автоответа.
         */
        $operator = empty($data['operator']['login']) || $data['operator']['login'] == 'Offline' ? null : $data['operator']['login'];

        $this->sendMessage($data['client']['clientId'], $auto_responder_config['message'], $operator);

        /**
         * Отметка что сегодня уже был отправлен автоответ.
         */
        $just_sent_clients[$data['client']['clientId']] = true;
        $this->cache->set($cache_key, $just_sent_clients, Carbon::createFromFormat('H:i', $auto_responder_config['period_end'])->addDays(now()->hour > 12 ? 1 : 0));

        return true;
    }

    /**
     * Проверка на то, отправлялся ли уже ответ сегодня.
     *
     * @param int $answer_key
     * @param string $client_id
     * @return bool
     */
    protected function isJustSentAnswerToday($answer_key, $client_id)
    {
        $sent_today = $this->cache->get('SupportBot:JustSentAnswersToday', []);

        return !empty($sent_today[$client_id]) && in_array($answer_key, $sent_today[$client_id]);
    }

    /**
     * Запись информации в кеш о том, что ответ уже отправлялся сегодня.
     *
     * @param int $answer_key
     * @param string $client_id
     */
    protected function writeJustSentAnswerToday($answer_key, $client_id)
    {
        $cache_key = 'SupportBot:JustSentAnswersToday';
        $sent_today = $this->cache->get($cache_key, []);

        $sent_today[$client_id][] = $answer_key;

        $this->cache->set($cache_key, $sent_today, now()->endOfDay()->addHours(3));
    }

    /**
     * Проверка, что текущее время содержится в указанном интервале.
     *
     * @param string $time_begin
     * @param string $time_end
     * @return bool
     */
    protected function checkCurrentTime($time_begin, $time_end)
    {
        $now_time = now()->format('H:i');

        if($time_begin > $time_end) {
            return !($now_time < $time_begin && $now_time > $time_end);
        }

        return $now_time >= $time_begin && $now_time <= $time_end;
    }

}