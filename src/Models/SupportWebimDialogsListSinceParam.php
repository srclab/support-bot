<?php

namespace SrcLab\SupportBot\Models;

use Illuminate\Database\Eloquent\Model;

class SupportWebimDialogsListSinceParam extends Model
{
    public $timestamps = false;

    protected $dates = [
        'period_from',
        'period_to'
    ];

    /**
     * SupportScriptModel constructor.
     *
     * @param array $attributes
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        /**
         * Определение названия таблицы.
         */
        $this->table = app_config('support_bot.accounts.webim.dialog_list_since_param_table_name');
    }
}