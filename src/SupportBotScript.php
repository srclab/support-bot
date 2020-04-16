<?php

namespace SrcLab\SupportBot;

use Carbon\Carbon;
use SrcLab\SupportBot\Contracts\OnlineConsultant;
use SrcLab\SupportBot\Repositories\SupportScriptExceptionRepository;
use SrcLab\SupportBot\Repositories\SupportScriptRepository;

class SupportBotScript
{
    /**
     * @var \Illuminate\Config\Repository|mixed
     */
    protected $config;

    /**
     * @var \SrcLab\SupportBot\Contracts\OnlineConsultant
     */
    protected $online_consultant;

    /**
     * @var \SrcLab\SupportBot\Repositories\SupportScriptRepository
     */
    protected $scripts_repository;

    /**
     * @var \SrcLab\SupportBot\Repositories\SupportScriptExceptionRepository
     */
    protected $scripts_exception_repository;

    /**
     * SupportBotScripts constructor.
     */
    public function __construct()
    {
        $this->config = array_merge(config('support_bot'), app_config('support_bot'));
        $this->online_consultant = app(OnlineConsultant::class, ['config' => $this->config['accounts']['talk_me']]);
        $this->scripts_repository = app(SupportScriptRepository::class);
        $this->scripts_exception_repository = app(SupportScriptExceptionRepository::class);
    }

    /**
     * Планировка или обработка скрипта для пользователя.
     *
     * @param $clientId
     */
    public function planingOrProcessScriptForUser($clientId)
    {
        $script = $this->scripts_repository->findBy(['client_id' => $clientId]);

        if(is_null($script)) {
            $this->planningPendingScripts($clientId);
        } else {
            $this->processScript($clientId);
        }
    }

    /**
     * Обработка скрипта для пользователя.
     *
     * @param $script
     */
    public function processScript($script)
    {
        if($script->send_message_at < now()) return;

        $steps = $this->config['scripts']['clarfication']['steps'];

        /**
         * Получение сообщений.
         */
        $filter = [
            'period' => [Carbon::now()->subDay(1), Carbon::now()->endOfDay()],
            'client' => [
                'clientId' => $script->client_id,
            ],
        ];

        $messages = $this->online_consultant->getMessages($filter);

        if(!empty($steps[$script->step]['is_final'])) {

            $result = $steps[$script->step]['message'];

            $script->delete();

        } else {
            foreach($steps[$script->step]['variants'] as $variant) {
                if (preg_match('/' . $variant['select_message'] . '/iu', $messages)) {
                    $result = $variant['message'];

                    $script->step = $variant['next_step'];

                    $script->save();

                    break;
                }
            }

            if(!empty($result)) {
                $result = $steps['final']['message'];

                $script->delete();
            }
        }

        if(!empty($result)) {

            $this->online_consultant->sendMessage($script->client_id, $result, $this->config['accounts']['default_operator']);

        }
    }

    /**
     * Запуск сценария для пользователя.
     */
    public function sendStartMessageForUser()
    {
        $script = $this->scripts_repository->getNextScriptForUser();

        if(!is_null($script)) return;

        /**
         * Получение сообщений.
         */
        $filter = [
            'period' => [Carbon::now()->subDay(1), Carbon::now()->endOfDay()],
            'client' => [
                'clientId' => $script->client_id,
            ]
        ];

        $messages = $this->online_consultant->getMessages($filter);
        $exceptions = $this->scripts_exception_repository->getAllException();

        $operator_messages = '';
        $client_messages = '';

        foreach($messages as $message) {
            if($message['whoSend'] == 'operator') {
                $operator_messages .= $message['text'];
            } else {
                $client_messages .= $message['text'];
            }
        }

        if (preg_match('/' . $this->config['scripts']['select_message'] . '/iu', $operator_messages)) {

            foreach ($exceptions as $exception) {
                if (preg_match('/(?:' . $exception . ')/iu', $client_messages)) {
                    $stop_word = true;
                    break;
                }
            }

            if (!empty($stop_word)) {
                $result = $this->config['scripts']['notification']['message'];
            }
        }

        if(!empty($result)) {

            $this->online_consultant->sendMessage($script->client_id, $result, $this->config['accounts']['default_operator']);

            $script->step = 1;
            $script->send_message_at = now()->addDay(14);

            $script->save();

        } else {

            /**
             * Удаление сценария.
             */
            $script->delete();
        }
    }

    /**
     * Планирование отложенных сценариев.
     *
     * @param int $clientId
     */
    private function planningPendingScripts($clientId)
    {
        $this->scripts_repository->addRecord($clientId, now()->addHour(3));
    }
}