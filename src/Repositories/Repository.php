<?php

namespace SrcLab\SupportBot\Repositories;

abstract class Repository
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

    /**
     * Получить все записи.
     *
     * @param  array $columns
     * @return \Illuminate\Database\Eloquent\Collection|static[]
     */
    public function getAll($columns = ['*'])
    {
        if (empty($columns)) {
            $columns = ['*'];
        }

        $where = $this->getExtendedWhere();

        if (!empty($where)) {
            return $this->query()->where($where)->get($columns);
        } else {
            return $this->query()->get($columns);
        }
    }

    /**
     * Поиск записи по условиям.
     *
     * @param  array $where
     * @param  array $columns
     * @return mixed
     */
    public function findBy(array $where, $columns = ['*'])
    {
        if (empty($columns)) {
            $columns = ['*'];
        }

        return $this->query()->where(array_merge($where, $this->getExtendedWhere()))->first($columns);
    }

    /**
     * Получение доп условия для запроса.
     *
     * @return array
     */
    protected function getExtendedWhere()
    {
        return [];
    }
}