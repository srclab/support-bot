<?php

namespace SrcLab\SupportBot;

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
        $post_data = $this->request->getContent();

        if (!empty($post_data)) {
            $post_data = json_decode($post_data, true);
            app(\SrcLab\SupportBot\SupportBot::class)->processWebhook($post_data);
        }
    }
}