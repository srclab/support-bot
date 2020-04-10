<?php


namespace SrcLab\SupportBot\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class ClearExceptionScriptsCache
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bot:clear_exception_cache';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Удаление кэша слов исключений для скриптов бота';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        /**
         * Удаление кэша слов исключений для скриптов бота.
         */
        Cache::forget('script_exception');
    }
}
