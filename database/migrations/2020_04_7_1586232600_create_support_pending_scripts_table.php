<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateSupportPendingScriptsTable extends Migration
{
    /**
     * @var string
     */
    private $table_name;

    /**
     * CreateSupportPendingScriptsTable constructor.
     */
    public function __construct()
    {
        $this->table_name = app_config('support_bot.scripts.table_name');;
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
                $table->unsignedBigInteger('search_id');
                $table->tinyInteger('step')->default(0);
                $table->tinyInteger('prev_step')->nullable();
                $table->dateTime('send_message_at');
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
            Schema::dropIfExists(app_config('support_bot.scripts.table_name'));
        }
    }

}
