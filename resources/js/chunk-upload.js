/*
 * Copyright @w33bvgl
 */

const KB = 1024;
const MB = KB * 1024;
const GB = MB * 1024;

const PENDING_TTL = 24 * 60 * 60 * 1000;
const MAX_LOGS = 300;
const MAX_ATTEMPTS = 3;
const RETRY_BASE_DELAY = 1000;
const PROGRESS_MIN_ELAPSED = 1;
const BUSY_DATASET_KEY = 'chunkUploadBusy';

const toInt = (value, fallback) => {
    const parsed = Number.parseInt(value, 10);

    return Number.isFinite(parsed) && parsed > 0 ? parsed : fallback;
};

const humanBytes = (bytes) => {
    if (bytes >= GB) return `${(bytes / GB).toFixed(2)} GB`;
    if (bytes >= MB) return `${(bytes / MB).toFixed(1)} MB`;
    if (bytes >= KB) return `${(bytes / KB).toFixed(0)} KB`;

    return `${bytes} B`;
};

const extensionOf = (name) => (name.includes('.') ? name.split('.').pop().toLowerCase() : '');

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

document.addEventListener('alpine:init', () => {
    Alpine.data('chunkUploader', (config) => ({
        urls: config.urls,
        csrfToken: config.csrfToken,
        storageKey: config.storageKey,
        profile: config.profile,
        labels: config.labels || {},
        extensions: config.extensions || [],
        keepName: Boolean(config.keepName),
        chunkSize: toInt(config.chunkSize, 8 * MB),
        concurrency: toInt(config.concurrency, 4),
        maxFileSize: toInt(config.maxFileSize, 0),

        state: 'idle',
        progress: 0,
        uploadedHuman: '',
        speedHuman: '',
        errorMessage: '',
        filePath: config.initialValue || '',
        fileName: (config.initialValue || '').split('/').pop(),
        canResume: false,
        isDragging: false,
        showLogs: false,
        logs: [],

        file: null,
        uploadId: null,
        totalChunks: 0,
        chunkLoaded: {},
        startedAt: 0,
        aborted: false,
        busy: false,
        pending: null,
        nextLogId: 0,

        init() {
            if (this.filePath) {
                this.state = 'success';
                this.progress = 100;

                return;
            }

            this.restore();
        },

        destroy() {
            this.setBusy(false);
        },

        restore() {
            const stored = this.readPending();

            if (!stored) return;

            this.api(this.statusUrl(stored.uploadId))
                .then(() => {
                    this.pending = stored;
                    this.fileName = stored.name;
                    this.state = 'resumable';
                })
                .catch(() => this.forgetPending());
        },

        readPending() {
            if (!this.storageKey) return null;

            try {
                const raw = window.localStorage.getItem(this.storageKey);

                if (!raw) return null;

                const stored = JSON.parse(raw);

                if (!stored || !stored.uploadId) return null;

                if (Date.now() - (stored.savedAt || 0) > PENDING_TTL) {
                    this.forgetPending();

                    return null;
                }

                return stored;
            } catch {
                return null;
            }
        },

        rememberPending() {
            if (!this.storageKey || !this.uploadId || !this.file) return;

            try {
                window.localStorage.setItem(this.storageKey, JSON.stringify({
                    uploadId: this.uploadId,
                    name: this.file.name,
                    size: this.file.size,
                    chunkSize: this.chunkSize,
                    savedAt: Date.now(),
                }));
            } catch {
                this.storageKey = '';
            }
        },

        forgetPending() {
            this.pending = null;

            if (!this.storageKey) return;

            try {
                window.localStorage.removeItem(this.storageKey);
            } catch {
                this.storageKey = '';
            }
        },

        discardPending() {
            this.forgetPending();
            this.fileName = '';
            this.state = 'idle';
        },

        statusUrl(uploadId) {
            return `${this.urls.status}?upload_id=${encodeURIComponent(uploadId)}`;
        },

        label(key, replacements = {}) {
            return Object.entries(replacements).reduce(
                (text, [token, replacement]) => text.replaceAll(`:${token}`, replacement),
                this.labels[key] || key,
            );
        },

        addLog(entry) {
            this.logs.unshift({ id: this.nextLogId++, time: new Date().toLocaleTimeString(), ...entry });

            if (this.logs.length > MAX_LOGS) this.logs.pop();
        },

        async api(url, options = {}) {
            const response = await fetch(url, {
                method: options.method || 'GET',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': this.csrfToken,
                    ...(options.json ? { 'Content-Type': 'application/json' } : {}),
                },
                body: options.json ? JSON.stringify(options.json) : undefined,
            });

            const data = await response.json().catch(() => ({}));

            if (!response.ok) {
                throw new Error(data.error || this.label('server_error', { status: response.status }));
            }

            return data;
        },

        async handleFileSelect(files) {
            if (!files || !files.length) return;

            const file = files[0];
            const error = this.validate(file);

            if (error) {
                this.file = null;
                this.fileName = file.name;
                this.handleError(error);

                return;
            }

            this.file = file;
            this.fileName = file.name;
            this.uploadId = null;

            const resumed = await this.resumePending(file);

            if (resumed) return;

            this.forgetPending();

            await this.startUpload([]);
        },

        async resumePending(file) {
            const pending = this.pending;

            if (!pending || pending.name !== file.name || pending.size !== file.size) return false;

            this.pending = null;
            this.uploadId = pending.uploadId;
            this.chunkSize = toInt(pending.chunkSize, this.chunkSize);

            try {
                const status = await this.api(this.statusUrl(this.uploadId));

                await this.startUpload(status.received || []);

                return true;
            } catch {
                this.uploadId = null;
                this.forgetPending();

                return false;
            }
        },

        validate(file) {
            const extension = extensionOf(file.name);

            if (this.extensions.length && !this.extensions.includes(extension)) {
                return this.label('bad_extension', { extensions: this.extensions.join(', ') });
            }

            if (!file.size || (this.maxFileSize && file.size > this.maxFileSize)) {
                return this.label('too_large', { max: humanBytes(this.maxFileSize) });
            }

            return null;
        },

        async startUpload(alreadyReceived) {
            this.state = 'uploading';
            this.errorMessage = '';
            this.canResume = false;
            this.aborted = false;
            this.progress = 0;
            this.speedHuman = '';
            this.chunkLoaded = {};
            this.totalChunks = Math.ceil(this.file.size / this.chunkSize);
            this.startedAt = performance.now();
            this.setBusy(true);

            try {
                if (!this.uploadId) {
                    this.logs = [];

                    const started = await this.api(this.urls.init, {
                        method: 'POST',
                        json: {
                            filename: this.file.name,
                            size: this.file.size,
                            total: this.totalChunks,
                            chunk_size: this.chunkSize,
                            profile: this.profile,
                            keep_name: this.keepName,
                        },
                    });

                    this.uploadId = started.upload_id;
                    this.addLog({ info: `Start: ${this.fileName}`, resp: humanBytes(this.file.size) });
                }

                this.rememberPending();

                const received = new Set(alreadyReceived);
                const queue = [];

                for (let index = 1; index <= this.totalChunks; index++) {
                    if (received.has(index)) {
                        this.chunkLoaded[index] = this.chunkBytes(index);
                    } else {
                        queue.push(index);
                    }
                }

                this.refreshProgress();

                await this.runPool(queue);

                if (this.aborted) return;

                this.state = 'assembly';

                const finalized = await this.api(this.urls.finalize, {
                    method: 'POST',
                    json: { upload_id: this.uploadId },
                });

                this.filePath = finalized.path;
                this.progress = 100;
                this.state = 'success';
                this.addLog({ info: 'Done', resp: finalized.path });
                this.forgetPending();
                this.setBusy(false);
            } catch (error) {
                if (!this.aborted) this.handleError(error.message);
            }
        },

        chunkBytes(index) {
            return Math.min(this.chunkSize, this.file.size - (index - 1) * this.chunkSize);
        },

        runPool(queue) {
            const size = Math.max(1, Math.min(this.concurrency, queue.length));
            const workers = Array.from({ length: size }, () => this.worker(queue));

            return Promise.all(workers);
        },

        async worker(queue) {
            while (queue.length && !this.aborted) {
                await this.sendChunkWithRetry(queue.shift());
            }
        },

        async sendChunkWithRetry(index) {
            for (let attempt = 1; attempt <= MAX_ATTEMPTS; attempt++) {
                if (this.aborted) return;

                const startedAt = performance.now();

                try {
                    await this.sendChunk(index);

                    this.addLog({
                        chunk: index,
                        total: this.totalChunks,
                        status: 'OK',
                        resp: `${(performance.now() - startedAt).toFixed(0)}ms`,
                    });

                    return;
                } catch (error) {
                    const isLastAttempt = attempt === MAX_ATTEMPTS;

                    this.addLog({
                        chunk: index,
                        status: isLastAttempt ? 'ERROR' : 'RETRY',
                        error: error.message,
                    });

                    if (isLastAttempt) throw error;

                    await wait(RETRY_BASE_DELAY * 2 ** (attempt - 1));
                }
            }
        },

        sendChunk(index) {
            return new Promise((resolve, reject) => {
                const start = (index - 1) * this.chunkSize;
                const blob = this.file.slice(start, Math.min(start + this.chunkSize, this.file.size));

                const url = new URL(this.urls.chunk, window.location.origin);
                url.searchParams.set('upload_id', this.uploadId);
                url.searchParams.set('index', index);

                const xhr = new XMLHttpRequest();

                xhr.open('POST', url.toString(), true);
                xhr.setRequestHeader('Accept', 'application/json');
                xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                xhr.setRequestHeader('X-CSRF-TOKEN', this.csrfToken);
                xhr.setRequestHeader('Content-Type', 'application/octet-stream');

                xhr.upload.onprogress = (event) => {
                    this.chunkLoaded[index] = Math.min(event.loaded, blob.size);
                    this.refreshProgress();
                };

                xhr.onload = () => {
                    let payload = {};

                    try {
                        payload = JSON.parse(xhr.responseText);
                    } catch {
                        payload = {};
                    }

                    if (xhr.status >= 200 && xhr.status < 300) {
                        this.chunkLoaded[index] = blob.size;
                        this.refreshProgress();
                        resolve(payload);

                        return;
                    }

                    this.chunkLoaded[index] = 0;
                    reject(new Error(payload.error || this.label('server_error', { status: xhr.status })));
                };

                xhr.onerror = () => {
                    this.chunkLoaded[index] = 0;
                    reject(new Error(this.label('network_error')));
                };

                xhr.onabort = xhr.onerror;
                xhr.ontimeout = xhr.onerror;

                xhr.send(blob);
            });
        },

        refreshProgress() {
            if (!this.file) return;

            const loaded = Object.values(this.chunkLoaded).reduce((total, bytes) => total + bytes, 0);
            const elapsed = (performance.now() - this.startedAt) / 1000;

            this.progress = Math.min(100, Math.round((loaded / this.file.size) * 100));
            this.uploadedHuman = `${humanBytes(loaded)} / ${humanBytes(this.file.size)}`;

            if (elapsed > PROGRESS_MIN_ELAPSED) {
                this.speedHuman = `${humanBytes(Math.round(loaded / elapsed))}/s`;
            }
        },

        async resume() {
            if (!this.file || !this.uploadId) return;

            try {
                const status = await this.api(this.statusUrl(this.uploadId));

                await this.startUpload(status.received || []);
            } catch {
                this.uploadId = null;
                this.forgetPending();

                await this.startUpload([]);
            }
        },

        handleError(message) {
            this.state = 'error';
            this.errorMessage = message;
            this.canResume = Boolean(this.file && this.uploadId);
            this.setBusy(false);
        },

        pickFile() {
            this.$refs.fileInput.value = '';
            this.$refs.fileInput.click();
        },

        reset() {
            this.aborted = true;

            if (this.uploadId) {
                this.api(`${this.urls.abort}?upload_id=${encodeURIComponent(this.uploadId)}`, { method: 'DELETE' })
                    .catch(() => {});
            }

            this.forgetPending();

            this.state = 'idle';
            this.progress = 0;
            this.uploadedHuman = '';
            this.speedHuman = '';
            this.errorMessage = '';
            this.canResume = false;
            this.filePath = '';
            this.fileName = '';
            this.file = null;
            this.uploadId = null;
            this.totalChunks = 0;
            this.chunkLoaded = {};
            this.logs = [];
            this.setBusy(false);
        },

        setBusy(busy) {
            if (this.busy === busy) return;

            this.busy = busy;

            const form = this.$el.closest('form');

            if (!form) return;

            const pending = Math.max(0, Number(form.dataset[BUSY_DATASET_KEY] || 0) + (busy ? 1 : -1));

            form.dataset[BUSY_DATASET_KEY] = String(pending);
            form.querySelectorAll('button[type="submit"]').forEach((button) => {
                button.disabled = pending > 0;
            });
        },
    }));
});
