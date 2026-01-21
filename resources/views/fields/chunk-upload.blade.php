@props([
    'element' => null,
    'inputName' => '',
    'inputValue' => '',
    'uploadRoute' => '',
    'extensions' => [],
    'accept' => '*',
    'color' => 'primary',
    'size' => 'md',
    'radial' => false,
    'progressAttributes' => [],
])

@once
    <script src="https://cdn.jsdelivr.net/npm/resumablejs@1.1.0/resumable.min.js"></script>
    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('chunkUploader', (config) => ({
                uploadUrl: config.uploadUrl,
                csrfToken: config.csrfToken,
                allowedExtensions: config.extensions || [],
                state: 'idle', // idle, uploading, assembly, success, error
                progress: 0,
                filePath: config.initialValue,
                fileName: config.initialValue ? config.initialValue.split('/').pop() : '',
                errorMessage: '',
                resumable: null,
                isDragging: false,

                init() {
                    if (this.filePath) {
                        this.state = 'success';
                        this.progress = 100;
                    }
                    this.initResumable();
                },

                initResumable() {
                    if (!this.uploadUrl) {
                        this.handleError('Route missing');
                        return;
                    }

                    this.resumable = new Resumable({
                        target: this.uploadUrl,
                        query: { _token: this.csrfToken },
                        fileType: this.allowedExtensions.length > 0 ? this.allowedExtensions : undefined,
                        chunkSize: 2 * 1024 * 1024, // 2MB чанки
                        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrfToken },
                        testChunks: false,
                        throttleProgressCallbacks: 1,
                    });

                    if (this.$refs.dropZone) this.resumable.assignDrop(this.$refs.dropZone);

                    this.resumable.on('fileAdded', (file) => {
                        this.state = 'uploading';
                        this.fileName = file.fileName;
                        this.errorMessage = '';
                        this.toggleFormSubmit(true);
                        this.resumable.upload();
                    });

                    this.resumable.on('fileProgress', (file) => {
                        this.progress = Math.floor(file.progress() * 100);
                        if (this.progress >= 100) this.state = 'assembly';
                    });

                    this.resumable.on('fileSuccess', (file, message) => {
                        try {
                            const response = JSON.parse(message);
                            this.filePath = response.path;
                            this.state = 'success';
                            this.toggleFormSubmit(false);
                        } catch (e) { this.handleError('Server Error'); }
                    });

                    this.resumable.on('error', () => this.handleError('Upload Error'));
                    this.resumable.on('fileError', () => this.handleError('File Error'));
                },

                handleNativeSelect(e) {
                    if (e.target.files.length > 0 && this.resumable) {
                        this.resumable.addFiles(e.target.files);
                    }
                    e.target.value = '';
                },

                handleError(msg) {
                    this.state = 'error';
                    this.errorMessage = msg;
                    this.toggleFormSubmit(false);
                    if(this.resumable) this.resumable.cancel();
                },

                reset() {
                    if (this.resumable) this.resumable.cancel();
                    this.state = 'idle'; this.filePath = ''; this.fileName = ''; this.progress = 0;
                },

                toggleFormSubmit(disabled) {
                    const btn = this.$el.closest('form')?.querySelector('button[type="submit"]');
                    if(btn) btn.disabled = disabled;
                }
            }));
        });
    </script>
@endonce

<x-moonshine::card class="w-full">
    <div x-data='chunkUploader({
            uploadUrl: "{{ $uploadRoute }}",
            initialValue: "{{ $inputValue }}",
            csrfToken: "{{ csrf_token() }}",
            extensions: @json($extensions)
        })'
    >
        {{-- Скрытый инпут с путем к файлу (для формы) --}}
        <input type="hidden" name="{{ $inputName }}" x-model="filePath">

        {{-- Скрытый нативный инпут (для выбора файла) --}}
        <input type="file" x-ref="nativeFile" class="hidden" accept="{{ $accept }}" @change="handleNativeSelect">

        {{-- ЗОНА ДРОПА --}}
        <div x-ref="dropZone"
             class="relative w-full min-h-[260px] flex flex-col items-center justify-center rounded-xl border-2 border-dashed transition-all duration-300 gap-6 p-8 group"
             :class="{
                 'border-{{ $color }} bg-{{ $color }}/5': state === 'uploading' || state === 'assembly',
                 'border-gray-300 dark:border-gray-600 hover:border-{{ $color }}/50': state === 'idle',
                 'border-green-500 bg-green-50 dark:bg-green-900/10': state === 'success',
                 'border-red-500 bg-red-50 dark:bg-red-900/10': state === 'error'
             }"
             @dragover.prevent="isDragging = true"
             @dragleave.prevent="isDragging = false"
             @drop.prevent="isDragging = false"
        >

            {{-- 1. ОЖИДАНИЕ (IDLE) --}}
            <div x-show="state === 'idle'" class="text-center animate-fade-in">
                <div class="bg-{{ $color }}/10 p-6 rounded-full mb-5 inline-block group-hover:scale-110 transition-transform duration-300">
                    <x-moonshine::icon icon="c.video-camera" size="10" class="text-{{ $color }}" />
                </div>

                <h3 class="text-xl font-bold text-gray-800 dark:text-gray-100 mb-2">
                    Загрузка видео
                </h3>

                <p class="text-sm text-gray-500 dark:text-gray-400 mb-6 max-w-xs mx-auto">
                    Перетащите файл сюда или нажмите кнопку ниже.
                    @if(!empty($extensions))
                        <br><span class="opacity-70 text-xs">{{ implode(', ', $extensions) }}</span>
                    @endif
                </p>

                <x-moonshine::link-button :color="$color" @click="$refs.nativeFile.click()">
                    <x-moonshine::icon icon="c.folder-open" class="w-4 h-4 mr-2"/>
                    Выбрать файл
                </x-moonshine::link-button>
            </div>

            {{-- 2. ЗАГРУЗКА (UPLOADING / ASSEMBLY) --}}
            <div x-show="state === 'uploading' || state === 'assembly'" class="w-full max-w-sm text-center flex flex-col items-center" style="display: none;">
                <div class="mb-6">
                    <template x-if="state === 'assembly'">
                        <div class="flex flex-col items-center gap-3 animate-pulse">
                            <x-moonshine::spinner :color="$color" size="lg" />
                            <span class="text-lg font-bold text-gray-700 dark:text-gray-200">Сборка на сервере...</span>
                        </div>
                    </template>

                    <template x-if="state === 'uploading'">
                        <div class="text-2xl font-black text-{{ $color }}" x-text="progress + '%'"></div>
                    </template>
                </div>

                {{-- ГЛАВНЫЙ ФИКС: Атрибуты Alpine прокидываются через массив --}}
                <x-moonshine::progress-bar
                        :color="$color"
                        :size="$size"
                        :radial="$radial"
                        :value="0"
                        {{ $attributes->merge($progressAttributes) }}
                        class="w-full"
                >
                    <span x-text="progress + '%'"></span>
                </x-moonshine::progress-bar>

                <div class="mt-4 text-xs font-mono text-gray-400 truncate w-full" x-text="fileName"></div>
            </div>

            {{-- 3. УСПЕХ (SUCCESS) --}}
            <div x-show="state === 'success'" class="w-full text-center animate-fade-in" style="display: none;">
                <div class="bg-green-100 dark:bg-green-900/30 p-5 rounded-full inline-block mb-4">
                    <x-moonshine::icon icon="c.check-badge" size="12" class="text-green-600" />
                </div>
                <div class="mb-6">
                    <h3 class="text-lg font-bold text-gray-800 dark:text-white">Готово!</h3>
                    <p class="text-sm text-green-600 font-medium break-all px-4" x-text="fileName || 'Файл загружен'"></p>
                </div>
                <x-moonshine::link-button color="gray" size="sm" @click="reset">
                    <x-moonshine::icon icon="c.arrow-path" class="w-4 h-4 mr-2"/>
                    Загрузить другой
                </x-moonshine::link-button>
            </div>

            {{-- 4. ОШИБКА (ERROR) --}}
            <div x-show="state === 'error'" class="text-center" style="display: none;">
                <div class="bg-red-100 dark:bg-red-900/30 p-4 rounded-full inline-block mb-4">
                    <x-moonshine::icon icon="c.exclamation-triangle" size="10" class="text-red-500" />
                </div>
                <h3 class="text-lg font-bold text-red-500 mb-2">Ошибка</h3>
                <p class="text-sm text-red-400 mb-6" x-text="errorMessage"></p>
                <x-moonshine::link-button color="error" @click="reset">Попробовать снова</x-moonshine::link-button>
            </div>

        </div>
    </div>
</x-moonshine::card>