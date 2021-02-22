<?php

namespace SrcLab\SupportBot;

use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use SrcLab\OnlineConsultant\Contracts\OnlineConsultant;
use SrcLab\SupportBot\Repositories\SupportAutoAnsweringRepository;
use SrcLab\SupportBot\Repositories\SupportRedirectChatRepository;
use SrcLab\SupportBot\Support\Traits\SupportBotStatistic;

class SupportBot
{
    use SupportBotStatistic;
    
    /**
     * @var \SrcLab\SupportBot\Repositories\SupportAutoAnsweringRepository
     */
    protected $messages_repository;

    /**
     * @var \SrcLab\OnlineConsultant\Contracts\OnlineConsultant
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
     * @var \SrcLab\SupportBot\SupportBotScript
     */
    protected $support_bot_scripts;

    /**
     * @var \SrcLab\SupportBot\Repositories\SupportRedirectChatRepository
     */
    protected $redirect_chat_repository;

    /**
     * SupportAutoAnswering constructor.
     */
    public function __construct()
    {
        $this->config = array_merge(config('support_bot'), app_config('support_bot'));
        $this->messages_repository = app(SupportAutoAnsweringRepository::class);
        $this->online_consultant = app(OnlineConsultant::class);
        $this->support_bot_scripts = app(SupportBotScript::class);
        $this->redirect_chat_repository = app(SupportRedirectChatRepository::class);
        $this->cache = app('cache');
    }

    /**
     * Обработка новых обращений по вебхуку. ( - )
     *
     * @param array $data
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function processWebhook(array $data)
    {
        /**
         * Проверка полученных данных, определение возможности сформировать ответ.
         */
        if(!$this->online_consultant->checkWebhookDataForNewMessage($data)) {
            return false;
        }

        $search_id = $this->online_consultant->getParamFromDataWebhook('search_id', $data);

        if(!empty($this->config['scripts']['enabled']) && !empty($search_id)) {

            /**
             * Планирование отложенного сценария для удержания пользователя.
             */
            if($this->support_bot_scripts->checkEnabledUserIds($search_id)) {

                $result_handle = $this->support_bot_scripts->handleScriptForUserIfExists($search_id, $data);

                if ($result_handle == 'processing') {
                    return true;
                } else {
                    /**
                     * Задержка чата на боте в случае если на бота чат перекинул оператор.
                     */
                    if ($this->online_consultant->getOnlineConsultantName() == 'webim') {
                        if ($data['event'] == 'new_chat') {
                            if (!$result_handle) {
                                $this->support_bot_scripts->planningPendingScripts($search_id);
                            }

                            $dialog = $this->online_consultant->getDialogFromClientByPeriod($search_id, [Carbon::now()->subDays(2), Carbon::now()->endOfDay()]);

                            if ($this->online_consultant->isClientRedirectedToBot($dialog)) {
                                return true;
                            }
                        }
                    } elseif (!$result_handle) {
                        $this->support_bot_scripts->planningPendingScripts($search_id);
                    }
                }
            }
        }

        /**
         * Проверка фильтра пользователей по id на сайте.
         */
        if(!$this->online_consultant->checkEnabledUserIds($this->config['enabled_for_user_ids'], $data)) {
            return false;
        }

        /**
         * В инстаграм не отвечать.
         */
        if($this->online_consultant->getOnlineConsultantName() == 'talk_me' && !empty($data['client']['source']['type']['id']) && $data['client']['source']['type']['id'] == 'instagram') {
            return false;
        }

        /**
         * Автоответчик.
         */
        if($this->autoResponder($data)) {
            return true;
        }

        /**
         * Бот отключен.
         */
        if(!$this->config['enabled']) {
            return false;
        }

        /**
         * Проверка периода активности бота.
         */
        if(!$this->checkActivePeriod()) {
            return false;
        }

        /**
         * Удаление отложенных сообщений, если пользователь написал что-либо после приветствия.
         */
        if($this->config['deferred_answer_after_welcome'] ?? false) {
            $client_id = $this->online_consultant->getParamFromDataWebhook('client_id', $data);

            $this->messages_repository->deleteDeferredMessagesByClient($client_id);
        }

        /**
         * Формирование автоответа.
         */
        [$answer_index, $answer] = $this->getAnswer($data);

        if(empty($answer)) {
            return false;
        }

        $client_id = $this->online_consultant->getParamFromDataWebhook('client_id', $data);

        /**
         * Отправка сообщения.
         */
        $this->sendOrPlaningMessage($client_id, $answer, $this->online_consultant->getParamFromDataWebhook('operator_login', $data));

        /**
         * Если ответ это простое приветствие, добавление отложенного сообщения "Чем я могу вам помочь?"
         */
        if(($this->config['deferred_answer_after_welcome'] ?? false) && preg_match('/^(?:Здравствуйте|Привет|Добрый вечер|Добрый день)[.!)\s]?$/iu', $this->online_consultant->getParamFromDataWebhook('message_text', $data))) {
            $this->messages_repository->addRecord($client_id, $this->online_consultant->getParamFromDataWebhook('operator_login', $data), 'Чем я могу вам помочь?', now()->addMinutes(2));
        }

        /**
         * Запись информации о том, что ответ уже отправлялся сегодня.
         */
        $this->writeJustSentAnswerToday($answer_index, $client_id);

        return true;
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
            $this->sendMessageAndIncrementStatistic($message->client_id, $message->message, $message->operator);
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

        $period = [now()->subDays($cache_days-1)->startOfDay(), Carbon::now()->endOfDay()];

        $dialogs = $this->online_consultant->getDialogsByPeriod($period);

        if(empty($dialogs)) {
            Log::error('[SrcLab\SupportBot] Не удалось получить сообщения для составления статистики отпраки.');
            return [];
        }

        /**
         * Подсчет и добавление общего количества сообщений от менеджеров по дням.
         */
        $messages_by_days = $this->online_consultant->findOperatorMessagesAndGroupBySentAt($dialogs);

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

        $message = $this->online_consultant->getParamFromDataWebhook('message_text', $data);
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
        $client_id = $this->online_consultant->getParamFromDataWebhook('client_id', $data);

        if($this->isJustSentAnswerToday($answer_index, $client_id)) {
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

        return check_current_time($period['day_beginning'], $period['day_end']);
    }

    /**
     * Проверка на факт приветсвия клиента за текущий день.
     *
     * @param array $data
     * @return bool
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    protected function alreadySaidHello(array $data)
    {
        $search_id = $this->online_consultant->getParamFromDataWebhook('search_id', $data);

        $cache_key = 'SupportBot:AlreadySaidHello:'.$search_id;

        /**
         * В кеше есть запись о том, что сегодня уже здоровались.
         */
        if($this->cache->has($cache_key)) {
            return true;
        }

        /**
         * Получение сообщений за сегодняшний день и поиск приветствия с нашей стороны.
         */
        $dialog = $this->online_consultant->getDialogFromClientByPeriod($search_id, [Carbon::now()->startOfDay(), Carbon::now()->endOfDay()]);
        $today_operator_messages = $this->online_consultant->findOperatorMessages($this->online_consultant->getParamFromDialog('messages', $dialog));

        if(empty($today_operator_messages)) {
            $this->cache->set($cache_key, 1, now()->endOfDay());
            return false;
        }

        foreach ($today_operator_messages as $message) {
            if(preg_match('/(?:Здравствуйте|Добрый день|Доброе утро|Добрый вечер)/iu', $message)) {
                return true;
            }
        }

        /**
         * Запись в кеш факта приветствия.
         */
        $this->cache->set($cache_key, 1, now()->endOfDay());

        return false;
    }

    /**
     * Добавление ответа в очередь на отправку или мгновенная отправка на основании текущего режима работы.
     *
     * @param int $client_id
     * @param string $message
     * @param null|string $operator
     */
    protected function sendOrPlaningMessage($client_id, $message, $operator = null)
    {
        if($this->config['answering_mode'] == 'sync') {
            $this->sendMessageAndIncrementStatistic($client_id, $message, $operator);
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
        $auto_responder_config = $this->config['auto_responder'];

        /**
         * Автоответчик выключен.
         */
        if(empty($auto_responder_config['enabled'])) {
            return false;
        }

        /**
         * Проверка периода активности автоответчика.
         */
        if(empty($auto_responder_config['period_begin']) || empty($auto_responder_config['period_end'])) {
            return false;
        }

        if(!check_current_time($auto_responder_config['period_begin'], $auto_responder_config['period_end'])) {
            return false;
        }

        /**
         * Проверка полученных данных, определение возможности сформировать ответ.
         */
        if(empty($auto_responder_config['message'])) {
            return false;
        }

        /**
         * Проверка типа полученного сообщения. Для реального сообщения от пользователя тип должен быть = null.
         */
        if(!empty($data['message']['messageType'])) {
            return false;
        }

        /**
         * Сегодня уже был отправлен автоответ.
         */
        $cache_key = 'SupportBot:TodayJustSentAutoRespond';

        $just_sent_clients = $this->cache->get($cache_key, []);

        $client_id = $this->online_consultant->getParamFromDataWebhook('client_id', $data);

        if(!empty($just_sent_clients[$client_id])) {
            return false;
        }

        /**
         * Отправка автоответа.
         */
        $operator = empty($data['operator']['login']) || $data['operator']['login'] == 'Offline' ? null : $data['operator']['login'];

        $this->sendOrPlaningMessage($client_id, $auto_responder_config['message'], $operator);

        /**
         * Отложенный редирект пользователя в рабочее время.
         */
        $this->redirect_chat_repository->addRecord($client_id);

        /**
         * Отметка что сегодня уже был отправлен автоответ.
         */
        $just_sent_clients[$client_id] = true;
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
}