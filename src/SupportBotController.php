<?php

namespace Vsesdal\SupportBot;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

class SupportBotController extends BaseController
{
    /**
     * @var \Illuminate\Http\Request
     */
    private $request;

    /**
     * WebhookController constructor.
     *
     * @param \Illuminate\Http\Request $request
     */
    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    /**
     * Обработка вебхука.
     */
    public function support_bot()
    {
        /**
         * Если бот отключен.
         */
        if(!config('support_bot.enabled')) {
            return;
        }

        $post_data = $this->request->getContent();

        if (!empty($post_data)) {
            $post_data = json_decode($post_data, true);
            app(\Vsesdal\SupportBot\SupportBot::class)->processWebhook($post_data);
        }
    }
}