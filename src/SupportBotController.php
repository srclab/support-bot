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
     *
     * @param string|null $secret
     */
    public function support_bot($secret = null)
    {
        $config = array_merge(config('support_bot'), app_config('support_bot'));

        $post_data = $this->request->getContent();

        if (!empty($post_data)) {
            $post_data = json_decode($post_data, true);

            $result = app(\SrcLab\SupportBot\SupportBot::class)->processWebhook(! empty($secret) ? array_merge($post_data, ['secretKey' => $secret]) : $post_data);

            if($config['online_consultant'] == 'webim' && $result) {
                return response()->json(['result' => 'ok']);
            }
        }
    }
}