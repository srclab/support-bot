<?php

namespace SrcLab\SupportBot\Models;

use Illuminate\Database\Eloquent\Model;

class SupportScriptModel extends Model
{
    public $timestamps = false;

    protected $dates = [
        'send_message_at',
        'start_script_at'
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
        $this->table = app_config('support_bot.scripts')['table_name'];
    }
}