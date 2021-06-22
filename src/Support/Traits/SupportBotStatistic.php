<?php

namespace SrcLab\SupportBot\Support\Traits;

use Illuminate\Support\Facades\Log;

trait SupportBotStatistic
{
    /**
     * Отправка сообщения и увеличение статистики.
     *
     * @param int $client_id
     * @param string $message
     * @param null|string $operator
     */
    protected function sendMessageAndIncrementStatistic($client_id, $message, $operator = null)
    {
        if($this->online_consultant->sendMessage($client_id, $message, $operator)) {
            $this->sentMessagesAnalyse();
        }
    }

    /**
     * Отправка сообщения с кнопками и увеличение статистики.
     *
     * @param int $client_id
     * @param array $button_names
     * @param null|string $operator
     */
    protected function sendButtonMessageAndIncrementStatistic($client_id, array $button_names, $operator = null)
    {
        if($this->online_consultant->sendButtonsMessage($client_id, $button_names, $operator)) {
            $this->sentMessagesAnalyse();
        }
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
}