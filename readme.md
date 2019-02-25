# Бот тех. поддержки для отправки атоответов на популярные фразы клиетов

### Установка

```
 $ composer require vsesdal/support-bot

 $ php artisan vendor:publish --provider="Vsesdal\SupportBot\SupportBotServiceProvider"
```

При использовании режима с очердями сообещений необходимой выполнить миграции
и добавить задачу в крон (более подробно в разделе Настройка).
Опционально можно изменить дефолтное название создаваемой таблицы в бд
в конфигурационном файле support_bot.php.

```
 $ php artisan migrate
```

### Настройка

Конфигурационный файл support_bot.php предоставляет возможности для
гибкой настройки бота поддержки.

Имеется два режима работы: синхронный и с очередями сообщений.

В синхронном режиме, автоответ отправляется мгновенно после получения вебхука
о новом сообщении от клиента.

В режиме с использованием очередей используется таблица в бд для формировании
очереди автоответов и задача в кроне для отправки автоответов с задержкой.

Конфигурационный файл содержит всю необходимую для настройки информацию.