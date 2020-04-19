<?php

namespace SrcLab\SupportBot\Repositories;

use SrcLab\SupportBot\Models\SupportScriptModel as SupportScriptModel;
use Carbon\Carbon;

class SupportScriptRepository extends Repository
{
    /**
     * SupportScriptRepository constructor.
     */
    public function __construct()
    {
        $this->model = SupportScriptModel::class;
    }

    //****************************************************************
    //************************ Получение *****************************
    //****************************************************************

    /**
     * Получение очередного отложенного сценария.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getNextScriptForUser()
    {
        return $this->query()
            ->where('send_message_at', '<', Carbon::now())
            ->whereIn('step', [0, 1])
            ->first();
    }


    //****************************************************************
    //********************** Редактирование **************************
    //****************************************************************

    /**
     * Вставка записи.
     *
     * @param int $client_id
     * @param string|Carbon $send_message_at
     * @param int $step
     */
    public function addRecord($client_id, $send_message_at, $step = 0)
    {
        $this->query()
            ->insert(compact('client_id', 'step', 'send_message_at'));
    }

}