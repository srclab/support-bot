<?php

namespace SrcLab\SupportBot\Models;

use Illuminate\Database\Eloquent\Model;

class SupportRedirectChatModel extends Model
{
    public $timestamps = false;

    /**
     * SupportRedirectChatModel constructor.
     *
     * @param array $attributes
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        /**
         * Определение названия таблицы.
         */
        $this->table = app_config('support_bot.redirect_chats.table_name');

    }
}