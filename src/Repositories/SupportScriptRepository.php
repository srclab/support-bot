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
                'step' => 0
            ])
            ->limit(20)
            ->get();
    }

    /**
     * Получение очередной пачки отложенных завершенных сценариев.
     *
     * @param int $offset
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getNextCompletedScripts($offset = 0)
    {
        return $this->query()
            ->where([
                'step' => -1,
            ])
            ->offset($offset)
            ->limit(5)
            ->get();
    }

    /**
     * Получение очередной пачки скриптов без ответа.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getNextUnansweredScripts()
    {
        $chat_closing_time = config('support_bot.scripts.chat_closing_time') ?? 24;

        return $this->query()
            ->where([
                'user_answered' => false,
                ['start_script_at', '<', Carbon::now()->subHours($chat_closing_time)],
            ])
            ->get();
    }

    /**
     * Получение скриптов недельной давности.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getNextScriptsWeekAgo()
    {
        return $this->query()
            ->whereNotNull('start_script_at')
            ->where('start_script_at', '<', Carbon::now()->subWeeks(1))
            ->limit(20)
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
