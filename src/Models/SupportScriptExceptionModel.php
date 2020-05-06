<?php

namespace SrcLab\SupportBot\Models;

use Illuminate\Database\Eloquent\Model;

class SupportScriptExceptionModel extends Model
{
    public $timestamps = false;

    /**
     * SupportScriptExceptionModel constructor.
     *
     * @param array $attributes
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        /**
         * Определение названия таблицы.
         */
        $this->table = app_config('support_bot.scripts')['exceptions_table_name'];
    }
}