<?php

namespace SrcLab\SupportBot\Repositories;

use SrcLab\SupportBot\Models\SupportRedirectChatModel as SupportRedirectChatModel;

class SupportRedirectChatRepository extends Repository
{
    /**
     * SupportRedirectChatRepository constructor.
     */
    public function __construct()
    {
        $this->model = SupportRedirectChatModel::class;
    }

    /**
     * Получение очередной пачки отложенных редиректов.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getNextPart()
    {
        return $this->query()
            ->limit(20)
            ->get();
    }

    //****************************************************************
    //********************** Редактирование **************************
    //****************************************************************

    /**
     * Вставка записи.
     *
     * @param int $client_id
     */
    public function addRecord($client_id)
    {
        $this->query()
            ->insert(compact('client_id'));
    }
}