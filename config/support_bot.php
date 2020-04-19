<?php

return [

    /*
     |--------------------------------------------------------------------------
     | Активен ли бот
     |--------------------------------------------------------------------------
     |
     | Не влияет на работу автоответчика. Автоответчик регулируется отдельно.
     |
     */
    'enabled' => env('SRCLAB_SUPPORT_BOT_ENABLED', true),

    /*
     |--------------------------------------------------------------------------
     | Настройка автоответчика
     |--------------------------------------------------------------------------
     |
     | enabled - включен ли автоответчик
     | period_begin и period_end временные границы работы автооветчика
     |
     */
    'auto_responder' => [
        'enabled' => env('SRCLAB_SUPPORT_BOT_AUTO_RESPONDER_ENABLED', true),
        'period_begin' => '00:00',
        'period_end' => '07:59',
        'message' => 'Здравствуйте! 🤗Благодарим за обращение в службу поддержки. К сожалению, мы сейчас не можем вам ответить. Но обязательно свяжемся с вами до 9 часов утра. 😉'
    ],

    /*
     |--------------------------------------------------------------------------
     | Бот активен только для указанных id (id пользователей на конкретном сайте)
     |--------------------------------------------------------------------------
     |
     | Оставить пустым для автоответа всем пользователям.
     |
     */
    'enabled_for_user_ids' => [],

    /*
     |--------------------------------------------------------------------------
     | Название таблицы бд для очереди сообщений
     |--------------------------------------------------------------------------
     |
     | Создание таблицы очереди. Требуется для задержки ответа на сообщение клиента
     | или для отложенной отправки сообщений.
     |
     */
    'table_name' => 'support_auto_answering_queue',

    /*
     |--------------------------------------------------------------------------
     | Настройки отложенных сценариев для клиентов
     |--------------------------------------------------------------------------
     |
     | Отложеные сценарии для клиента используются когда необходима работа с
     | клиентом после его обращения в поддержку. Для уточнения смог ли
     | пользователь справиться с размещением заказа после ответа менеджера.
     |
     */
    'scripts' => [
        'table_name' => 'support_pending_sripts',
        'exceptions_table_name' => 'support_pending_exceptions',
        'select_message' => '(?:https:\/\/vse-sdal.com\/promo|https:\/\/vse-sdal.com\/onlajn-pomoshch|http:\/\/taplink.cc\/vsesdal_official)',
        'notification' => [
            'message' => 'Здравствуйте!🤗  Не так давно вы обращались к нам для выполнения задания
                          Удалось ли вам разместить заказ на сайте?
                          Если нет, мы можем помочь вам с размещением заказа – напишите нам',
        ],
        'clarfication' => [
            'message' => 'Добрый день! Помните про нас? ;)
                          :client_name, недавно вы обращались в поддержку с вопросом. Скажите пожалуйста, удалось ли вам разместить заказ на сайте?',
            'select_message' => '(?:недавно вы обращались в поддержку с вопросом. Скажите пожалуйста, удалось ли вам разместить заказ на сайте?)',
            'final_step' => 5,
            'steps' => [
                2 => [
                    'variants' => [
                        [
                            'message' => 'Была ли работа выполнена в срок и без замечаний?',
                            'select_message' => '(?:Да)',
                        ],
                        [
                            'message' => 'Поделитесь причиной. Это позволит сделать сервис «Всё сдал!» лучше
                                         Выберите один вариант ответа из списка:
                                         1) нашел откликнувшегося исполнителя и стали работать вне сайта;
                                         2) не нравится интерфейс;
                                         3) исполнители, которые откликнулись, не подходят для задачи;
                                         4) заказал в другом месте;
                                         5) другое (поясните ответ).',
                            'select_message' => '(?:НЕТ|НЕ РАЗМЕЩАЛ)',
                            'next_step' => true,
                        ]
                    ],
                ],
                3 => [
                    'variants' => [
                        [
                            'message' => 'А сколько дней на выполнение вы закладывали?',
                            'select_message' => '(?:дорого)',
                            'next_step' => true,
                        ]
                    ],
                ],
                4 => [
                    'message' => 'Возможно причина в том, что было мало времени на выполнение или наши эксперты были загружены. 
                                          Но выход есть. На нашем сайте много проверенных авторов https://vse-sdal.com/allauthors. Вам будет из чего выбрать 
                                          Разместите, пожалуйста, заказ и напишите нам. А мы бесплатно подключим услугу "Рекомендуемый заказ". Это часто помогает заказать работу дешевле.',
                    'is_final' => true,
                ],
                5 => [
                    'message' => 'Спасибо за то что уделили время, обращайтесь!',
                ]
            ]
        ],
    ],

    /*
     |--------------------------------------------------------------------------
     | Отложенная отправка вопроса "Чем могу помочь?" после приветствия
     |--------------------------------------------------------------------------
     |
     */
    'deferred_answer_after_welcome' => false,

    /*
     |--------------------------------------------------------------------------
     | Режим отправки автоответа
     |--------------------------------------------------------------------------
     |
     | sync - мгновенная отправка ответа
     | queue - отправка ответа с задержкой
     |
     | Режим queue требует добавление задачи в крон с выполнением метода \SrcLab\SupportBot\SupportBot::sendAnswers
     | раз в минуту.
     |
     */
    'answering_mode' => 'sync',

    /*
     |--------------------------------------------------------------------------
     | Время задержки автоответа в секундах (требуется answering_mode = queue)
     |--------------------------------------------------------------------------
     |
     | Внимание: ввиду отправки автоответов из очереди раз в 1 минуту в кроне, образует погрешность.
     | Например, при установке задержки 30 секунд, автоответ будет отправлен не менее чем через 30 секунд
     | и не более чем через 90 секунд после получения информации о новом сообщении через вебхук.
     |
     */
    'answering_delay' => '15',

    /*
     |--------------------------------------------------------------------------
     | Период активности бота
     |--------------------------------------------------------------------------
     |
     | В случе некорректного указания периода бот активен круглые сутки.
     | active_period = null - бот активен круглые сутки
     |
     */
    'active_period' => [
        'day_beginning' => '08:00',
        'day_end' => '23:59',
    ],

    /*
     |--------------------------------------------------------------------------
     | Анализ количества отправленных сообщений ботом
     |--------------------------------------------------------------------------
     |
     | enabled - функция включена
     | cache_days - количество дней, которое будет хранится в кеше количество отправленных сообщений за день
     |
     */
    'sent_messages_analyse' => [
        'enabled' => true,
        'cache_days' => 7,
    ],

    /*
     |--------------------------------------------------------------------------
     | Используемый онлайн-консультант
     |--------------------------------------------------------------------------
     |
     | Пока что доступен только talk_me
     |
     */
    'online_consultant' => 'talk_me',

    /*
     |--------------------------------------------------------------------------
     | Настройки подключений к сторонним api
     |--------------------------------------------------------------------------
     |
     |
     */
    'accounts' => [

        'talk_me' => [
            'api_token' => env('SRCLAB_SUPPORT_BOT_TALK_ME_API_TOKEN', ''),
            'webhook_secret' => env('SRCLAB_SUPPORT_BOT_TALK_ME_WEBHOOK_SECRET', ''),
            'default_operator' => env('SRCLAB_SUPPORT_BOT_TALK_ME_DEFAULT_OPERATOR', ''),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Сообщение приветствия
    |--------------------------------------------------------------------------
    |
    | Используется для определения необходимости добавления приветствия в ответы.
    |
    */
    'greeting_phrase' => 'Здравствуйте 🤗',

    /*
    |--------------------------------------------------------------------------
    | Ответы без приветствия
    |--------------------------------------------------------------------------
    |
    | К ответам из этого массива не будет добавлять фраза приветствия
    | ни прикаких условиях.
    |
    */
    'answers_without_greeting' => [],

    /*
    |--------------------------------------------------------------------------
    | Фильтры ответов на сообщения.
    |--------------------------------------------------------------------------
    |
    | Указание фильтров опционально, оставить массив пустым, чтобы не применять никаких фильтров.
    | Доступные фильтры:
    | order_id - фильтр по id заказа (не запрашивать id заказа, если он был указан в обращении)
    | deadline_date - фильтр по дате готовности заказа (не запрашивать дату готовности, если она уже была указана в обращении)
    |
    | Для указания фильтрации конкретных ответов перечислить порядковые номера ответов через запятую после двоеточия.
    | Например - order_id:0,1,2
    */
    'answer_filters' => [],

    /*
    |--------------------------------------------------------------------------
    | Массив автоответов
    |--------------------------------------------------------------------------
    |
    | Формат [$question => $answer]
    | $question можно задавать в виде регулярного выражения (без использования /.../i)
    |
    */
    'auto_answers' => [
        'Тестовое обращение' => 'Тестовый ответ',
    ],

];