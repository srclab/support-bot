<?php


namespace SrcLab\SupportBot\Repositories;

class Repository
{
    /**
     * @var \Illuminate\Database\Eloquent\Model
     */
    protected $model;

    /**
     * Получить Builder объект модели.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function query()
    {
        return $this->model::query();
    }
}