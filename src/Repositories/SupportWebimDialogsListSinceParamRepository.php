<?php

namespace SrcLab\SupportBot\Repositories;

use Carbon\Carbon;
use SrcLab\SupportBot\Models\SupportWebimDialogsListSinceParam as SupportWebimDialogsListSinceParamModel;

class SupportWebimDialogsListSinceParamRepository extends Repository
{
    /**
     * SupportWebimDialogsListSinceParamRepository constructor.
     */
    public function __construct()
    {
        $this->model = SupportWebimDialogsListSinceParamModel::class;
    }

    //****************************************************************
    //************************ Получение *****************************
    //****************************************************************

    /**
     * Получение последней записи.
     *
     * @return mixed
     */
    public function getLast()
    {
        return $this->query()->orderByDesc('id')->first();
    }

    /**
     * Получение записей за период.
     *
     * @param string $from
     * @param strign $to
     * @return \Illuminate\Database\Eloquent\Builder[]|\Illuminate\Database\Eloquent\Collection
     */
    public function getByPeriod($from, $to)
    {
        return $this->query()->where('period_from', '>=', $from)->where('period_to', '<=', $to)->get();
    }

    //****************************************************************
    //********************** Редактирование **************************
    //****************************************************************

    /**
     * Вставка записи.
     *
     * @param string $period_from
     * @param string $period_to
     * @param int $since
     * @param int $last_ts
     */
    public function addRecord($period_from, $period_to, $since, $last_ts)
    {
        $this->query()
            ->insert(compact('period_from', 'period_to', 'since', 'last_ts'));
    }
}