<?php

namespace Vsesdal\SupportBot;

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
     */
    public function processWebhook(array $data)
    {
        /**
         * Проверка секретки.
         */
        if(!$this->online_consultant->checkSecret($data['secretKey'] ?? null)) {
            Log::warning('[Vsesdal\SupportBot] Получен неверный секретный ключ.', $data);
            return;
        }

        /**
         * Проверка наличия сообщения.
         */
        if(empty($data['message'])) {
            Log::error('[Vsesdal\SupportBot] Сообщение не получено.', $data);
            return;
        }

        /**
         * Проверка наличия оператора.
         */
        if(empty($data['operator']['login'])) {
            Log::error('[Vsesdal\SupportBot] Не найден оператор.', $data);
            return;
        }

        /**
         * Формирование автоответа.
         */
        $answer = $this->getAnswer($data['message']['text']);

        if(empty($answer)) return;

        /**
         * Добавление ответа в очередь на отправку или мгновенная отправка на основании текущего редима работы.
         */
        if($this->config['answering_mode'] == 'sync') {
            $this->online_consultant->sendMessage($data['client']['clientId'], $answer, $data['operator']['login']);
        } else {
            $this->messages_repository->addRecord($data['client']['clientId'], $data['operator']['login'], $answer);
        }

        //Log::info("[Vsesdal\SupportBot] Сформирован автоответ. {$data['message']['text']} => {$answer}");
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

        //Log::info("[Vsesdal\SupportBot] Отправлено {$messages->count()} автоответов.");
    }

    //****************************************************************
    //************************** Support *****************************
    //****************************************************************

    /**
     * Получение ответа на обращение.
     *
     * @param string $case
     * @return string
     */
    protected function getAnswer($case)
    {
        $answers = $this->config['auto_answers'];

        foreach ($answers as $question => $answer) {

            if(preg_match('/'.$question.'/i', $case)) {
                return $answer;
            }

        }

        return '';
    }

}