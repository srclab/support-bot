<?php


namespace SrcLab\SupportBot\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class ClearExceptionScriptsCache extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'support_bot:clear_exception_cache';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Удаление кэша слов исключений для скриптов бота';

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
