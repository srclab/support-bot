<?php

namespace SrcLab\SupportBot\Repositories;

use SrcLab\SupportBot\Models\SupportAutoAnsweringModel as SupportAutoAnsweringModel;
use Carbon\Carbon;

class SupportAutoAnsweringRepository extends Repository
{
    /**
     * SupportAutoAnsweringRepository constructor.
     */
    public function __construct()
    {
        $this->model = SupportAutoAnsweringModel::class;
    }

    //****************************************************************
    //************************ Получение *****************************
    //****************************************************************

    /**
     * Получение очередной пачки оповещений на отправку.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getNextSendingPart()
    {
        return $this->query()
            ->where('created_at', '<', Carbon::now()->subSeconds(app_config('support_bot.answering_delay')))
            ->whereNull('send_at')
            ->limit(50)
            ->get();
    }

    /**
     * Получение очередной пачки отложенных оповещений на отправку.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getNextDeferredPart()
    {
        return $this->query()
            ->whereNotNull('send_at')
            ->where('send_at', '<=', Carbon::now())
            ->limit(50)
            ->get();
    }

    //****************************************************************
    //********************** Редактирование **************************
    //****************************************************************

    /**
     * Вставка записи.
     *
     * @param string $client_id
     * @param string $operator
     * @param string $message
     * @param string|Carbon $send_at
     */
    public function addRecord($client_id, $operator, $message, $send_at = null)
    {
        $created_at = Carbon::now();

        $this->query()
            ->insert(compact('client_id', 'operator', 'message', 'created_at', 'send_at'));
    }

    /**
     * Удаление по переданным значениям указанного поля.
     *
     * @param string $column
     * @param array $values
     */
    public function deleteWhereIn($column, array $values)
    {
        $this->query()
            ->whereIn($column, $values)
            ->delete();
    }

    /**
     * Удаление отложенных сообщений клиенту.
     *
     * @param string $client_id
     */
    public function deleteDeferredMessagesByClient($client_id)
    {
        $this->query()
            ->where('client_id', $client_id)
            ->whereNotNull('send_at')
            ->delete();
    }

}
