<?php

namespace SrcLab\SupportBot\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use SrcLab\SupportBot\Jobs\ScriptProcessUserResponsesJob;

class ScriptProcessUserResponses extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'support_bot:script_process_user_responses';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Сбор статистики по ответам пользователей на опрос';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $config = array_merge(config('support_bot'), app_config('support_bot'));

        if(empty($config['scripts']['job_queue'])) {
            Log::error('[SrcLab\SupportBot|Commands] В конфиге отсутствует название очереди для скриптов');
        }

        ScriptProcessUserResponsesJob::dispatch(0)->onQueue($config['scripts']['job_queue']);

    }
}