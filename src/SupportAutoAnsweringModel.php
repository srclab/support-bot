<?php

namespace Vsesdal\SupportBot;

use Illuminate\Database\Eloquent\Model;

class SupportAutoAnsweringModel extends Model
{
    public $timestamps = false;

    protected $dates = ['created_at'];

    /**
     * SupportAutoAnsweringModel constructor.
     *
     * @param array $attributes
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        /**
         * Определение названия таблицы.
         */
        $this->table = config('support_bot.table_name');

    }

}
