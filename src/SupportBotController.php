<?php

namespace SrcLab\SupportBot;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use SrcLab\OnlineConsultant\Contracts\OnlineConsultant;

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
        /** @var \SrcLab\OnlineConsultant\Contracts\OnlineConsultant $online_consultant */
        $online_consultant = app(OnlineConsultant::class);

        $post_data = $this->request->getContent();

        if (!empty($post_data)) {
            $post_data = json_decode($post_data, true);

            $result = app(\SrcLab\SupportBot\SupportBot::class)->processWebhook(! empty($secret) ? array_merge($post_data, ['secretKey' => $secret]) : $post_data);

            /**
             * Отправка ответа для удержания чата на боте.
             */
            if($online_consultant->getOnlineConsultantName() == 'webim' && !empty($result)) {
                return response()->json(['result' => 'ok']);
            }
        }
    }
}