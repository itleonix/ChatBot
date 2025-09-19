# Bot API (PHP) — описание и настройка

Этот скрипт (`bot_api.php`) реализует простой HTTP‑эндпоинт (POST, JSON), который:
- принимает сообщение пользователя,
- добавляет его в историю диалога,
- при необходимости подмешивает указанный файл как вложение,
- запрашивает ответ у модели GigaChat,
- возвращает ответ и статистику токенов в JSON.

## Требования
- PHP 8.1+
- Composer‑зависимости: `guzzlehttp/guzzle`, `ramsey/uuid`
  - автозагрузка ожидается по пути `../vendor/autoload.php` относительно `bot_api.php`
- Доступ в сеть к хостам GigaChat

## Как это работает
- История диалога хранится:
  - в APCu (если доступна) с TTL 1 час,
  - и дублируется во временный файл (в системной `temp` директории).
- Перед отправкой в модель история «мягко» и «жестко» подрезается по количеству сообщений и длине,
  при этом системный промпт принудительно держится первым сообщением.
- Доступ к API GigaChat осуществляется по OAuth 2.0. Токен кешируется в APCu и файле.
- При включении опции вложений указанный файл загружается в GigaChat и его `fileId` кешируется локально.

## Переменные окружения (конфигурация)
- GIGA_API_BASE — базовый URL GigaChat API (по умолчанию `https://gigachat.devices.sberbank.ru`).
- GIGA_OAUTH_BASE — базовый URL OAuth (по умолчанию `https://ngw.devices.sberbank.ru:9443/`).
- GIGA_MODEL — идентификатор модели (по умолчанию `GigaChat-2-Pro`).
- GIGA_CA_BUNDLE — путь к CA‑bundle сертификату (опционально). Если не задан, используется системный стор.
- GIGA_CLIENT_ID — OAuth client_id (обязательно).
- GIGA_CLIENT_SECRET — OAuth client_secret (обязательно).
- GIGA_ATTACH_FILE — путь к файлу, который нужно подмешивать (опционально).
- GIGA_ATTACH_ALWAYS — `true/false` — подмешивать файл в каждый запрос (по умолчанию `false`).
- GIGA_SYSTEM_PROMPT — текст системного промпта (опционально).
- GIGA_SYSTEM_PROMPT_FILE — путь к файлу с промптом (приоритетнее, чем переменная).


## Пример запуска (веб‑сервер)
Разместите `bot_api.php` под любым веб‑сервером (Apache/Nginx/PHP‑FPM) и передайте переменные окружения через конфиг сервера или `systemd`.

## Пример запроса
```
POST /bot_api.php
Content-Type: application/json

{
  "message": "Привет! Расскажи о возможностях."
}
```

## Пример ответа
```
{
  "response": "…текст ответа…",
  "session_id": "…UUID…",
  "usage": { "prompt_tokens": 0, "completion_tokens": 0, "total_tokens": 0, "precached_prompt_tokens": 0 },
  "cached_prompt_tokens": 0,
  "attachments_used": ["file_…"]
}
```

