<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSupportBotPendingScriptsAnswersTable extends Migration
{
    /**
     * @var string
     */
    private $table_name;

    /**
     * CreateSupportBotPendingScriptsAnswersTable constructor.
     */
    public function __construct()
    {
        $this->table_name = app_config('support_bot.scripts.answers_table_name');
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
                $table->unsignedBigInteger('client_id');
                $table->integer('answer_option_id');
                $table->text('comment', 32004)->nullable();
                $table->dateTime('created_at');
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
        Schema::dropIfExists($this->table_name);
    }
}
