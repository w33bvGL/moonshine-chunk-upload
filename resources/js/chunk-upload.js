/*
 * Copyright Anidzen @w33bvgl
 *
 * Alpine component driving the chunked upload field.
 *
 * The file is sliced in the browser and pushed through a small pool of parallel
 * XHRs; each chunk retries on its own, so a dropped connection costs one chunk
 * rather than the whole upload. The upload id is mirrored into localStorage,
 * which is what makes an upload survive a page reload — File objects cannot be
 * persisted, so the user re-picks the same file and only the missing chunks go
 * back over the wire.
 */
document.addEventListener('alpine:init', () => {
    Alpine.data('chunkUploader', (config) => ({
        urls: config.urls,
        profile: config.profile,
        csrfToken: config.csrfToken,
        chunkSize: parseInt(config.chunkSize),
        concurrency: parseInt(config.concurrency) || 4,
        maxFileSize: parseInt(config.maxFileSize),
        extensions: config.extensions || [],
        keepName: !!config.keepName,
        storageKey: config.storageKey,
        labels: config.labels || {},

        state: 'idle', // idle | resumable | uploading | assembly | success | error
        progress: 0,
        uploadedHuman: '',
        speedHuman: '',
        filePath: config.initialValue || '',
        fileName: config.initialValue ? config.initialValue.split('/').pop() : '',
        errorMessage: '',
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
        pending: null,

        init() {
            if (this.filePath) {
                this.state = 'success';
                this.progress = 100;

                return;
            }

            this.restore();
        },

        // --- persistence -----------------------------------------------------

        restore() {
            const stored = this.readPending();

            if (!stored) return;

            // The upload only deserves the resume prompt if the server still
            // holds its parts; otherwise the leftover is just noise.
            this.api(`${this.urls.status}?upload_id=${stored.uploadId}`)
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

                // A day-old id is past the server's default tmp TTL anyway.
                if (Date.now() - (stored.savedAt || 0) > 86400000) {
                    this.forgetPending();

                    return null;
                }

                return stored;
            } catch (e) {
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
            } catch (e) {
                // Private mode / quota — resuming across reloads is a bonus, not a requirement.
            }
        },

        forgetPending() {
            this.pending = null;

            if (!this.storageKey) return;

            try {
                window.localStorage.removeItem(this.storageKey);
            } catch (e) {
                //
            }
        },

        // --- helpers ---------------------------------------------------------

        addLog(data) {
            this.logs.unshift({ time: new Date().toLocaleTimeString(), ...data });
            if (this.logs.length > 300) this.logs.pop();
        },

        human(bytes) {
            if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(2) + ' GB';
            if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
            if (bytes >= 1024) return (bytes / 1024).toFixed(0) + ' KB';

            return bytes + ' B';
        },

        label(key, replacements = {}) {
            let text = this.labels[key] || key;

            Object.keys(replacements).forEach((token) => {
                text = text.replace(`:${token}`, replacements[token]);
            });

            return text;
        },

        async api(url, options = {}) {
            const response = await fetch(url, {
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': this.csrfToken,
                    ...(options.json ? { 'Content-Type': 'application/json' } : {}),
                },
                method: options.method || 'GET',
                body: options.json ? JSON.stringify(options.json) : undefined,
            });

            const data = await response.json().catch(() => ({}));

            if (!response.ok) {
                throw new Error(data.error || this.label('server_error', { status: response.status }));
            }

            return data;
        },

        // --- upload flow -----------------------------------------------------

        async handleFileSelect(files) {
            if (!files.length) return;

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

            // Re-picking the exact file of an interrupted upload continues it
            // instead of paying for the bytes the server already holds.
            if (this.pending && this.pending.name === file.name && this.pending.size === file.size) {
                this.uploadId = this.pending.uploadId;
                this.chunkSize = this.pending.chunkSize || this.chunkSize;
                this.pending = null;

                try {
                    const status = await this.api(`${this.urls.status}?upload_id=${this.uploadId}`);

                    return await this.startUpload(status.received || []);
                } catch (e) {
                    this.uploadId = null;
                    this.forgetPending();
                }
            }

            this.forgetPending();

            await this.startUpload([]);
        },

        validate(file) {
            const extension = (file.name.split('.').pop() || '').toLowerCase();

            if (this.extensions.length && !this.extensions.includes(extension)) {
                return this.label('bad_extension', { extensions: this.extensions.join(', ') });
            }

            if (this.maxFileSize && file.size > this.maxFileSize) {
                return this.label('too_large', { max: this.human(this.maxFileSize) });
            }

            if (!file.size) {
                return this.label('too_large', { max: this.human(this.maxFileSize) });
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
            this.logs = this.uploadId ? this.logs : [];
            this.totalChunks = Math.ceil(this.file.size / this.chunkSize);
            this.startedAt = performance.now();
            this.toggleSubmitButton(true);

            try {
                if (!this.uploadId) {
                    const init = await this.api(this.urls.init, {
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

                    this.uploadId = init.upload_id;
                    this.chunkLoaded = {};
                    this.addLog({ info: `Start: ${this.fileName}`, resp: this.human(this.file.size) });
                }

                this.rememberPending();

                const received = new Set(alreadyReceived);
                received.forEach((i) => { this.chunkLoaded[i] = this.chunkBytes(i); });
                this.refreshProgress();

                const queue = [];
                for (let i = 1; i <= this.totalChunks; i++) {
                    if (!received.has(i)) queue.push(i);
                }

                await this.runPool(queue);

                if (this.aborted) return;

                this.state = 'assembly';

                const result = await this.api(this.urls.finalize, {
                    method: 'POST',
                    json: { upload_id: this.uploadId },
                });

                this.filePath = result.path;
                this.state = 'success';
                this.progress = 100;
                this.addLog({ info: 'Done', resp: result.path });
                this.forgetPending();
                this.toggleSubmitButton(false);
            } catch (err) {
                if (!this.aborted) this.handleError(err.message);
            }
        },

        chunkBytes(index) {
            const start = (index - 1) * this.chunkSize;

            return Math.min(this.chunkSize, this.file.size - start);
        },

        async runPool(queue) {
            const workers = Array.from(
                { length: Math.min(this.concurrency, queue.length) },
                () => this.worker(queue),
            );

            await Promise.all(workers);
        },

        async worker(queue) {
            while (queue.length && !this.aborted) {
                await this.sendChunkWithRetry(queue.shift());
            }
        },

        async sendChunkWithRetry(index, attempts = 3) {
            for (let attempt = 1; attempt <= attempts; attempt++) {
                if (this.aborted) return;

                try {
                    const startTime = performance.now();
                    await this.sendChunk(index);
                    this.addLog({
                        chunk: index,
                        total: this.totalChunks,
                        status: 'OK',
                        resp: (performance.now() - startTime).toFixed(0) + 'ms',
                    });

                    return;
                } catch (err) {
                    this.addLog({
                        chunk: index,
                        status: attempt < attempts ? 'RETRY' : 'ERROR',
                        error: err.message,
                    });

                    if (attempt === attempts) throw err;

                    await new Promise((r) => setTimeout(r, 1000 * Math.pow(2, attempt - 1)));
                }
            }
        },

        sendChunk(index) {
            return new Promise((resolve, reject) => {
                const start = (index - 1) * this.chunkSize;
                const blob = this.file.slice(start, Math.min(start + this.chunkSize, this.file.size));

                const xhr = new XMLHttpRequest();
                const url = new URL(this.urls.chunk, window.location.origin);
                url.searchParams.append('upload_id', this.uploadId);
                url.searchParams.append('index', index);

                xhr.open('POST', url.toString(), true);
                xhr.setRequestHeader('Accept', 'application/json');
                xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                xhr.setRequestHeader('X-CSRF-TOKEN', this.csrfToken);
                xhr.setRequestHeader('Content-Type', 'application/octet-stream');

                xhr.upload.onprogress = (e) => {
                    this.chunkLoaded[index] = e.loaded;
                    this.refreshProgress();
                };

                xhr.onload = () => {
                    let resp = {};
                    try { resp = JSON.parse(xhr.responseText); } catch (e) { /* non-JSON error page */ }

                    if (xhr.status >= 200 && xhr.status < 300) {
                        this.chunkLoaded[index] = blob.size;
                        this.refreshProgress();
                        resolve(resp);
                    } else {
                        reject(new Error(resp.error || this.label('server_error', { status: xhr.status })));
                    }
                };

                xhr.onerror = () => reject(new Error(this.label('network_error')));
                xhr.send(blob);
            });
        },

        refreshProgress() {
            const loaded = Object.values(this.chunkLoaded).reduce((a, b) => a + b, 0);

            this.progress = Math.min(100, Math.round((loaded / this.file.size) * 100));
            this.uploadedHuman = `${this.human(loaded)} / ${this.human(this.file.size)}`;

            const elapsed = (performance.now() - this.startedAt) / 1000;
            if (elapsed > 1) this.speedHuman = this.human(loaded / elapsed) + '/s';
        },

        async resume() {
            if (!this.file || !this.uploadId) return;

            try {
                const status = await this.api(`${this.urls.status}?upload_id=${this.uploadId}`);
                await this.startUpload(status.received || []);
            } catch (err) {
                // The server no longer holds this upload — start over from scratch.
                this.uploadId = null;
                this.forgetPending();
                await this.startUpload([]);
            }
        },

        handleError(msg) {
            this.state = 'error';
            this.errorMessage = msg;
            this.canResume = !!(this.file && this.uploadId);
            this.toggleSubmitButton(false);
        },

        pickFile() {
            this.$refs.fileInput.value = '';
            this.$refs.fileInput.click();
        },

        async reset() {
            this.aborted = true;

            if (this.uploadId) {
                this.api(`${this.urls.abort}?upload_id=${this.uploadId}`, { method: 'DELETE' }).catch(() => {});
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
            this.toggleSubmitButton(false);
        },

        toggleSubmitButton(disabled) {
            const btn = this.$el.closest('form')?.querySelector('button[type="submit"]');
            if (btn) btn.disabled = disabled;
        },
    }));
});
