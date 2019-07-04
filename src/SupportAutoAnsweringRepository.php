<?php

namespace SrcLab\SupportBot;

use SrcLab\SupportBot\SupportAutoAnsweringModel as SupportAutoAnsweringModel;
use Carbon\Carbon;

class SupportAutoAnsweringRepository
{
    /**
     * @var \Illuminate\Database\Eloquent\Model
     */
    protected $model;

    /**
     * SupportAutoAnswering constructor.
     */
    public function __construct()
    {
        $this->model = SupportAutoAnsweringModel::class;
    }

    /**
     * Получить Builder объект модели.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function query()
    {
        return $this->model::query();
    }

    //****************************************************************
    //************************ Получение *****************************
    //****************************************************************

    /**
     * Получние очередной пачки оповещений на отправку.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getNextSendingPart()
    {
        return $this->query()
            ->where('created_at', '<', Carbon::now()->subSecond(app_config('support_bot.answering_delay')))
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
     */
    public function addRecord($client_id, $operator, $message)
    {
        $created_at = Carbon::now();

        $this->query()
            ->insert(compact('client_id', 'operator', 'message', 'created_at'));
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

}
