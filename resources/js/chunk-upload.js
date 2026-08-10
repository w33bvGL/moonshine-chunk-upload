document.addEventListener('alpine:init', () => {
    Alpine.data('chunkUploader', (config) => ({
        urls: config.urls,
        profile: config.profile,
        csrfToken: config.csrfToken,
        chunkSize: parseInt(config.chunkSize),
        concurrency: parseInt(config.concurrency) || 4,

        state: 'idle', // idle | uploading | assembly | success | error
        progress: 0,
        uploadedHuman: '',
        speedHuman: '',
        filePath: config.initialValue,
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

        init() {
            if (this.filePath) {
                this.state = 'success';
                this.progress = 100;
            }
        },

        addLog(data) {
            this.logs.unshift({ time: new Date().toLocaleTimeString(), ...data });
            if (this.logs.length > 300) this.logs.pop();
        },

        human(bytes) {
            return (bytes / 1024 / 1024).toFixed(1) + ' MB';
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
            if (!response.ok) throw new Error(data.error || `Server error: ${response.status}`);
            return data;
        },

        async handleFileSelect(files) {
            if (!files.length) return;
            this.file = files[0];
            this.fileName = this.file.name;
            this.uploadId = null;
            await this.startUpload([]);
        },

        async startUpload(alreadyReceived) {
            this.state = 'uploading';
            this.errorMessage = '';
            this.canResume = false;
            this.aborted = false;
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
                            profile: this.profile,
                        },
                    });
                    this.uploadId = init.upload_id;
                    this.chunkLoaded = {};
                    this.addLog({ info: `Start: ${this.fileName}`, resp: this.human(this.file.size) });
                }

                const received = new Set(alreadyReceived);
                received.forEach((i) => { this.chunkLoaded[i] = this.chunkBytes(i); });

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
                this.addLog({ info: 'Done', resp: result.path });
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
                const index = queue.shift();
                await this.sendChunkWithRetry(index);
            }
        },

        async sendChunkWithRetry(index, attempts = 3) {
            for (let attempt = 1; attempt <= attempts; attempt++) {
                if (this.aborted) return;
                try {
                    const startTime = performance.now();
                    await this.sendChunk(index);
                    this.addLog({
                        chunk: index, total: this.totalChunks, status: 'OK',
                        resp: (performance.now() - startTime).toFixed(0) + 'ms',
                    });
                    return;
                } catch (err) {
                    this.addLog({ chunk: index, status: attempt < attempts ? 'RETRY' : 'ERROR', error: err.message });
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
                    try { resp = JSON.parse(xhr.responseText); } catch (e) {}
                    if (xhr.status >= 200 && xhr.status < 300) {
                        this.chunkLoaded[index] = blob.size;
                        this.refreshProgress();
                        resolve(resp);
                    } else {
                        reject(new Error(resp.error || `Server error: ${xhr.status}`));
                    }
                };
                xhr.onerror = () => reject(new Error('Network error'));
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
                // Session on the server is gone — start over from scratch.
                this.uploadId = null;
                await this.startUpload([]);
            }
        },

        handleError(msg) {
            this.state = 'error';
            this.errorMessage = msg;
            this.canResume = !!(this.file && this.uploadId);
            this.toggleSubmitButton(false);
        },

        async reset() {
            this.aborted = true;
            if (this.uploadId) {
                this.api(`${this.urls.abort}?upload_id=${this.uploadId}`, { method: 'DELETE' }).catch(() => {});
            }
            this.state = 'idle';
            this.progress = 0;
            this.filePath = '';
            this.file = null;
            this.uploadId = null;
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
