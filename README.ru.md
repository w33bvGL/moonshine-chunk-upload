<div align="center">

# w33bvgl/moonshine-chunk-upload

</div>

<div align="center">

  <a href="https://github.com/w33bvGL/moonshine-chunk-upload/actions/workflows/ci.yml">
    <img src="https://github.com/w33bvGL/moonshine-chunk-upload/actions/workflows/ci.yml/badge.svg" alt="CI"/>
  </a>
  <img src="https://img.shields.io/badge/PHP-8.3%2B-777bb4?style=flat-square&logo=php&logoColor=white" alt="PHP 8.3+"/>
  <img src="https://img.shields.io/badge/MoonShine-4-f97316?style=flat-square" alt="MoonShine 4"/>
  <img src="https://img.shields.io/badge/Laravel-11%20%7C%2012%20%7C%2013-ff2d20?style=flat-square&logo=laravel&logoColor=white" alt="Laravel 11 | 12 | 13"/>
  <img src="https://img.shields.io/badge/license-MIT-blue?style=flat-square" alt="MIT"/>

</div>

<p align="center">
  <b>Возобновляемое поле загрузки файлов чанками для MoonShine 4.</b><br/>
  Многогигабайтные файлы без правки <code>upload_max_filesize</code>: файл режется в браузере,
  чанки уходят параллельно и собираются на сервере.
</p>

<p align="center">
  <a href="README.md">🇬🇧 English</a> · 🇷🇺 Русский
</p>

---

## Содержание

- [Ключевое](#ключевое)
- [Установка](#установка)
- [Использование](#использование)
- [Методы поля](#методы-поля)
- [Протокол](#протокол)
- [Что происходит при сабмите формы](#что-происходит-при-сабмите-формы)
- [Безопасность](#безопасность)
- [События](#события)
- [Очистка мусора](#очистка-мусора)
- [Конфигурация](#конфигурация)
- [Разработка](#разработка)
- [Ручная проверка в реальной админке (Docker)](#ручная-проверка-в-реальной-админке-docker)

---

## Ключевое

- **Чанки и параллелизм** — файл режется на клиенте и уходит пулом одновременных
  запросов (`init` → `chunk` × N → `finalize`). Ни `upload_max_filesize`, ни лимит
  тела запроса на прокси не нужно поднимать под весь файл — только под один чанк.
- **Каждый чанк ретраится сам по себе** — оборванное соединение стоит одного чанка
  с экспоненциальной задержкой, а не всей загрузки.
- **Возобновление, в том числе после перезагрузки страницы** — id загрузки живёт в
  `localStorage`; выбрав тот же файл, пользователь дозаливает недостающие чанки,
  а не отправляет заново то, что уже лежит на сервере.
- **Гонки исключены по конструкции** — один файл на чанк, запись во временное имя и
  атомарный `rename`, поэтому параллельные ретраи одного индекса не перемешиваются.
  `finalize` захватывает директорию одним `rename`, так что двойной сабмит
  проигрывает гонку, а не собирает файл дважды.
- **Проверка по зафиксированному плану** — `init` пишет `meta.json`, а дальше клиент
  передаёт только id: ни размер, ни план чанков, ни расширение по ходу не поменять;
  чанк, чья длина не совпадает с заявленной, отклоняется.
- **Полноценное поле MoonShine** — профили расширений, `disk()`/`dir()`, `removable`,
  `keepOriginalFileName()`, `customName()`, ссылка-превью, удаление старого файла
  при замене и при удалении записи.
- **Значение формы нельзя подделать** — в скрытом инпуте лежит путь в staging, и поле
  принимает только тот путь, который выдал сам пакет.
- **Переводы en / ru** и лог по чанкам, включаемый на конкретном поле.

## Установка

```bash
composer require w33bvgl/moonshine-chunk-upload
php artisan vendor:publish --tag=moonshine-chunk-upload-config
php artisan vendor:publish --tag=moonshine-chunk-upload-assets
```

Роуты регистрируются под `/moonshine-chunk-upload/*` **только** с группой `web` —
пакет не знает, какой guard защищает вашу панель. Пропишите свою авторизацию в
опубликованном конфиге до продакшена:

```php
'route' => [
    'prefix' => 'moonshine-chunk-upload',
    'name' => 'moonshine-chunk-upload.',
    'middleware' => ['web', MoonShine\Laravel\Http\Middleware\Authenticate::class, 'throttle:120,1'],
],
```

## Использование

```php
use W33bvgl\MoonShineChunkUpload\Fields\ChunkUpload;

ChunkUpload::make('Видео', 'source_path')
    ->profile('video')
    ->disk('public')
    ->dir('videos')
    ->chunkSize(8 * 1024 * 1024)
    ->concurrency(4)
    ->removable();
```

Это обычное поле MoonShine: добавьте его в `formFields()` ресурса — в колонке
окажется путь до файла, например `videos/9f1c….mp4`.

`profile()` берёт список расширений из конфига (`video`, `audio`, `subtitle`,
`archive`, `image` идут из коробки), `allowedExtensions([...])` сужает список для
конкретного поля.

## Методы поля

| Метод                                          | Что делает                                                       |
|------------------------------------------------|------------------------------------------------------------------|
| `profile(string)`                              | Список расширений из конфига (по умолчанию `video`)              |
| `allowedExtensions(array)`                     | Переопределяет список для этого поля                             |
| `disk(string)` / `dir(string)`                 | Куда файл ляжет после сабмита формы                              |
| `chunkSize(int)`                               | Байт на чанк, обрезается по `max_chunk_size` (по умолчанию 8 МБ) |
| `concurrency(int)`                             | Сколько чанков летит одновременно, 1–8 (по умолчанию 4)          |
| `keepOriginalFileName()`                       | Хранить под очищенным исходным именем, а не под id загрузки      |
| `customName(Closure)`                          | Переименовать файл при переносе на диск поля                     |
| `removable()`                                  | Кнопка удаления (штатный трейт MoonShine)                        |
| `disableDeleteFiles()`                         | Не удалять старый файл при замене и удалении записи              |
| `title()` / `btnText()` / `icon()` / `color()` | Внешний вид дропзоны                                             |
| `debug()`                                      | Показать лог запросов по чанкам под полем                        |

## Протокол

```
POST   /moonshine-chunk-upload/init      {filename, size, total, chunk_size, profile, keep_name} -> {upload_id}
POST   /moonshine-chunk-upload/chunk     ?upload_id&index   (сырое тело)                         -> {received}
GET    /moonshine-chunk-upload/status    ?upload_id                                              -> {received: [...], total}
POST   /moonshine-chunk-upload/finalize  {upload_id}                                             -> {path}
DELETE /moonshine-chunk-upload/abort     ?upload_id                                              -> {status}
```

У каждой загрузки своя временная директория: по файлу на чанк плюс `meta.json`,
записанный на `init`. `status` делает возобновление дешёвым — браузер спрашивает,
какие индексы уже есть, и ставит в очередь только недостающие.

Сборка идёт по файловой системе, поэтому **диск загрузки должен быть локальным**
(`moonshine-chunk-upload.disk`). Диск, на который поле кладёт файл в итоге
(`ChunkUpload::disk()`), может быть любым — на другой диск файл переливается стримом.

## Что происходит при сабмите формы

`finalize` не кладёт файл туда, куда нужно приложению: файл попадает в staging
(`final_dir`), а форма несёт этот путь в скрытом инпуте. При apply поле:

1. отбрасывает значение, если это не финализированная загрузка пакета
   (префикс staging, один сегмент пути, никаких `..`),
2. переносит файл на `disk()`/`dir()`, переименовывая по `keepOriginalFileName()` /
   `customName()` и никогда не перетирая существующий файл,
3. удаляет предыдущий файл (если не выключено `disableDeleteFiles()`),
4. пишет новый относительный путь в колонку.

Пустое значение очищает колонку и удаляет файл; значение, равное текущему, ничего
не меняет; при удалении записи файл тоже удаляется.

## Безопасность

- **Закройте роуты авторизацией.** Дефолтный `['web']` — это заглушка,
  см. [Установку](#установка).
- Расширения проверяются на сервере по профилю на `init`, до приёма первого байта.
- `max_file_size` ограничивает и заявленный размер, и число чанков;
  `max_chunk_size` ограничивает тело запроса, причём превышение обрезается прямо
  во время записи, а не после того, как всё легло на диск.
- Длина каждого чанка должна совпадать с той, что положена его индексу, — собранный
  файл не может превысить заявленный размер.
- Имена файлов с клиента приводятся к одному безопасному сегменту пути.
- Скрытому инпуту нет доверия: принимается только путь, выданный самим пакетом.

## События

`W33bvgl\MoonShineChunkUpload\Events\ChunkUploadCompleted` срабатывает после сборки
файла — до того, как его заберёт поле — и несёт id загрузки, путь в staging и
`UploadMeta`. Это точка входа для транскодинга, антивируса или постановки задач:

```php
Event::listen(function (ChunkUploadCompleted $event): void {
    TranscodeVideo::dispatch($event->path, $event->meta->filename);
});
```

## Очистка мусора

Прерванные загрузки оставляют временные директории, а собранный файл, форму с
которым так и не отправили, остаётся в staging. И то и другое чистится по TTL.
Пакеты не планируют себя сами, поэтому команду вешают на своё расписание:

```php
// routes/console.php
Schedule::command('chunk-upload:prune')->hourly();
```

```bash
php artisan chunk-upload:prune --dry-run          # только отчёт
php artisan chunk-upload:prune --tmp-hours=6      # переопределить TTL
```

## Конфигурация

| Ключ | По умолчанию | Смысл |
|---|---|---|
| `disk` | `local` | Локальный диск, на котором идёт сборка |
| `tmp_dir` / `final_dir` | `chunked-uploads/tmp` / `…/final` | Staging-директории |
| `max_chunk_size` | 16 МБ | Жёсткий лимит на тело одного чанка |
| `max_file_size` | 32 ГБ | Жёсткий лимит на собранный файл |
| `tmp_ttl_hours` | 24 | Возраст, после которого чистятся незавершённые загрузки |
| `final_ttl_hours` | 72 | Возраст, после которого чистятся невостребованные файлы |
| `profiles` | video / audio / subtitle / archive / image | Списки расширений |
| `route` | prefix, name, middleware | Регистрация эндпоинтов |

`max_chunk_size` держите ниже лимита веб-сервера: `client_max_body_size` в nginx и
`post_max_size` в PHP всё ещё действуют на один чанк.

## Разработка

```bash
composer install
composer check   # rector + pint + phpstan + pest

npm install
npm run build    # собирает public/chunk-upload.js, он коммитится в репозиторий
```

Приложению-потребителю Node не нужен — публикуется только собранный
`public/chunk-upload.js` через `php artisan vendor:publish --tag=moonshine-chunk-upload-assets`.
CI падает, если бандл разошёлся с `resources/js`.

## Ручная проверка в реальной админке (Docker)

По тестам не видно главного — параллелизма, ретраев и возобновления. `docker-compose.yml`
поднимает [официальный demo-проект MoonShine](https://github.com/moonshine-software/demo-project),
подключает этот репозиторий как symlink-репозиторий composer типа `path` и кладёт
страницу-песочницу с тремя полями (видео с логом чанков, аудио с исходным именем,
субтитры в один поток) и живым списком того, что лежит на дисках.

```bash
docker compose up --build
```

- Админка: http://localhost:8000/admin — включён автологин, песочница открывается
  сразу, без формы входа.
- Отключите сеть посреди загрузки и посмотрите на ретраи; перезагрузите страницу —
  поле предложит дозалить файл; отправьте форму — файл уедет из staging на диск `public`.

Demo-приложение живёт в томе `sandbox-app` и клонируется один раз; пакет
примонтирован и подключён симлинком, так что правки PHP видны со следующего запроса.
Правки JS требуют `npm run build` на хосте и `docker compose restart`, чтобы бандл
переопубликовался. Снести песочницу: `docker compose down -v`.
