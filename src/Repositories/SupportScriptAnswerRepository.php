<?php

namespace SrcLab\SupportBot\Repositories;

use Illuminate\Support\Facades\DB;
use SrcLab\SupportBot\Models\SupportScriptAnswerModel;

class SupportScriptAnswerRepository extends Repository
{
    /**
     * SupportScriptAnswerRepository constructor.
     */
    public function __construct()
    {
        $this->model = SupportScriptAnswerModel::class;
    }

    /**
     * Проверка выбирал ли пользователь указанный вариант ответа нв вопрос.
     *
     * @param int $client_id
     * @param int $variant_id
     * @return bool
     */
    public function isUserAnswered($client_id, $variant_id)
    {
        return $this->query()
            ->where([
                'client_id' => $client_id,
                'answer_option_id' => $variant_id,
            ])
            ->exists();
    }

    /**
     * Получение записи о выборе указанного варианта ответа пользователем.
     *
     * @param int $client_id
     * @param int $variant_id
     * @return \SrcLab\SupportBot\Models\SupportScriptAnswerModel
     */
    public function getUserAnswer($client_id, $variant_id)
    {
        return $this->query()
            ->where([
                'client_id' => $client_id,
                'answer_option_id' => $variant_id,
            ])
            ->first();
    }

    /**
     * Получение ответа пользователя.
     *
     * @param int $client_id
     * @return \SrcLab\SupportBot\Models\SupportScriptAnswerModel
     */
    public function getLastUserAnswer($client_id)
    {
        return $this->query()
            ->where('client_id', $client_id)
            ->latest()
            ->first();
    }

    /**
     * Получение комментариев к ответу за период.
     *
     * @param int $answer_option_id
     * @param string $period_begin
     * @param string $period_end
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getCommentsOnAnswerOptionForPeriod($answer_option_id, $period_begin, $period_end)
    {
        return $this->query()
            ->where([
                'answer_option_id' => $answer_option_id,
                ['created_at', '>=', $period_begin],
                ['created_at', '<=', $period_end],
            ])
            ->whereNotNull('comment')
            ->get();
    }

    /**
     * Получение количества ответов с группировкой по вариантам ответов за период.
     *
     * @param string $period_begin
     * @param string $period_end
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAnswerCountByOptionAnswersForPeriod($period_begin, $period_end)
    {
        return $this->query()
            ->where([
                ['created_at', '>=', $period_begin],
                ['created_at', '<=', $period_end],
            ])
            ->groupBy('answer_option_id')
            ->get(['answer_option_id', DB::raw('count(*) as total')]);
    }
}