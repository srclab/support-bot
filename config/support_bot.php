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
        'period_begin' => '22:02',
        'period_end' => '07:58',
        'message' => 'Здравствуйте! 🤗 Благодарим за обращение в службу поддержки. К сожалению, мы сейчас не можем вам ответить. Но обязательно напишем вам до 9 часов утра 😉'
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
     | Перенаправление чатов
     |--------------------------------------------------------------------------
     |
     | table_name - название таблицы с отложенными редиректами чата
     | message_not_working_hours - сообщение при попытке перенаправления в нерабочее время
     | message_not_operators - сообщение при попытке перенаправления при отсуствии операторов
     | working_hours.period_begin - начало рабочего времени операторов
     | working_hours.period_end - конец рабочего времени операторов
     | except_operators_ids - не переводить на операторов с ид
     |
     */
    'redirect_chats' => [
        'table_name' => 'support_bot_redirect_chats',
        'message_not_working_hours' => 'К сожалению, мы сейчас не можем вам ответить. Но обязательно напишем вам до 9 часов утра 😉',
        'message_not_operators' => 'К сожалению, мы сейчас не можем вам ответить. Но обязательно напишем вам как только появятся свободные операторы',
        'working_hours' => [
            'period_begin' => '10:00',
            'period_end' => '22:00',
        ],
        'except_operators_ids' => [],
    ],

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
        'enabled' => env('SRCLAB_SUPPORT_BOT_SCRIPT_ENABLED', false),
        'table_name' => 'support_bot_pending_scripts',
        'exceptions_table_name' => 'support_bot_pending_exceptions',
        'select_message' => '(?:https:\/\/vse-sdal.com\/promo|https:\/\/vse-sdal.com\/onlajn-pomoshch|http:\/\/taplink.cc\/vsesdal_official)',
        'enabled_for_user_ids' => [],
        'send_notification_period' => [
            'period_begin' => '10:00',
            'period_end' => '22:00',
        ],
        'clarification' => [
            'message' => ':client_name, cкажите пожалуйста, удалось ли вам разместить заказ на сайте?',
            'final_step' => 3,
            'steps' => [
                1 => [
                    'variants' => [
                        [
                            'messages' => [
                                'Спасибо что воспользовались нашим сервисом! Мы всегда рады помочь вам 🤗',
                            ],
                            'button' => 'Да, спасибо',
                            'is_final' => true,
                        ],
                        [
                            'for_operator' => true,
                            'button' => 'Мне нужна помощь с размещением заказа'
                        ],
                        [
                            'messages' => [
                                'Поделитесь причиной. Это позволит сделать сервис «Всё сдал!» лучше 😉',
                            ],
                            'button' => 'Нет',
                            'next_step' => 2,
                        ],
                    ]
                ],

                2 => [
                    'variants' => [
                        [
                            'messages' => [
                                'Мы постоянно расширяем нашу базу. Пожалуйста, укажите предмет/задачу для которой не нашлось исполнителеля',
                            ],
                            'button' => 'Не нашлось подходящего исполнителя',
                        ],
                        [
                            'messages' => [
                                'Спасибо за то что уделили время, обращайтесь!',
                            ],
                            'button' => 'Исполнитель откликнулся и мы стали работать вне сайта',
                            'is_final' => true,
                        ],
                        [
                            'messages' => [
                                'Возможно причина в том, что было мало времени на выполнение или наши эксперты были загружены 🤔. Но выход есть. На нашем сайте много проверенных авторов https://vse-sdal.com/allauthors. Вам будет из чего выбрать 🤗',
                                'Разместите, пожалуйста, заказ и напишите нам. А мы бесплатно подключим услугу "Рекомендуемый заказ". Это часто помогает заказать работу дешевле 😎',
                            ],
                            'button' => 'Слишком дорого',
                            'is_final' => true,
                        ],
                        [
                            'messages' => [
                                'Пожалуйста  укажите,  что необходимо изменить/добавить на сайт?',
                            ],
                            'button' => 'Не понравился интерфейс',
                        ],
                        [
                            'messages' => [
                                'Сможете указать причину?',
                            ],
                            'button' => 'Заказал(а) работу в другом месте'
                        ]
                    ]
                ],

                3 => [
                    'messages' => [
                        'Спасибо за то что уделили время, мы обязательно учтём ваши пожелания, обращайтесь!',
                    ],
                    'is_final' => true,
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
        'day_beginning' => '07:59',
        'day_end' => '22:01',
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
     | talkme, webim
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
        'webim' => [
            'api_token' => env('SRCLAB_SUPPORT_BOT_WEBIM_API_TOKEN', ''),
            'login' => env('SRCLAB_SUPPORT_BOT_WEBIM_LOGIN', ''),
            'password' => env('SRCLAB_SUPPORT_BOT_WEBIM_PASSWORD', ''),
            'webhook_secret' => env('SRCLAB_SUPPORT_BOT_WEBIM_WEBHOOK_SECRET', ''),
            'bot_operator_name' => env('SRCLAB_SUPPORT_BOT_WEBIM_BOT_OPERATOR_NAME', ''),
            'bot_operator_id' => env('SRCLAB_SUPPORT_BOT_WEBIM_BOT_OPERATOR_ID', ''),
            'dialog_list_since_param_table_name' => 'support_webim_dialogs_list_since_param',
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