<script>
    function shadowingEngine(config) {
        return {
            lessonType: config.lessonType,
            youtubeId: config.youtubeId,
            audioUrl: config.audioUrl,
            segments: config.segments,
            userRecordings: config.userRecordings || {},
            currentIndex: config.currentIndex,
            ytPlayer: null,
            isPlaying: false,
            autoPause: true,
            largeVideo: false,
            showTranscriptPanel: true,
            showIpa: true,
            showTranslation: true,
            playbackRate: 1.0,
            isRecording: false,
            recordingState: 'idle', // 'idle' | 'requesting_permission' | 'recording' | 'stopping' | 'uploading' | 'ready' | 'error'
            recordingErrorMessage: '',
            mediaRecorder: null,
            audioChunks: [],
            userAudioUrl: null,
            isPlayingUserAudio: false,
            userAudioElement: null,
            timerCheck: null,

            // Explicit Playback State Machine
            playbackMode: 'continuous', // 'continuous' | 'chunk_practice'
            autoPause: localStorage.getItem('shadowing_auto_pause') !== 'false',
            autoPauseArmed: false,
            targetEndMs: null,
            isSeeking: false,
            expectedStartMs: null,
            prePaddingMs: 0,
            postPaddingMs: 150,

            // Loop Mode: 'once' | 'loop_3' | 'loop_infinite'
            loopMode: localStorage.getItem('shadowing_loop_mode') || 'once',
            loopCounter: 0,

            // User scroll guard timestamp
            userScrolledTimestamp: 0,

            initEngine() {
                if (this.lessonType === 'youtube' && this.youtubeId) {
                    this.initYouTubePlayer();
                }
                this.updateRecordingForActiveSegment();
            },

            updateRecordingForActiveSegment() {
                this.stopUserAudio();
                const seg = this.currentSegment();
                if (!seg) {
                    this.userAudioUrl = null;
                    return;
                }

                const userRecs = (this.userRecordings && Object.keys(this.userRecordings).length > 0)
                    ? this.userRecordings
                    : (this.$wire && this.$wire.userRecordings ? this.$wire.userRecordings : {});

                const rec = userRecs[seg.id] || userRecs[String(seg.id)] || null;
                if (rec && rec.playback_url) {
                    this.userAudioUrl = rec.playback_url;
                    this.recordingState = 'ready';
                } else {
                    this.userAudioUrl = null;
                    this.recordingState = 'idle';
                }
                this.recordingErrorMessage = '';
            },

            getBestSupportedMimeType() {
                const types = [
                    'audio/webm;codecs=opus',
                    'audio/webm',
                    'audio/mp4',
                    'audio/m4a',
                    'audio/ogg',
                    'audio/wav'
                ];
                for (const t of types) {
                    if (typeof MediaRecorder !== 'undefined' && MediaRecorder.isTypeSupported(t)) {
                        return t;
                    }
                }
                return '';
            },

            async startRecording() {
                this.stopUserAudio();
                this.pausePlayback();
                this.recordingErrorMessage = '';

                if (typeof MediaRecorder === 'undefined' || !navigator.mediaDevices?.getUserMedia) {
                    this.recordingState = 'error';
                    this.recordingErrorMessage = 'Trình duyệt không hỗ trợ API ghi âm MediaRecorder hoặc Web Audio API.';
                    return;
                }

                try {
                    this.recordingState = 'requesting_permission';
                    const stream = await navigator.mediaDevices.getUserMedia({ audio: true });

                    const mimeType = this.getBestSupportedMimeType();
                    const options = mimeType ? { mimeType } : {};
                    this.mediaRecorder = new MediaRecorder(stream, options);
                    this.audioChunks = [];

                    this.mediaRecorder.ondataavailable = (e) => {
                        if (e.data && e.data.size > 0) {
                            this.audioChunks.push(e.data);
                        }
                    };

                    this.recordStartedAt = performance.now();
                    this.mediaRecorder.start();
                    this.isRecording = true;
                    this.recordingState = 'recording';

                    this.mediaRecorder.onstop = async () => {
                        const durationMs = Math.max(100, Math.round(performance.now() - (this.recordStartedAt || performance.now())));
                        const recordedMime = this.mediaRecorder.mimeType || mimeType || 'audio/webm';
                        const ebmlBytes = new Uint8Array([
                            0x1A, 0x45, 0xDF, 0xA3, 0x9F, 0x42, 0x86, 0x81, 0x01, 0x42, 0xF7, 0x81, 0x01, 0x42, 0xF2, 0x81,
                            0x04, 0x42, 0xF3, 0x81, 0x08, 0x42, 0x82, 0x84, 0x77, 0x65, 0x62, 0x6D, 0x42, 0x87, 0x81, 0x04,
                            0x42, 0x85, 0x81, 0x02, 0x18, 0x53, 0x80, 0x67, 0x01, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00,
                            0x11, 0x4D, 0x9B, 0x74, 0x4D, 0xBB, 0x8B, 0x53, 0xAB, 0x84, 0x15, 0x49, 0xA9, 0x66, 0x53, 0xAC
                        ]);
                        const ebmlPadding = new Uint8Array(500);
                        const audioBlob = new Blob([ebmlBytes, ebmlPadding, ...this.audioChunks], { type: recordedMime });
                        this.userAudioUrl = URL.createObjectURL(audioBlob);

                        // Stop tracks
                        stream.getTracks().forEach(track => track.stop());

                        await this.uploadRecordingBlob(audioBlob, recordedMime, durationMs);
                    };
                } catch (err) {
                    this.isRecording = false;
                    this.recordingState = 'error';
                    if (err.name === 'NotAllowedError' || err.name === 'PermissionDeniedError') {
                        this.recordingErrorMessage = 'Quyền truy cập Micro bị từ chối. Vui lòng cấp quyền Microphone trên trình duyệt để ghi âm.';
                    } else {
                        this.recordingErrorMessage = 'Không thể khởi động ghi âm: ' + (err.message || 'Lỗi thiết bị micro');
                    }
                }
            },

            stopRecording() {
                if (this.mediaRecorder && this.isRecording) {
                    this.recordingState = 'stopping';
                    this.mediaRecorder.stop();
                    this.isRecording = false;
                }
            },

            async uploadRecordingBlob(audioBlob, mimeType, durationMs = 3000) {
                const seg = this.currentSegment();
                const lessonId = config.lessonId || (this.$wire && this.$wire.lesson ? this.$wire.lesson.id : null);
                if (!seg || !lessonId) return;

                this.recordingState = 'uploading';
                this.recordingErrorMessage = '';

                const formData = new FormData();
                const ext = mimeType.includes('mp4') || mimeType.includes('m4a') ? 'm4a' : mimeType.includes('ogg') ? 'ogg' : mimeType.includes('wav') ? 'wav' : 'webm';
                formData.append('audio', audioBlob, `recording.${ext}`);
                formData.append('lesson_id', lessonId);
                formData.append('segment_id', seg.id);
                formData.append('duration_ms', durationMs);

                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

                try {
                    const response = await fetch('/shadowing/recordings/upload', {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': csrfToken,
                            'Accept': 'application/json',
                        },
                        body: formData,
                    });

                    const data = await response.json();

                    if (response.ok && data.success) {
                        this.userAudioUrl = data.playback_url;
                        this.recordingState = 'ready';

                        // Update local Alpine userRecordings object and Livewire userRecordings array
                        if (!this.userRecordings) this.userRecordings = {};
                        this.userRecordings[seg.id] = {
                            public_id: data.public_id,
                            playback_url: data.playback_url,
                            duration_ms: data.duration_ms,
                            size_bytes: data.size_bytes,
                        };

                        if (this.$wire && this.$wire.userRecordings) {
                            this.$wire.userRecordings[seg.id] = this.userRecordings[seg.id];
                        }
                        this.$wire.recordAttempt(null, null, data.duration_ms);
                    } else {
                        this.recordingState = 'error';
                        this.recordingErrorMessage = data.message || 'Lỗi tải lên file ghi âm.';
                    }
                } catch (err) {
                    this.recordingState = 'error';
                    this.recordingErrorMessage = 'Lỗi kết nối khi tải lên file ghi âm: ' + (err ? (err.message || err.toString()) : 'Network error');
                }
            },

            playUserAudio() {
                if (!this.userAudioUrl) return;
                this.pausePlayback(); // Pause sample audio before playing student voice!

                if (!this.userAudioElement) {
                    this.userAudioElement = new Audio();
                }

                this.userAudioElement.src = this.userAudioUrl;
                this.userAudioElement.onended = () => {
                    this.isPlayingUserAudio = false;
                };
                this.userAudioElement.onpause = () => {
                    this.isPlayingUserAudio = false;
                };

                this.userAudioElement.play();
                this.isPlayingUserAudio = true;
            },

            stopUserAudio() {
                if (this.userAudioElement) {
                    this.userAudioElement.pause();
                    this.userAudioElement.currentTime = 0;
                }
                this.isPlayingUserAudio = false;
            },

            toggleRecording() {
                if (this.recordingState === 'recording') {
                    this.stopRecording();
                } else if (this.recordingState === 'idle' || this.recordingState === 'ready' || this.recordingState === 'error') {
                    this.startRecording();
                }
            },

            cycleLoopMode() {
                const modes = ['once', 'loop_3', 'loop_infinite'];
                const idx = modes.indexOf(this.loopMode);
                this.loopMode = modes[(idx + 1) % modes.length];
                localStorage.setItem('shadowing_loop_mode', this.loopMode);
                this.loopCounter = 0;
            },

            adjustSpeed(delta) {
                const rates = [0.5, 0.75, 1.0, 1.25, 1.5, 2.0];
                let idx = rates.indexOf(this.playbackRate);
                if (idx === -1) idx = 2; // Default 1.0
                if (delta > 0 && idx < rates.length - 1) idx++;
                if (delta < 0 && idx > 0) idx--;

                this.playbackRate = rates[idx];

                if (this.lessonType === 'youtube' && this.ytPlayer) {
                    this.ytPlayer.setPlaybackRate(this.playbackRate);
                } else if (this.$refs.masterAudio) {
                    this.$refs.masterAudio.playbackRate = this.playbackRate;
                }
            },

            quickRewind(seconds = 2.0) {
                this.stopUserAudio();
                const ms = seconds * 1000;
                const seg = this.currentSegment();
                if (!seg) return;

                let currentMs = 0;
                if (this.lessonType === 'youtube' && this.ytPlayer) {
                    currentMs = this.ytPlayer.getCurrentTime() * 1000;
                } else if (this.$refs.masterAudio) {
                    currentMs = this.$refs.masterAudio.currentTime * 1000;
                }

                const targetMs = Math.max(seg.start_ms, currentMs - ms);
                const targetSec = targetMs / 1000;

                if (this.lessonType === 'youtube' && this.ytPlayer) {
                    this.ytPlayer.seekTo(targetSec, true);
                } else if (this.$refs.masterAudio) {
                    this.$refs.masterAudio.currentTime = targetSec;
                }
            },

            selectSegment(index) {
                this.stopUserAudio();
                this.currentIndex = index;
                this.updateRecordingForActiveSegment();
                this.playCurrentSegment();
            },

            scrollToActiveCard(index, forceUserScrollReset = false) {
                const now = Date.now();
                if (!forceUserScrollReset && (now - this.userScrolledTimestamp < 5000)) {
                    return;
                }

                this.$nextTick(() => {
                    const card = document.getElementById(`segment-card-${index}`);
                    const container = document.getElementById('transcript-scroll-container');

                    if (card && container) {
                        const cardTop = card.offsetTop;
                        const cardHeight = card.offsetHeight;
                        const containerHeight = container.offsetHeight;

                        const targetScrollTop = cardTop - (containerHeight / 2) + (cardHeight / 2);

                        container.scrollTo({
                            top: Math.max(0, targetScrollTop),
                            behavior: 'smooth'
                        });
                    }
                });
            },

            onUserScroll() {
                this.userScrolledTimestamp = Date.now();
            },

            initYouTubePlayer() {
                if (window.YT && window.YT.Player) {
                    this.createYTPlayer();
                } else {
                    if (!window.onYouTubeIframeAPIReady) {
                        window.onYouTubeIframeAPIReady = () => {
                            window.dispatchEvent(new Event('yt-api-ready'));
                        };
                    }
                    window.addEventListener('yt-api-ready', () => this.createYTPlayer());

                    if (!document.getElementById('yt-iframe-api')) {
                        const tag = document.createElement('script');
                        tag.id = 'yt-iframe-api';
                        tag.src = "https://www.youtube.com/iframe_api";
                        const firstScriptTag = document.getElementsByTagName('script')[0];
                        firstScriptTag.parentNode.insertBefore(tag, firstScriptTag);
                    }
                }
            },

            createYTPlayer() {
                this.ytPlayer = new window.YT.Player('shadowing-yt-player', {
                    videoId: this.youtubeId,
                    playerVars: {
                        'autoplay': 0,
                        'controls': 1,
                        'rel': 0,
                        'modestbranding': 1,
                        'playsinline': 1
                    },
                    events: {
                        'onStateChange': (event) => this.onPlayerStateChange(event)
                    }
                });
            },

            onPlayerStateChange(event) {
                // YT.PlayerState.PLAYING = 1
                if (event.data === 1) {
                    this.isPlaying = true;
                    this.syncPlaybackStateOnPlay();
                } else if (event.data === 2 || event.data === 0) {
                    this.isPlaying = false;
                    this.stopTimerCheck();
                }
            },

            syncPlaybackStateOnPlay() {
                const seg = this.currentSegment();
                if (!seg) return;

                let currentMs = 0;
                if (this.lessonType === 'youtube' && this.ytPlayer) {
                    currentMs = this.ytPlayer.getCurrentTime() * 1000;
                } else if (this.$refs.masterAudio) {
                    currentMs = this.$refs.masterAudio.currentTime * 1000;
                }

                // Check if playback position is inside the active segment (with padding)
                const segStart = Math.max(0, seg.start_ms - this.prePaddingMs);
                const segEnd = seg.end_ms + this.postPaddingMs;

                if (currentMs >= segStart && currentMs <= segEnd) {
                    // Arm auto-pause for active segment
                    this.playbackMode = 'chunk_practice';
                    this.autoPauseArmed = this.autoPause;
                    this.targetEndMs = segEnd;
                } else {
                    // Timeline playback outside current segment bounds
                    this.playbackMode = 'continuous';
                    this.autoPauseArmed = false;
                    this.targetEndMs = null;
                }

                this.startTimerCheck();
            },

            startTimerCheck() {
                this.stopTimerCheck();
                this.timerCheck = setInterval(() => {
                    this.checkPlaybackTime();
                }, 50);
            },

            stopTimerCheck() {
                if (this.timerCheck) {
                    clearInterval(this.timerCheck);
                    this.timerCheck = null;
                }
            },

            checkPlaybackTime() {
                if (!this.isPlaying) return;

                let currentMs = 0;
                if (this.lessonType === 'youtube' && this.ytPlayer) {
                    currentMs = this.ytPlayer.getCurrentTime() * 1000;
                } else if (this.$refs.masterAudio) {
                    currentMs = this.$refs.masterAudio.currentTime * 1000;
                }

                // Seeking guard: Ignore checks while seek is in progress!
                if (this.isSeeking && this.expectedStartMs !== null) {
                    if (Math.abs(currentMs - this.expectedStartMs) < 400) {
                        this.isSeeking = false; // Seeking completed!
                    } else {
                        return; // Still seeking, ignore auto-pause!
                    }
                }

                // Active auto-pause boundary check
                if (this.autoPauseArmed && this.targetEndMs !== null && currentMs >= this.targetEndMs) {
                    if (this.loopMode === 'loop_3') {
                        this.loopCounter++;
                        if (this.loopCounter < 3) {
                            this.playCurrentSegment();
                            return;
                        }
                    } else if (this.loopMode === 'loop_infinite') {
                        this.playCurrentSegment();
                        return;
                    }

                    // Reset loop counter & pause!
                    this.loopCounter = 0;
                    this.pausePlayback();
                    this.playbackMode = 'continuous';
                    this.autoPauseArmed = false;
                    this.targetEndMs = null;
                    return;
                }

                // Auto segment tracking: Find active segment matching current time
                const activeSegIndex = this.segments.findIndex(s => currentMs >= s.start_ms && currentMs <= s.end_ms);
                if (activeSegIndex !== -1 && (activeSegIndex + 1) !== this.currentIndex) {
                    this.currentIndex = activeSegIndex + 1;
                    this.updateRecordingForActiveSegment();
                    this.scrollToActiveCard(this.currentIndex);
                } else if (activeSegIndex === -1 && this.playbackMode === 'continuous') {
                    // In a gap or before Chunk #1: set awaiting_next_chunk
                    this.playbackMode = 'awaiting_next_chunk';
                    this.autoPauseArmed = false;
                    this.targetEndMs = null;
                }
            },

            onAutoPauseToggleChange() {
                localStorage.setItem('shadowing_auto_pause', this.autoPause);
                if (this.isPlaying) {
                    this.syncPlaybackStateOnPlay();
                } else if (!this.autoPause) {
                    this.autoPauseArmed = false;
                    this.targetEndMs = null;
                }
            },

            currentSegment() {
                return this.segments.find(s => s.segment_index === this.currentIndex) || this.segments[0];
            },

            togglePlay() {
                if (this.isPlaying) {
                    this.pausePlayback();
                } else {
                    // MANUAL PLAY BUTTON / SPACEBAR: STOP STUDENT AUDIO & RESUME CURRENT TIMELINE
                    this.stopUserAudio();
                    this.syncPlaybackStateOnPlay();
                    this.isSeeking = false;

                    if (this.lessonType === 'youtube' && this.ytPlayer) {
                        this.ytPlayer.playVideo();
                    } else if (this.$refs.masterAudio) {
                        this.$refs.masterAudio.play();
                        this.isPlaying = true;
                    }
                }
            },

            pausePlayback() {
                if (this.lessonType === 'youtube' && this.ytPlayer && typeof this.ytPlayer.pauseVideo === 'function') {
                    this.ytPlayer.pauseVideo();
                } else if (this.$refs.masterAudio) {
                    this.$refs.masterAudio.pause();
                }
                this.isPlaying = false;
                this.stopTimerCheck();
            },

            playCurrentSegment() {
                this.stopUserAudio();
                const seg = this.currentSegment();
                if (!seg) return;

                // Actions that seek: Arm chunk practice + set seeking guard!
                this.playbackMode = 'chunk_practice';
                this.autoPauseArmed = false; // Disarmed until seeking finishes!
                this.isSeeking = true;
                this.expectedStartMs = Math.max(0, seg.start_ms - this.prePaddingMs);
                this.targetEndMs = seg.end_ms + this.postPaddingMs;

                this.scrollToActiveCard(seg.segment_index, true);

                const startSec = this.expectedStartMs / 1000;
                if (this.lessonType === 'youtube' && this.ytPlayer) {
                    this.ytPlayer.seekTo(startSec, true);
                    this.ytPlayer.playVideo();
                } else if (this.$refs.masterAudio) {
                    this.$refs.masterAudio.currentTime = startSec;
                    this.$refs.masterAudio.play();
                    this.isPlaying = true;
                }
            },

            replayCurrentSegment() {
                this.stopUserAudio();
                this.loopCounter = 0;
                this.playCurrentSegment();
            },

            handleKeydown(e) {
                // Ignore keyboard shortcuts when typing in inputs
                const tag = document.activeElement?.tagName;
                if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;

                switch(e.code) {
                    case 'Space':
                        e.preventDefault();
                        this.togglePlay();
                        break;
                    case 'KeyR':
                        e.preventDefault();
                        this.replayCurrentSegment();
                        break;
                    case 'ArrowRight':
                        e.preventDefault();
                        this.nextSegment();
                        break;
                    case 'ArrowLeft':
                        e.preventDefault();
                        this.prevSegment();
                        break;
                    case 'Minus': // - key = speed down
                    case 'NumpadSubtract':
                        e.preventDefault();
                        this.adjustSpeed(-0.25);
                        break;
                    case 'Equal': // + key (=/+) = speed up
                    case 'NumpadAdd':
                        e.preventDefault();
                        this.adjustSpeed(0.25);
                        break;
                    case 'KeyZ': // Z = quick rewind -2s
                        e.preventDefault();
                        this.quickRewind(2.0);
                        break;
                    case 'KeyM': // M = toggle mic recording
                        e.preventDefault();
                        this.toggleRecording();
                        break;
                    case 'KeyL': // L = cycle loop mode
                        e.preventDefault();
                        this.cycleLoopMode();
                        break;
                }
            }
        };
    }
</script>

<div class="min-h-screen w-full bg-[#f5f5f9] text-slate-800 font-['Public_Sans',sans-serif] flex flex-col select-none"
    x-data="shadowingEngine({
        lessonId: {{ $this->lesson?->id ?? 0 }},
        lessonType: '{{ $this->lesson?->media_type ?? 'audio' }}',
        youtubeId: '{{ $this->lesson?->youtube_video_id ?? '' }}',
        audioUrl: '{{ $this->lesson?->audio_url ?? '' }}',
        segments: {{ json_encode($this->studentSegments) }},
        userRecordings: {{ json_encode($this->userRecordings) }},
        currentIndex: {{ $currentIndex }}
    })"
    x-init="initEngine()"
    @keydown.window="handleKeydown($event)">

    {{-- FOCUS MODE STYLING: Hide top marketing banners, main nav, and floating Zalo/CTA overlays --}}
    <style>
        #announcement-bar,
        header.sticky,
        div.bg-\[\#696cff\]\/10,
        .zalo-chat-widget, #zalo-widget, .ad-banner, [class*="zalo"], [id*="zalo"], [class*="banner-ads"], .floating-cta-button,
        body > div > div > font, body > font {
            display: none !important;
        }
        body {
            background-color: #f5f5f9 !important;
            overflow-x: hidden;
        }
    </style>

    {{-- Top Workspace Bar --}}
    <div class="h-16 border-b border-slate-200/80 bg-white px-6 flex items-center justify-between shrink-0 shadow-xs">
        <div class="flex items-center space-x-4">
            <a href="{{ route('shadowing.index') }}" class="flex items-center gap-1.5 text-slate-500 hover:text-slate-900 text-sm font-semibold transition">
                <i class='bx bx-left-arrow-alt text-lg'></i>
                <span>Thư viện</span>
            </a>
            <div class="h-4 w-px bg-slate-200"></div>
            @if($this->lesson)
                <h1 class="text-sm font-bold text-slate-900 truncate max-w-md" title="{{ $this->lesson->title }}">
                    {{ $this->lesson->title }}
                </h1>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-[#696cff]/10 text-[#696cff]">
                    {{ $this->lesson->level }}
                </span>
            @endif
        </div>

        <div class="flex items-center space-x-3">
            {{-- Practice Mode Selector --}}
            <div class="inline-flex rounded-xl bg-slate-100 p-1 border border-slate-200/80 text-xs font-bold text-slate-600">
                <button wire:click="setMode('LISTEN_REPEAT')"
                    class="px-3 py-1.5 rounded-lg transition {{ $practiceMode === 'LISTEN_REPEAT' ? 'bg-white text-[#696cff] shadow-xs font-extrabold' : 'hover:text-slate-900' }}">
                    <span>Nghe & Nhại lại</span>
                </button>
                <button wire:click="setMode('SHADOWING')"
                    class="px-3 py-1.5 rounded-lg transition {{ $practiceMode === 'SHADOWING' ? 'bg-white text-[#696cff] shadow-xs font-extrabold' : 'hover:text-slate-900' }}">
                    <span>Shadowing</span>
                </button>
                <button wire:click="setMode('CHALLENGE')"
                    class="px-3 py-1.5 rounded-lg transition {{ $practiceMode === 'CHALLENGE' ? 'bg-white text-amber-600 shadow-xs font-extrabold' : 'hover:text-slate-900' }}">
                    <i class='bx bx-target-lock mr-1'></i>
                    <span>Thử thách</span>
                </button>
            </div>

            {{-- Display Toggles --}}
            <button @click="showTranscriptPanel = !showTranscriptPanel"
                class="inline-flex items-center px-3 py-1.5 rounded-xl border text-xs font-bold transition"
                :class="!showTranscriptPanel ? 'bg-purple-50 text-[#696cff] border-[#696cff]/40 shadow-xs' : 'bg-white text-slate-700 border-slate-200/80 hover:bg-slate-50'">
                <i class="bx text-sm mr-1.5" :class="showTranscriptPanel ? 'bx-dock-right text-[#696cff]' : 'bx-sidebar text-slate-400'"></i>
                <span>Phụ đề</span>
            </button>
        </div>
    </div>

    {{-- Main Workspace Split-Pane --}}
    <div class="flex-1 flex overflow-hidden">
        {{-- Left: Media & Interactive Player Workspace --}}
        <div class="flex-1 flex flex-col min-w-0 bg-[#f5f5f9]">
            {{-- Video / Media Player Container --}}
            <div class="p-6 flex-1 flex flex-col justify-center items-center">
                @if($this->lesson)
                    <div class="w-full max-w-4xl bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden flex flex-col">
                        @if($this->lesson->media_type === 'youtube' && $this->lesson->youtube_video_id)
                            <div class="aspect-video w-full bg-slate-950 relative">
                                <div id="shadowing-yt-player" class="w-full h-full"></div>
                            </div>
                        @elseif($this->lesson->audio_url)
                            <div class="p-8 flex flex-col items-center justify-center bg-slate-900 text-white relative">
                                <i class='bx bx-music text-6xl text-[#696cff] mb-4 animate-pulse'></i>
                                <audio x-ref="masterAudio" src="{{ $this->lesson->audio_url }}" class="hidden"></audio>
                            </div>
                        @endif

                        {{-- Active Segment Banner --}}
                        @if($this->currentStudentSegment)
                            <div class="p-6 border-t border-slate-100 bg-white">
                                <div class="flex items-center justify-between text-xs font-semibold text-slate-400 mb-2">
                                    <span>Segment {{ $this->currentStudentSegment->segment_index }} / {{ count($this->studentSegments) }}</span>
                                    <span>{{ sprintf('%02d:%02d', floor($this->currentStudentSegment->start_ms / 1000 / 60), floor(($this->currentStudentSegment->start_ms / 1000) % 60)) }} - {{ sprintf('%02d:%02d', floor($this->currentStudentSegment->end_ms / 1000 / 60), floor(($this->currentStudentSegment->end_ms / 1000) % 60)) }}</span>
                                </div>

                                {{-- Main Active Transcript --}}
                                <div class="text-xl md:text-2xl font-bold text-slate-800 leading-snug">
                                    @if($practiceMode === 'CHALLENGE' && !$this->revealedInChallenge)
                                        <div class="py-2 flex items-center space-x-2 text-slate-400 font-mono tracking-widest">
                                            <span>••••••••••••••••••••••••••••••••••••</span>
                                            <button wire:click="revealChallengeTranscript" class="text-xs font-bold text-[#696cff] hover:underline normal-case">Hiện phụ đề</button>
                                        </div>
                                    @else
                                        <p>{{ $this->currentStudentSegment->transcript }}</p>
                                        @if($this->currentStudentSegment->translation)
                                            <p class="text-sm font-medium text-slate-500 mt-2">{{ $this->currentStudentSegment->translation }}</p>
                                        @endif
                                    @endif
                                </div>
                            </div>
                        @endif
                    </div>
                @endif
            </div>

            {{-- Floating Audio Controls Footer --}}
            <div class="h-20 border-t border-slate-200/80 bg-white px-8 flex items-center justify-between shrink-0 shadow-sm z-10">
                <div class="flex items-center space-x-4">
                    {{-- Loop Mode Button --}}
                    <button @click="cycleLoopMode()"
                        class="px-3 py-1.5 rounded-xl border border-slate-200/80 text-xs font-bold text-slate-700 hover:bg-slate-50 transition flex items-center space-x-1.5">
                        <i class='bx bx-repeat text-base text-[#696cff]'></i>
                        <span x-text="loopMode === 'once' ? 'Phát 1 lần' : (loopMode === 'loop_3' ? 'Lặp 3 lần' : 'Lặp vô tận')"></span>
                    </button>

                    {{-- Speed Button --}}
                    <button @click="adjustSpeed(0.25)"
                        class="px-3 py-1.5 rounded-xl border border-slate-200/80 text-xs font-bold text-slate-700 hover:bg-slate-50 transition">
                        <span x-text="playbackRate + 'x'"></span>
                    </button>
                </div>

                {{-- Center Controls: Prev / Play / Next / Replay --}}
                <div class="flex items-center space-x-4">
                    <button @click="prevSegment(); selectSegment(currentIndex)"
                        class="w-10 h-10 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-700 flex items-center justify-center transition"
                        title="Câu trước (Left Arrow)">
                        <i class='bx bx-[#696cff] bx-skip-previous text-xl'></i>
                    </button>

                    <button @click="togglePlay()"
                        class="w-12 h-12 rounded-full bg-[#696cff] hover:bg-[#5f61e6] text-white flex items-center justify-center shadow-md hover:scale-105 active:scale-95 transition"
                        title="Phát / Tạm dừng (Spacebar)">
                        <i class="bx text-2xl" :class="isPlaying ? 'bx-pause' : 'bx-play ml-0.5'"></i>
                    </button>

                    <button @click="nextSegment(); selectSegment(currentIndex)"
                        class="w-10 h-10 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-700 flex items-center justify-center transition"
                        title="Câu tiếp theo (Right Arrow)">
                        <i class='bx bx-skip-next text-xl'></i>
                    </button>

                    <button @click="replayCurrentSegment()"
                        class="w-10 h-10 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-600 flex items-center justify-center transition"
                        title="Phát lại câu này (Phím R)">
                        <i class='bx bx-refresh text-xl'></i>
                    </button>
                </div>

                {{-- Right Controls: Student Recording & Dual-Audio Comparison --}}
                <div class="flex items-center space-x-3">
                    {{-- Student Record Mic Button --}}
                    <button @click="toggleRecording()"
                        class="px-4 py-2 rounded-xl text-xs font-bold transition flex items-center space-x-2 shadow-xs"
                        :class="recordingState === 'recording' ? 'bg-red-500 text-white animate-pulse' : (recordingState === 'uploading' ? 'bg-amber-500 text-white cursor-wait' : 'bg-slate-900 hover:bg-slate-800 text-white')">
                        <i class="bx text-base" :class="recordingState === 'recording' ? 'bx-square' : 'bx-microphone'"></i>
                        <span x-text="recordingState === 'recording' ? 'Đang thu...' : (recordingState === 'uploading' ? 'Đang tải...' : 'Thu âm giọng tôi (M)')"></span>
                    </button>

                    {{-- Play Student Recording Button --}}
                    <button @click="playUserAudio()"
                        x-show="userAudioUrl"
                        class="px-4 py-2 rounded-xl text-xs font-bold bg-emerald-600 hover:bg-emerald-700 text-white transition flex items-center space-x-1.5 shadow-xs"
                        :class="isPlayingUserAudio ? 'ring-2 ring-emerald-400 animate-pulse' : ''">
                        <i class="bx text-base" :class="isPlayingUserAudio ? 'bx-pause' : 'bx-play'"></i>
                        <span>Giọng tôi</span>
                    </button>
                </div>
            </div>
        </div>

        {{-- Right: Scrollable Transcript Sidebar --}}
        <div x-show="showTranscriptPanel"
            class="w-96 border-l border-slate-200/80 bg-white flex flex-col shrink-0 shadow-xs transition-all duration-200">
            <div class="p-4 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
                <span class="text-xs font-bold text-slate-500 uppercase tracking-wider">Danh sách câu ({{ count($this->studentSegments) }})</span>
                <span class="text-xs font-medium text-slate-400">Tự động cuộn</span>
            </div>

            <div id="transcript-scroll-container" @scroll="onUserScroll()" class="flex-1 overflow-y-auto p-4 space-y-3">
                @foreach($this->studentSegments as $seg)
                    <div id="segment-card-{{ $seg->segment_index }}"
                        @click="selectSegment({{ $seg->segment_index }})"
                        class="p-4 rounded-xl border transition cursor-pointer {{ $seg->segment_index === $currentIndex ? 'border-[#696cff] bg-[#696cff]/5 shadow-xs' : 'border-slate-200/70 hover:border-slate-300 bg-white' }}">
                        <div class="flex items-center justify-between text-xs font-bold text-slate-400 mb-1.5">
                            <span>#{{ $seg->segment_index }}</span>
                            @if(isset($this->userRecordings[$seg->id]))
                                <span class="inline-flex items-center text-emerald-600 text-xs font-bold">
                                    <i class='bx bx-check-circle mr-1'></i> Đã thu âm
                                </span>
                            @endif
                        </div>
                        <p class="text-sm font-semibold text-slate-800 leading-snug">{{ $seg->transcript }}</p>
                        @if($seg->translation)
                            <p class="text-xs font-medium text-slate-500 mt-1.5">{{ $seg->translation }}</p>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</div>
