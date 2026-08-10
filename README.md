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
  <b>Resumable, parallel chunked file upload field for MoonShine 4.</b><br/>
  Multi-gigabyte files without touching <code>upload_max_filesize</code>: sliced in the browser,
  uploaded as concurrent independently-retryable chunks, assembled server-side.
</p>

<p align="center">
  🇬🇧 English · <a href="README.ru.md">🇷🇺 Русский</a>
</p>

---

## Table of contents

- [Highlights](#highlights)
- [Installation](#installation)
- [Usage](#usage)
- [Field reference](#field-reference)
- [The protocol](#the-protocol)
- [What the field does on submit](#what-the-field-does-on-submit)
- [Security](#security)
- [Events](#events)
- [Pruning](#pruning)
- [Configuration](#configuration)
- [Development](#development)
- [Manual QA in a real admin panel (Docker)](#manual-qa-in-a-real-admin-panel-docker)

---

## Highlights

- **Chunked and parallel** — the file is sliced client-side and pushed through a
  small pool of concurrent requests (`init` → `chunk` × N → `finalize`). Neither
  `upload_max_filesize` nor a proxy body limit has to be raised for the whole
  file — only for a single chunk.
- **Every chunk retries on its own** — a dropped connection costs one chunk with
  exponential backoff, not the upload.
- **Resumable, including across a page reload** — the upload id is mirrored into
  `localStorage`; re-picking the same file continues from the last acknowledged
  chunk instead of re-sending what the server already holds.
- **Race-free by construction** — one part file per chunk, written under a
  private name and renamed into place, so parallel retries of the same index
  cannot interleave. Finalize claims the directory with a single atomic rename,
  so a double submit loses the race instead of assembling the parts twice.
- **Validated against a frozen plan** — `init` writes a `meta.json` and every
  later request carries nothing but an upload id: the declared size, chunk plan
  and extension cannot be grown or swapped halfway through, and a chunk whose
  body does not match its declared length is rejected.
- **A real MoonShine field** — extension profiles, `disk()`/`dir()`, removable,
  `keepOriginalFileName()`, `customName()`, preview link, old-file cleanup on
  replace and on record delete.
- **Tamper-proof form value** — the hidden input carries a staging path, and the
  field only accepts one this package itself produced.
- **en / ru translations** and a per-chunk debug log you can switch on per field.

## Installation

```bash
composer require w33bvgl/moonshine-chunk-upload
php artisan vendor:publish --tag=moonshine-chunk-upload-config
php artisan vendor:publish --tag=moonshine-chunk-upload-assets
```

The endpoints are registered under `/moonshine-chunk-upload/*` behind the `web`
middleware group **only** — the package cannot know which guard protects your
panel. Put your own auth in the published config before going anywhere near
production:

```php
'route' => [
    'prefix' => 'moonshine-chunk-upload',
    'name' => 'moonshine-chunk-upload.',
    'middleware' => ['web', MoonShine\Laravel\Http\Middleware\Authenticate::class, 'throttle:120,1'],
],
```

## Usage

```php
use W33bvgl\MoonShineChunkUpload\Fields\ChunkUpload;

ChunkUpload::make('Video', 'source_path')
    ->profile('video')
    ->disk('public')
    ->dir('videos')
    ->chunkSize(8 * 1024 * 1024)
    ->concurrency(4)
    ->removable();
```

That is a normal MoonShine field: drop it into a `ModelResource`'s `formFields()`
and the column ends up holding the stored path, e.g. `videos/9f1c….mp4`.

`profile()` picks an extension whitelist from the `profiles` config array
(`video`, `audio`, `subtitle`, `archive`, `image` ship by default);
`allowedExtensions([...])` narrows it for one field.

## Field reference

| Method | What it does |
|---|---|
| `profile(string)` | Extension whitelist from the config (default `video`) |
| `allowedExtensions(array)` | Overrides the profile list for this field |
| `disk(string)` / `dir(string)` | Where the file is stored once the form is submitted |
| `chunkSize(int)` | Bytes per chunk, clamped to `max_chunk_size` (default 8 MB) |
| `concurrency(int)` | Chunks in flight at once, 1–8 (default 4) |
| `keepOriginalFileName()` | Store under a sanitized original name instead of the upload id |
| `customName(Closure)` | Rename the file while it is moved onto the field's disk |
| `removable()` | Render a remove control (MoonShine's own trait) |
| `disableDeleteFiles()` | Keep the old file when the value is replaced or the record is deleted |
| `title()` / `btnText()` / `icon()` / `color()` | Cosmetics of the drop zone |
| `debug()` | Render the per-chunk request log under the field |

## The protocol

```
POST   /moonshine-chunk-upload/init      {filename, size, total, chunk_size, profile, keep_name} -> {upload_id}
POST   /moonshine-chunk-upload/chunk     ?upload_id&index   (raw body)                           -> {received}
GET    /moonshine-chunk-upload/status    ?upload_id                                              -> {received: [...], total}
POST   /moonshine-chunk-upload/finalize  {upload_id}                                             -> {path}
DELETE /moonshine-chunk-upload/abort     ?upload_id                                              -> {status}
```

Each upload owns a tmp directory holding one file per chunk plus the `meta.json`
written at `init`. `status` is what makes resuming cheap: the browser asks which
indexes the server already has and only queues the rest.

Assembly happens on the filesystem, so **the upload disk must be a local disk**
(`moonshine-chunk-upload.disk`). The disk the field finally stores the file on
(`ChunkUpload::disk()`) can be anything — a different disk is streamed to.

## What the field does on submit

Finalize does not put the file where your application wants it: it lands in the
staging directory (`final_dir`) and the form carries that path in a hidden input.
On apply the field:

1. rejects the value outright unless it is a finalized upload of this package
   (staging prefix, single path segment, no traversal),
2. moves it onto `disk()`/`dir()`, renaming per `keepOriginalFileName()` /
   `customName()` and never overwriting an existing file,
3. deletes the previously stored file (unless `disableDeleteFiles()`),
4. writes the new relative path into the column.

An empty submitted value clears the column and deletes the file; a value equal to
the current one is a no-op; a record delete removes the file too.

## Security

- **Authenticate the routes.** The default `['web']` middleware is a placeholder
  — see [Installation](#installation).
- Extensions are checked server-side against the profile at `init`, before a
  single byte is accepted.
- `max_file_size` bounds both the declared size and the chunk count;
  `max_chunk_size` bounds each request body, and an oversized body is cut off
  mid-write rather than landing on disk in full.
- Every chunk must match the length its index is supposed to carry, so the
  assembled file cannot outgrow what was declared.
- Filenames from the client are sanitized down to a single safe path segment.
- The hidden input is not trusted: only a path this package produced is claimed,
  anything else leaves the column untouched.

## Events

`W33bvgl\MoonShineChunkUpload\Events\ChunkUploadCompleted` fires when the parts
have been assembled — before any field claims the file — carrying the upload id,
the staging path and the `UploadMeta`. That is the hook for transcoding, virus
scanning or queueing follow-up work:

```php
Event::listen(function (ChunkUploadCompleted $event): void {
    TranscodeVideo::dispatch($event->path, $event->meta->filename);
});
```

## Pruning

Aborted uploads leave a tmp directory behind, and a finalized file whose form was
never submitted stays in staging. Both are swept by TTL. Packages don't schedule
themselves, so register the command on your own schedule:

```php
// routes/console.php
Schedule::command('chunk-upload:prune')->hourly();
```

```bash
php artisan chunk-upload:prune --dry-run          # report only
php artisan chunk-upload:prune --tmp-hours=6      # override the TTLs
```

## Configuration

| Key | Default | Meaning |
|---|---|---|
| `disk` | `local` | Local disk uploads are assembled on |
| `tmp_dir` / `final_dir` | `chunked-uploads/tmp` / `…/final` | Staging directories |
| `max_chunk_size` | 16 MB | Hard cap per chunk request body |
| `max_file_size` | 32 GB | Hard cap on the assembled file |
| `tmp_ttl_hours` | 24 | Age at which unfinished uploads are pruned |
| `final_ttl_hours` | 72 | Age at which unclaimed assembled files are pruned |
| `profiles` | video / audio / subtitle / archive / image | Extension whitelists |
| `route` | prefix, name, middleware | Endpoint registration |

Keep `max_chunk_size` under the web server's own body limit — nginx's
`client_max_body_size` and PHP's `post_max_size` still apply to a single chunk.

## Development

```bash
composer install
composer check   # rector + pint + phpstan + pest

npm install
npm run build    # outputs public/chunk-upload.js, committed to the repo
```

Consuming apps never need Node — only the built `public/chunk-upload.js` is
published via `php artisan vendor:publish --tag=moonshine-chunk-upload-assets`.
CI fails if that bundle is out of date with `resources/js`.

## Manual QA in a real admin panel (Docker)

Upload behaviour is hard to judge from tests alone — parallelism, retries and
resuming want to be watched. `docker-compose.yml` boots
[MoonShine's own official demo project](https://github.com/moonshine-software/demo-project),
wires this repo into it as a symlinked composer `path` repository and drops in a
playground page with three fields (video with the debug log, audio keeping its
original name, subtitles single-threaded) plus a live view of what is on disk.

```bash
docker compose up --build
```

- Admin panel: http://localhost:8000/admin — auto-login is enabled, the
  playground opens directly, no login screen.
- Kill the network mid-upload to watch chunks retry; reload the page to be
  offered the unfinished upload; submit the form to see the file move out of
  staging onto the `public` disk.

The demo app lives in a named volume (`sandbox-app`) and is only cloned once;
this package is bind-mounted and symlinked in, so PHP edits show up on the next
request. JS edits need `npm run build` on the host, then `docker compose restart`
to re-publish the bundle. To wipe the sandbox: `docker compose down -v`.
