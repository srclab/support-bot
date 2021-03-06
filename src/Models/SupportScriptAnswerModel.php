<?php

namespace SrcLab\SupportBot\Models;

use Illuminate\Database\Eloquent\Model;

class SupportScriptAnswerModel extends Model
{
    public $timestamps = false;

    protected $dates = ['created_at'];

    /**
     * SupportScriptAnswerModel constructor.
     *
     * @param array $attributes
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        /**
         * Определение названия таблицы.
         */
        $this->table = app_config('support_bot.scripts.answers_table_name');
    }
}