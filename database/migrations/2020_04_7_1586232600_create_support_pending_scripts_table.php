<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateSupportPendingScriptsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create(app_config('support_bot.scripts')['table_name'], function (Blueprint $table) {
            $table->increments('id');
            $table->string('client_id', 32);
            $table->unsignedInteger('step');
            $table->dateTime('send_first_message_at');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists(app_config('support_bot.scripts')['table_name']);
    }

}
