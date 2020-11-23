<?php

namespace SrcLab\SupportBot\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use SrcLab\SupportBot\Repositories\SupportWebimDialogsListSinceParamRepository;
use SrcLab\SupportBot\Services\Messengers\Webim\Webim;

class SupportWebimDialogsGetParamSinceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Количество раз, которое можно попробовать выполнить задачу.
     *
     * @var int
     */
    public $tries = 1;

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        /**
         * TODO: поробовать сделать больше запросов за раз.
         */
        $config = array_merge(config('support_bot'), app_config('support_bot'));
        $webim = app(Webim::class, ['config' => $config['accounts']]);
        $webim_dialog_list_since_param_repository = app(SupportWebimDialogsListSinceParamRepository::class);

        $requests = 0;
        $last_since_record = $webim_dialog_list_since_param_repository->getLast();

        if(!empty($last_since_record)) {
            $last_since = $last_since_record->last_ts;
        } else {
            $last_since = 0;
        }

        do {

            $result = $webim->getDialogsBySince($last_since);

            if(empty($result['chats']) || count($result['chats']) < 100) {
                break;
            }

            $periods = $webim->getPeriodByDialogs($result['chats']);

            if(!empty($result)) {

                $webim_dialog_list_since_param_repository->addRecord($periods['from'], $periods['to'], $last_since, $result['last_ts']);

                $last_since = $result['last_ts'];

                $requests++;
            } else {
                break;
            }
        } while($result['more_chats_available'] && $requests < 2);
    }
}