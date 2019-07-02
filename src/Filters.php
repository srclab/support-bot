<?php

namespace SrcLab\SupportBot;

class Filters
{
    /**
     * Проверка ответа фильтрами.
     *
     * @param string $message
     * @param int $answer_id
     * @return bool
     */
    public function checkFilters($message, $answer_id)
    {
        /**
         * Получение массива включенных фильтров.
         */
        $filters = config('support_bot.answer_filters', []);

        if(empty($filters)) {
            return true;
        }

        /**
         * Проход по фильтрам и их выполнение.
         */
        $check_result = true;

        foreach ($filters as $filter) {

            /**
             * Получение названия фильтра и id ответов.
             */
            preg_match('/([a-z_]*)[:]{0,1}([0-9\,]*)/', $filter, $match_result);

            $filter_name = $match_result[1] ?? '';
            $answer_ids = $match_result[2] ?? [];
            $answer_ids = !empty($answer_ids) ? explode(',', $answer_ids) : [];

            /**
             * Выполнение фильтров.
             */
            switch ($filter_name) {

                case 'order_id':
                    $check_result &= $this->orderIdFilter($message, $answer_id, $answer_ids);
                    break;

                case 'deadline_date':
                    $check_result &= $this->deadlineDateFilter($message, $answer_id, $answer_ids);
                    break;

            }

        }

        return $check_result;
    }

    /**
     * Фильтр по id заказа.
     *
     * @param string $message
     * @param int $answer_id
     * @param array $answer_ids
     * @return bool
     */
    protected function orderIdFilter($message, $answer_id, array $answer_ids)
    {
        /**
         * Поиск вхождения id заказа в сообщение.
         */
        if((empty($answer_ids) || in_array($answer_id, $answer_ids)) && preg_match('/(?:id[\:\s]*[0-9]{5,}|\b[0-9]{7,}\b)/i', $message)) {
            return false;
        }

        return true;
    }

    /**
     * Фильтр по дате выполнения заказа.
     *
     * @param string $message
     * @param int $answer_id
     * @param array $answer_ids
     * @return bool
     */
    protected function deadlineDateFilter($message, $answer_id, array $answer_ids)
    {
        /**
         * Поиск вхождения даты в сообщение.
         */
        if(
            (empty($answer_ids) || in_array($answer_id, $answer_ids))
            && preg_match('/(?:[0-3]?[0-9]\.[0-1][0-9]\.?[0-9]{0,4}|[0-9]{0,2}\s*(?:янв|фев|мар|апр|май|мая|июн|июл|авг|сент|окт|нояб|дек))/iu', $message)
        ) {
            return false;
        }

        return true;
    }

}