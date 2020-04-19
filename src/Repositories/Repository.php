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