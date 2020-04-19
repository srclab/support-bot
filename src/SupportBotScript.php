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
            if($script->step == 1) {
                $script->delete();
            } else {
                $this->processScript($script);
            }
        }
    }

    /**
     * Обработка скрипта для пользователя.
     *
     * @param $script
     */
    public function processScript($script)
    {
        if($script->send_message_at > now()) return;

        $steps = $this->config['scripts']['clarfication']['steps'];

        if(empty($steps[$script->step])) return;

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

        $messages = array_shift($messages);

        $messages = $messages['messages'];

        if($script->step == $this->config['scripts']['clarfication']['final_step']) {

            $result = $steps[$this->config['scripts']['clarfication']['final_step']]['message'];

            /**
             * todo раскоментировать
             */
            $script->delete();

        } else {

            if ($script->step == 2) {

                $select_message = '(?:' . preg_replace('/[\x00-\x1F\x7F\s]/', '', $this->config['scripts']['clarfication']['select_message']) . ')';

            } else {

                $select_message = '(?:';

                foreach ($steps[$script->step-1]['variants'] as $key => $variant) {

                    $select_message .= preg_replace('/[\x00-\x1F\x7F\s]/', '', quotemeta($variant['message']));

                    if ($key != array_key_last($steps[$script->step-1]['variants'])) {
                        $select_message .= '|';
                    }
                }

                $select_message .= ')';
            }

            foreach ($messages as $key => $message) {
                if ($message['whoSend'] == 'operator') {

                    if (preg_match('/' . $select_message . '/iu', preg_replace('/[\x00-\x1F\x7F\s]/', '', $message['text']))) {
                        $script_message_id = $key;
                        /**
                         * todo раскоментировать
                         */
                        //break;
                    }
                }
            }

            $client_messages = '';

            if (!empty($script_message_id)) {
                for ($i = ($script_message_id + 1); $i < (array_key_last($messages) + 1); $i++) {

                    if ($messages[$i]['whoSend'] == 'client') {

                        $client_messages .= $messages[$i]['text'];
                    }

                }
            }

            if (!empty($client_messages)) {

                if (!empty($steps[$script->step]['is_final'])) {

                    $result = $steps[$script->step]['message'];

                    /**
                     * todo раскоментировать
                     */
                    $script->delete();

                } else {
                    foreach ($steps[$script->step]['variants'] as $variant) {
                        if (preg_match('/' . $variant['select_message'] . '/iu', $client_messages)) {
                            $result = $variant['message'];

                            if (empty($variant['next_step'])) {
                                $script->step = $this->config['scripts']['clarfication']['final_step'];
                            } else {
                                $script->step++;
                            }

                            $script->save();

                            break;
                        }
                    }

                    if (empty($result)) {
                        $result = $steps[$this->config['scripts']['clarfication']['final_step']]['message'];

                        /**
                         * todo раскоментировать
                         */
                        $script->delete();
                    }
                }

            }
        }

        if(!empty($result)) {

            $this->online_consultant->sendMessage($script->client_id, preg_replace("/\s\s+/", "\n", $result));

        }
    }

    /**
     * Запуск сценария для пользователя.
     */
    public function sendStartMessageForUser()
    {
        $script = $this->scripts_repository->getNextScriptForUser();

        if(is_null($script)) return;

        if($script->step == 0) {

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

            if(!count($messages)) {
                $script->delete();
                return;
            }

            $messages = array_shift($messages);

            $messages = $messages['messages'];

            $exceptions = $this->scripts_exception_repository->getAllException();

            $operator_messages = '';
            $client_messages = '';

            foreach ($messages as $message) {
                if ($message['whoSend'] == 'operator') {
                    $operator_messages .= $message['text'];
                } else {
                    $client_messages .= $message['text'];
                }
            }

            if (preg_match( '/' . $this->config['scripts']['select_message'] . '/iu', $operator_messages)) {

                foreach ($exceptions as $exception) {

                    if (preg_match('/(?:' . $exception->exception . ')/iu', $client_messages)) {
                        $stop_word = true;
                        break;
                    }
                }

                if (empty($stop_word)) {
                    $result = $this->config['scripts']['notification']['message'];
                }
            }

            if(!empty($result)) {

                $script->step = 1;
                /**
                 * todo раскоментировать
                 */
                //$script->send_message_at = now()->addDay(14);

                $script->save();

            }

        } else {

            /**
             * Получение сообщений.
             */
            $filter = [
                'period' => [Carbon::now()->subDay(14), Carbon::now()->endOfDay()],
                'client' => [
                    'clientId' => $script->client_id,
                ],
            ];

            $messages = $this->online_consultant->getMessages($filter);

            $messages = array_shift($messages);

            $client_name = $messages['name'];

            $messages = $messages['messages'];

            foreach($messages as $key=>$message) {
                if ($message['whoSend'] == 'operator') {

                    if (preg_replace('/[\x00-\x1F\x7F\s]/', '', $message['text']) == preg_replace('/[\x00-\x1F\x7F\s]/', '', $this->config['scripts']['notification']['message'])) {
                        $script_message_id = $key;
                        /**
                         * todo раскоментировать
                         */
                        //break;
                    }
                }
            }

            if(!empty($script_message_id)) {
                for ($i = ($script_message_id + 1); $i < (array_key_last($messages) + 1); $i++) {

                    if ($messages[$i]['whoSend'] == 'client') {
                        $is_client_sent_message = true;
                    }

                }
            }

            if(empty($is_client_sent_message)) {
                $result = $this->insertClientNameInString($this->config['scripts']['clarfication']['message'], $client_name);
            }

            if(!empty($result)) {

                $script->step = 2;

                $script->save();

            }

        }

        if(!empty($result)) {

            $this->online_consultant->sendMessage($script->client_id, preg_replace("/\s\s+/", "\n", $result));

        } else {

            /**
             * Удаление сценария.
             */
            $script->delete();
        }
    }

    /**
     * Вставка имени клиента в строку.
     *
     * @param string $string
     * @param string $client_name
     * @return string
     */
    private function insertClientNameInString($string, $client_name)
    {
        return str_replace(':client_name', $client_name, $string);
    }

    /**
     * Планирование отложенных сценариев.
     *
     * @param int $clientId
     */
    private function planningPendingScripts($clientId)
    {
        $this->scripts_repository->addRecord($clientId, now());
    }
}