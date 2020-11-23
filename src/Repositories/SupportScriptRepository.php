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
     * Получение очередной пачки отложенных сценариев.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getNextScripts()
    {
        return $this->query()
            ->where([
                ['send_message_at', '<', Carbon::now()],
                ['step', 0]
            ])
            ->limit(20)
            ->get();
    }

    /**
     * Получение очередной пачки скриптов без ответа.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getNextUnansweredScripts()
    {
        /**
         * TODO: после проверки для карбона установить Carbon::now()->subDays(1)
         */
        return $this->query()
            ->where([
                ['user_answered', false],
                ['start_script_at', '<', Carbon::now()->subMinute(30)],
            ])
            ->get();
    }


    //****************************************************************
    //********************** Редактирование **************************
    //****************************************************************

    /**
     * Вставка записи.
     *
     * @param int $search_id
     * @param string|Carbon $send_message_at
     * @param int $step
     */
    public function addRecord($search_id, $send_message_at, $step = 0)
    {
        $this->query()
            ->insert(compact('search_id', 'step', 'send_message_at'));
    }

}
