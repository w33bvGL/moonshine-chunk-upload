<?php

declare(strict_types=1);

/*
 * Copyright @w33bvgl
 */

use Illuminate\Support\Facades\Storage;

it('deletes stale tmp uploads and orphaned final files', function (): void {
    Storage::disk('local')->put('tmp/stale/meta.json', '{}');
    Storage::disk('local')->put('tmp/fresh/meta.json', '{}');
    Storage::disk('local')->put('final/old.mp4', 'old');
    Storage::disk('local')->put('final/new.mp4', 'new');

    ageOf('tmp/stale', 48);
    ageOf('final/old.mp4', 96);

    $this->artisan('chunk-upload:prune')
        ->expectsOutputToContain('Pruned 1 stale tmp upload(s), 1 orphaned final file(s).')
        ->assertSuccessful();

    expect(Storage::disk('local')->exists('tmp/stale'))->toBeFalse()
        ->and(Storage::disk('local')->exists('tmp/fresh'))->toBeTrue()
        ->and(Storage::disk('local')->exists('final/old.mp4'))->toBeFalse()
        ->and(Storage::disk('local')->exists('final/new.mp4'))->toBeTrue();
});

it('reports without deleting on a dry run', function (): void {
    Storage::disk('local')->put('tmp/stale/meta.json', '{}');
    ageOf('tmp/stale', 48);

    $this->artisan('chunk-upload:prune --dry-run')
        ->expectsOutputToContain('Would prune 1 stale tmp upload(s)')
        ->assertSuccessful();

    expect(Storage::disk('local')->exists('tmp/stale'))->toBeTrue();
});

it('takes TTL overrides from the command line', function (): void {
    Storage::disk('local')->put('tmp/recent/meta.json', '{}');
    ageOf('tmp/recent', 2);

    $this->artisan('chunk-upload:prune --tmp-hours=1')->assertSuccessful();

    expect(Storage::disk('local')->exists('tmp/recent'))->toBeFalse();
});

it('sweeps up a directory left behind by an interrupted assembly', function (): void {
    Storage::disk('local')->put('tmp/abandoned.assembling/1.part', 'x');
    ageOf('tmp/abandoned.assembling', 48);

    $this->artisan('chunk-upload:prune')->assertSuccessful();

    expect(Storage::disk('local')->exists('tmp/abandoned.assembling'))->toBeFalse();
});
