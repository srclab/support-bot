<?php

namespace Vsesdal\SupportBot;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Vsesdal\SupportBot\Contracts\OnlineConsultant;

class SupportBot

{
    /**
     * @var \Vsesdal\SupportBot\SupportAutoAnsweringRepository
     */
    protected $messages_repository;

    /**
     * @var \Vsesdal\SupportBot\Contracts\OnlineConsultant
     */
    protected $online_consultant;

    /**
     * @var \Illuminate\Config\Repository|mixed
     */
    protected $config;

    /**
     * SupportAutoAnswering constructor.
     */
    public function __construct()
    {
        $this->config = config('support_bot');
        $this->messages_repository = app(SupportAutoAnsweringRepository::class);
        $this->online_consultant = app(OnlineConsultant::class, ['config' => $this->config['accounts']['talk_me']]);
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
         * Формирование автоответа.
         */
        $answer = $this->getAnswer($data);

        if(empty($answer)) {
            return;
        }

        /**
         * Добавление ответа в очередь на отправку или мгновенная отправка на основании текущего редима работы.
         */
        if($this->config['answering_mode'] == 'sync') {
            $this->online_consultant->sendMessage($data['client']['clientId'], $answer, $data['operator']['login']);
        } else {
            $this->messages_repository->addRecord($data['client']['clientId'], $data['operator']['login'], $answer);
        }
    }

    /**
     * Отложенная отправка автоответов.
     */
    public function sendAnswers()
    {
        /**
         * Получение пачки сообщений для отправки.
         */
        $messages = $this->messages_repository->getNextSendingPart();

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

    //****************************************************************
    //************************** Support *****************************
    //****************************************************************

    /**
     * Получение ответа на обращение.
     *
     * @param array $data
     * @return string
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    protected function getAnswer(array $data)
    {
        /**
         * Определение автоответа на обращение.
         */
        $answers = $this->config['auto_answers'];

        $result_answer = '';

        foreach ($answers as $question => $answer) {

            if(preg_match('/'.$question.'/iu', $data['message']['text'])) {
                $result_answer = $answer;
                break;
            }

        }

        /**
         * Добавление приветствия, если автоответ сформирован и это не приветствие.
         */
        if(!empty($result_answer) && $result_answer != $this->config['greeting_phrase']) {

            /**
             * Сегодня еще не здоровались.
             */
            if(!$this->alreadySaidHello($data)) {
                $result_answer = $this->config['greeting_phrase'] . "\n\n" . $result_answer;
            }

        }

        return $result_answer;
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

        /**
         * Парсинг времени.
         */
        try {
            $day_beginning = Carbon::createFromFormat('H:i', $period['day_beginning']);
            $day_end = Carbon::createFromFormat('H:i', $period['day_end']);
        } catch (\Throwable $e) {
            Log::error('[Vsesdal\SupportBot] Ошибка парсинга дат.', [$e]);
            return true;
        }

        return Carbon::now()->between($day_beginning, $day_end);
    }

    /**
     * Проверка полученных данных в вебхуке, определение возможности сформировать ответ.
     *
     * @param array $data
     * @return bool
     */
    protected function checkWebhookData(array $data)
    {
        /**
         * Проверка секретки.
         */
        if(!$this->online_consultant->checkSecret($data['secretKey'] ?? null)) {
            Log::warning('[Vsesdal\SupportBot] Получен неверный секретный ключ.', $data);
            return false;
        }

        /**
         * Проверка наличия сообщения.
         */
        if(empty($data['message'])) {
            Log::error('[Vsesdal\SupportBot] Сообщение не получено.', $data);
            return false;
        }

        /**
         * Проверка наличия оператора.
         */
        if(empty($data['operator']['login'])) {
            Log::error('[Vsesdal\SupportBot] Не найден оператор.', $data);
            return false;
        }

        /**
         * Проверка фильтра пользователей по id на сайте.
         */
        $only_user_ids = config('support_bot.enabled_for_user_ids');

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
        /** @var \Illuminate\Contracts\Cache\Repository $cache */
        $cache = app('cache');
        $cache_key = 'SupportBot:AlreadySaidHello:'.$data['client']['searchId'];

        /**
         * В кеше есть запись о том, что сегодня уже здоровались.
         */
        if($cache->has($cache_key)) {
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
            $cache->set($cache_key, 1, now()->endOfDay());
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
        $cache->set($cache_key, 1, now()->endOfDay());

        return false;
    }

}