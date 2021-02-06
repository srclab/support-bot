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
            ->limit(10)
            ->get();
    }

    /**
     * Проверка существует ли запись о редиректе для чата.
     *
     * @param $chat_id
     * @return mixed
     */
    public function isExistRecord($chat_id)
    {
        return $this->query()
            ->where('client_id', $chat_id)
            ->exists();
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