# MoonShine Chunk Upload

Resumable, parallel chunked file upload field for [MoonShine](https://moonshine-laravel.com) 4.

Large files are split client-side and uploaded as concurrent, independently-retryable chunks
(`init` → `chunk` × N → `finalize`), so a single dropped connection doesn't cost the whole upload and
multiple chunks can be in flight at once. Uploads can be resumed after a page reload from the last
acknowledged chunk.

## Installation

```bash
composer require w33bvgl/moonshine-chunk-upload
php artisan vendor:publish --tag=moonshine-chunk-upload-config
php artisan vendor:publish --tag=moonshine-chunk-upload-assets
```

By default the routes are registered under `/moonshine-chunk-upload/*` behind the `web` middleware group
only. Set your own auth/permission middleware in the published `config/moonshine-chunk-upload.php`:

```php
'route' => [
    'prefix' => 'moonshine-chunk-upload',
    'name' => 'moonshine-chunk-upload.',
    'middleware' => ['web', 'auth:moonshine'],
],
```

## Usage

```php
use W33bvgl\MoonShineChunkUpload\Fields\ChunkUpload;

ChunkUpload::make('Video', 'source_path')
    ->profile('video')
    ->disk('public')
    ->chunkSize(8 * 1024 * 1024)
    ->concurrency(4);
```

`profile()` picks an extension whitelist from the `profiles` config array (`video`, `audio`, `subtitle` ship
by default). Use `allowedExtensions([...])` to override the list per field instance instead.

## Pruning stale uploads

Aborted uploads leave a temp directory behind until their TTL expires. Register the package's
`chunk-upload:prune` command on your own schedule (packages don't self-schedule):

```php
// routes/console.php
Schedule::command('chunk-upload:prune')->hourly();
```

## Development

```bash
composer install
npm install && npm run build   # produces dist/chunk-upload.js
composer test
```
