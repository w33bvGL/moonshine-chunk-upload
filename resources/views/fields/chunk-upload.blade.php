@props([
    'inputName' => '',
    'uploader' => [],
    'accept' => '*',
    'color' => 'primary',
    'icon' => 'c.cloud-arrow-up',
    'title' => '',
    'btnText' => '',
    'isDebug' => false,
    'isRemovable' => false,
    'previewUrl' => null,
])

<div x-data="chunkUploader(@js($uploader))" class="space-y-4">

    <x-moonshine::card class="w-full overflow-hidden">
        <input type="hidden" name="{{ $inputName }}" x-model="filePath">
        <input type="file" x-ref="fileInput" class="hidden" accept="{{ $accept }}" x-on:change="handleFileSelect($event.target.files)">

        <div
            class="relative w-full min-h-[280px] flex flex-col items-center justify-center rounded-xl border-2 border-dashed transition-all duration-500 p-8 group"
            x-bind:class="{
                'border-{{ $color }} bg-{{ $color }}/5 shadow-inner': state === 'uploading' || isDragging || state === 'assembly',
                'border-gray-300 dark:border-gray-600 hover:border-{{ $color }}/50': (state === 'idle' || state === 'resumable') && !isDragging,
                'border-green-500 bg-green-50 dark:bg-green-900/10': state === 'success',
                'border-red-500 bg-red-50 dark:bg-red-900/10': state === 'error'
            }"
            x-on:dragover.prevent="isDragging = true"
            x-on:dragleave.prevent="isDragging = false"
            x-on:drop.prevent="isDragging = false; handleFileSelect($event.dataTransfer.files)"
        >
            <div x-show="state === 'idle'" class="text-center animate-fade-in" x-transition>
                <x-moonshine::layout.grid>
                    <x-moonshine::layout.column colSpan="12">
                        <x-moonshine::layout.flex :justifyAlign="'center'">
                            <x-moonshine::icon :icon="$icon" :size="10" class="text-{{ $color }}" />
                        </x-moonshine::layout.flex>
                    </x-moonshine::layout.column>
                    <x-moonshine::layout.column colSpan="12">
                        <x-moonshine::heading h="3">{{ $title }}</x-moonshine::heading>
                        <p class="mb-3">{{ __('moonshine-chunk-upload::ui.hint') }}</p>
                        <x-moonshine::layout.divider/>
                    </x-moonshine::layout.column>
                    <x-moonshine::layout.column colSpan="12">
                        <x-moonshine::link-button :color="$color" x-on:click.prevent="pickFile()">
                            <x-moonshine::icon icon="c.folder-open"/>
                            {{ $btnText }}
                        </x-moonshine::link-button>
                    </x-moonshine::layout.column>
                </x-moonshine::layout.grid>
            </div>

            <div x-show="state === 'resumable'" class="text-center animate-fade-in" style="display: none;" x-transition>
                <x-moonshine::icon icon="c.arrow-path" size="12" class="text-{{ $color }} mb-2" />
                <h3 class="text-lg font-bold mb-2">{{ $title }}</h3>
                <p class="text-xs text-gray-500 mb-6" x-text="label('resumable', { name: fileName })"></p>
                <div class="flex items-center justify-center gap-3">
                    <x-moonshine::link-button :color="$color" size="sm" x-on:click.prevent="pickFile()">
                        {{ __('moonshine-chunk-upload::ui.resume') }}
                    </x-moonshine::link-button>
                    <x-moonshine::link-button color="gray" size="sm" outline x-on:click.prevent="discardPending()">
                        {{ __('moonshine-chunk-upload::ui.start_over') }}
                    </x-moonshine::link-button>
                </div>
            </div>

            <div x-show="state === 'uploading' || state === 'assembly'" class="w-full max-w-md text-center" style="display: none;" x-transition>
                <div class="mb-6 relative inline-block">
                    <template x-if="state === 'uploading'">
                        <div class="animate-bounce">
                            <x-moonshine::icon icon="c.arrow-up-tray" size="12" class="text-{{ $color }}" />
                        </div>
                    </template>
                    <template x-if="state === 'assembly'">
                        <div class="animate-spin">
                            <x-moonshine::icon icon="c.arrow-path" size="12" class="text-{{ $color }}" />
                        </div>
                    </template>
                </div>
                <h4 class="text-lg font-bold mb-4" x-text="state === 'assembly' ? @js(__('moonshine-chunk-upload::ui.assembling')) : @js(__('moonshine-chunk-upload::ui.uploading'))"></h4>
                <div class="space-y-2">
                    <div class="flex justify-between text-xs font-bold px-1">
                        <span class="text-gray-400" x-text="progress + '%'"></span>
                        <span class="text-gray-400" x-text="uploadedHuman"></span>
                        <span class="text-{{ $color }}" x-text="state === 'assembly' ? @js(__('moonshine-chunk-upload::ui.finalizing')) : speedHuman"></span>
                    </div>
                    <div class="progress progress-md bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden">
                        <div class="progress-bar progress-bar--{{ $color }} h-full transition-all duration-300" x-bind:style="'width: ' + progress + '%'"></div>
                    </div>
                </div>
                <p class="mt-4 text-[10px] text-gray-400 truncate" x-text="fileName"></p>
            </div>

            <div x-show="state === 'success'" class="text-center animate-fade-in" style="display: none;" x-transition>
                <x-moonshine::icon icon="c.check-circle" size="12" class="text-green-500 mb-2" />
                <h3 class="text-xl font-bold text-green-600 mb-2">{{ __('moonshine-chunk-upload::ui.success') }}</h3>
                <p class="text-[10px] text-gray-400 mb-4 truncate" x-text="fileName"></p>
                <div class="flex items-center justify-center gap-3">
                    @if($previewUrl)
                        <x-moonshine::link-button href="{{ $previewUrl }}" color="gray" size="sm" outline target="_blank">
                            <x-moonshine::icon icon="c.eye"/>
                        </x-moonshine::link-button>
                    @endif
                    <x-moonshine::link-button color="gray" size="sm" x-on:click.prevent="reset()" outline>
                        {{ __('moonshine-chunk-upload::ui.replace') }}
                    </x-moonshine::link-button>
                    @if($isRemovable)
                        <x-moonshine::link-button color="error" size="sm" x-on:click.prevent="reset()" outline>
                            {{ __('moonshine-chunk-upload::ui.remove') }}
                        </x-moonshine::link-button>
                    @endif
                </div>
            </div>

            <div x-show="state === 'error'" class="text-center animate-fade-in" style="display: none;" x-transition>
                <x-moonshine::icon icon="c.x-circle" size="12" class="text-red-500 mb-2" />
                <h3 class="text-xl font-bold text-red-600 mb-2">{{ __('moonshine-chunk-upload::ui.error') }}</h3>
                <p class="text-xs text-red-500 mb-6 font-bold" x-text="errorMessage"></p>
                <div class="flex items-center justify-center gap-3">
                    <template x-if="canResume">
                        <x-moonshine::link-button :color="$color" size="sm" x-on:click.prevent="resume()">
                            {{ __('moonshine-chunk-upload::ui.resume') }}
                        </x-moonshine::link-button>
                    </template>
                    <x-moonshine::link-button color="error" size="sm" x-on:click.prevent="reset()">
                        {{ __('moonshine-chunk-upload::ui.start_over') }}
                    </x-moonshine::link-button>
                </div>
            </div>
        </div>
    </x-moonshine::card>

    @if($isDebug)
        <div class="border border-gray-200 dark:border-gray-700 rounded-xl overflow-hidden shadow-sm">
            <button type="button" x-on:click="showLogs = !showLogs" class="w-full flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-800">
                <span class="text-xs font-bold uppercase tracking-widest text-gray-600">{{ __('moonshine-chunk-upload::ui.debug') }}</span>
                <div x-bind:class="showLogs ? 'rotate-180' : ''" class="transition-transform duration-300">
                    <x-moonshine::icon icon="c.chevron-down" size="4" />
                </div>
            </button>
            <div x-show="showLogs" x-collapse class="p-0 bg-black text-[10px] font-mono text-green-400 max-h-60 overflow-y-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                    <tr class="bg-gray-900 text-gray-400 uppercase">
                        <th class="p-2 border-b border-gray-800">{{ __('moonshine-chunk-upload::ui.log_time') }}</th>
                        <th class="p-2 border-b border-gray-800">{{ __('moonshine-chunk-upload::ui.log_chunk') }}</th>
                        <th class="p-2 border-b border-gray-800">{{ __('moonshine-chunk-upload::ui.log_status') }}</th>
                        <th class="p-2 border-b border-gray-800">{{ __('moonshine-chunk-upload::ui.log_response') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    <template x-for="log in logs" :key="log.id">
                        <tr class="border-b border-gray-900 hover:bg-white/5">
                            <td class="p-2 text-gray-500" x-text="log.time"></td>
                            <td class="p-2" x-text="log.chunk ? 'Chunk #' + log.chunk : log.info"></td>
                            <td class="p-2 font-bold" x-bind:class="log.status === 'OK' ? 'text-green-500' : (log.status === 'RETRY' ? 'text-yellow-500' : 'text-red-500')" x-text="log.status"></td>
                            <td class="p-2 text-gray-400 italic" x-text="log.resp || log.error"></td>
                        </tr>
                    </template>
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
