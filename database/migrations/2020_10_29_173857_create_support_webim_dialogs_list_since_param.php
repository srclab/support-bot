<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSupportWebimDialogsListSinceParam extends Migration
{
    /**
     * @var string
     */
    private $table_name;

    /**
     * CreateSupportScriptsExceptionsTable constructor.
     */
    public function __construct()
    {
        $this->table_name = app_config('support_bot.accounts.webim.dialog_list_since_param_table_name');
    }

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if(!empty($this->table_name)) {
            Schema::create($this->table_name, function (Blueprint $table) {
                $table->increments('id');
                $table->dateTime('period_from');
                $table->dateTime('period_to');
                $table->integer('since');
                $table->integer('last_ts');
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if(!empty($this->table_name)) {
            Schema::dropIfExists($this->table_name);
        }
    }
}
