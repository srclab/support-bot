<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateSupportScriptsExceptionsTable extends Migration
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
        $this->table_name = app_config('support_bot.scripts.exceptions_table_name');
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
                $table->string('exception');
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
            Schema::dropIfExists(app_config('support_bot.scripts.exceptions_table_name'));
        }
    }

}
