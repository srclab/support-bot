<?php

namespace SrcLab\SupportBot\Contracts;

interface OnlineConsultant
{
    /**
     * Проверка полученных данных в вебхуке, определение возможности сформировать ответ.
     *
     * @param array $data
     * @return bool
     */
    public function checkWebhookData(array $data);

    /**
     * Проверка фильтра пользователей по id на сайте.
     *
     * @param array $only_user_ids
     * @param array $data
     * @return bool
     */
    public function checkEnabledUserIds(array $only_user_ids, array $data);

    /**
     * Проверка наличия оператора.
     *
     * @param array $data
     * @return bool
     */
    public function checkOperator(array $data);

    /**
     * Отправка сообщения клиенту.
     *
     * @param string $client_id
     * @param string $message
     * @param string $operator
     * @return bool
     */
    public function sendMessage($client_id, $message, $operator = null);

    /**
     * Отправка сообщения с кнопками клиенту.
     *
     * @param string $client_id
     * @param array $button_names
     * @param string $operator
     * @return bool
     */
    public function sendButtonsMessage($client_id, array $button_names, $operator = null);

    /**
     * Проверка секретки.
     *
     * @param string $request_secret
     * @return bool
     */
    public function checkSecret($request_secret);

    /**
     * Получение диалога с клиентом.
     *
     * @param int $client_id
     * @param array $period
     * @return array
     */
    public function getDialogFromClient($client_id, array $period = []);

    /**
     * Получение списка сообщений за период.
     *
     * @param array $period
     * @return array
     */
    public function getDialogsByPeriod(array $period);

    /**
     * Получение сообщений оператора в диалоге с пользователем за период.
     *
     * @param array $period
     * @return array
     */
    public function getOperatorMessages($client_id, array $period);

    /**
     * Получение параметра из данных вебхука.
     *
     * @param string $param
     * @param array $data
     * @return int|null
     */
    public function getParamFromDataWebhook($param, array $data);

    /**
     * Получение параметров диалога.
     *
     * @param string $param
     * @param array $dialog
     * @return mixed
     */
    public function getParamFromDialog($param, array $dialog);

    /**
     * Получение даты и времени последнего сообщения клиента.
     *
     * @param array $dialog
     * @return \Carbon\Carbon|false
     */
    public function getDateTimeClientLastMessage($dialog);

    /**
     * Поиск сообщений оператора.
     *
     * @param array $messages
     * @return array
     */
    public function findOperatorMessages(array $messages);

    /**
     * Поиск сообщения от оператора.
     *
     * @param string $select_message
     * @param array $messages
     * @return int|null
     */
    public function findMessageFromOperator($select_message, array $messages);

    /**
     * Получение сообщений клиента если нет сообщений оператора.
     *
     * @param array $messages
     * @param int $offset
     * @return false|string
     */
    public function getClientMessagesIfNoOperatorMessages(array $messages, $offset = 0);

    /**
     * Поиск сообщений от клиента.
     *
     * @param array $messages
     * @return array
     */
    public function findClientMessages(array $messages);

    /**
     * Проверка отправил ли клиент сообщение после сообщения оператора.
     *
     * @param string $message_text
     * @param array $messages
     * @return bool
     */
    public function isClientSentMessageAfterOperatorMessage($message_text, array $messages);

    /**
     * Получение списка ид операторов онлайн.
     *
     * @return array
     */
    public function getListOnlineOperatorsIds();

    /**
     * Перевод чата на оператора.
     *
     * @param int $client_id
     * @param int $operator_id
     * @return bool
     */
    public function redirectClientToChat($client_id, $operator_id);
}