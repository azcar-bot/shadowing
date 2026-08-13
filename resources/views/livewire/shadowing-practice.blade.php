<div class="min-h-screen w-full bg-[#f5f5f9] text-slate-800 font-['Public_Sans',sans-serif] flex flex-col select-none"
    x-data="shadowingEngine({
        lessonType: '{{ $this->lesson?->media_type ?? 'audio' }}',
        youtubeId: '{{ $this->lesson?->youtube_video_id ?? '' }}',
        audioUrl: '{{ $this->lesson?->audio_url ?? '' }}',
        segments: {{ json_encode($this->studentSegments) }},
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

    {{-- Header Bar (Compact Focus Mode Header: h-14) --}}
    <header class="h-14 bg-white border-b border-slate-200/80 px-4 sm:px-6 flex items-center justify-between shrink-0 z-30 shadow-xs">
        <div class="flex items-center gap-3">
            <a href="{{ route('shadowing.index') }}" class="p-1.5 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 transition-all text-xs font-bold flex items-center gap-1 active:scale-95">
                <i class="bx bx-left-arrow-alt text-base"></i>
                <span class="hidden sm:inline">Trở về</span>
            </a>
            <div class="h-4 w-[1px] bg-slate-200"></div>
            <div class="flex items-center gap-2">
                <select wire:change="switchLesson($event.target.value)" class="text-xs font-bold bg-white hover:bg-slate-50 border border-slate-200 rounded-xl px-2 py-1 text-slate-800 focus:ring-2 focus:ring-[#696cff]/20 cursor-pointer shadow-2xs">
                    @foreach($this->availableLessons as $l)
                        <option value="{{ $l->code }}" {{ $l->code === $this->lessonCode ? 'selected' : '' }}>
                            {{ $l->title }} ({{ $l->level }})
                        </option>
                    @endforeach
                </select>
                <span class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-[#696cff]/10 text-[#696cff] border border-[#696cff]/20">
                    {{ $this->lesson?->level ?? '' }}
                </span>
            </div>
        </div>

        {{-- Practice Modes (3 Learning Modes) & Ẩn bản chép (UI Layout Option) --}}
        <div class="flex flex-wrap items-center gap-2">
            {{-- 3 LEARNING MODES (Segmented Pill Container) --}}
            <div class="flex items-center bg-slate-100 p-0.5 rounded-xl border border-slate-200/80 shadow-2xs">
                <button type="button" wire:click="setMode('LISTEN_REPEAT')"
                    class="px-3 py-1 rounded-lg text-xs font-bold transition-all {{ $practiceMode === 'LISTEN_REPEAT' ? 'bg-[#696cff] text-white shadow-xs' : 'text-slate-600 hover:text-slate-900' }}"
                    title="Luyện nghe và lặp lại từng câu theo mẫu">
                    Luyện có chữ
                </button>
                <button type="button" wire:click="setMode('SHADOWING')"
                    class="px-3 py-1 rounded-lg text-xs font-bold transition-all {{ $practiceMode === 'SHADOWING' ? 'bg-[#696cff] text-white shadow-xs' : 'text-slate-600 hover:text-slate-900' }}"
                    title="Nói đuổi theo người nói theo thời gian thực">
                    Shadowing
                </button>
                <button type="button" wire:click="setMode('CHALLENGE')"
                    class="px-3 py-1 rounded-lg text-xs font-bold transition-all {{ $practiceMode === 'CHALLENGE' ? 'bg-[#696cff] text-white shadow-xs' : 'text-slate-600 hover:text-slate-900' }}"
                    title="Nghe nhớ và tự nói lại trước khi mở đáp án">
                    Không nhìn chữ
                </button>
            </div>

            {{-- STANDALONE UI LAYOUT OPTION (Ẩn / Hiện bản chép) --}}
            <button type="button" @click="showTranscriptPanel = !showTranscriptPanel"
                :class="!showTranscriptPanel ? 'bg-purple-50 text-[#696cff] border-[#696cff]/40 shadow-xs' : 'bg-white text-slate-700 border-slate-200/80 hover:bg-slate-50'"
                class="hidden sm:flex px-3 py-1 rounded-xl border text-xs font-bold transition-all items-center gap-1.5 active:scale-95 shadow-2xs">
                <i class="bx" :class="showTranscriptPanel ? 'bx-dock-right text-[#696cff]' : 'bx-sidebar text-slate-400'"></i>
                <span x-text="showTranscriptPanel ? 'Ẩn bản chép' : 'Hiện bản chép'"></span>
            </button>
        </div>
    </header>

    @if(!$this->lesson)
    {{-- EMPTY STATE: No lesson selected or lesson not found --}}
    <div class="flex-1 flex items-center justify-center p-8">
        <div class="text-center space-y-4 max-w-md">
            <div class="w-16 h-16 rounded-2xl bg-slate-100 text-slate-400 flex items-center justify-center text-3xl mx-auto">
                <i class="bx bx-book-open"></i>
            </div>
            <h2 class="text-lg font-bold text-slate-700">Chưa chọn bài luyện</h2>
            <p class="text-sm text-slate-500">Vui lòng chọn một bài Shadowing từ danh sách hoặc tạo bài mới từ YouTube.</p>
            <a href="{{ route('shadowing.index') }}" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-[#696cff] hover:bg-[#5f61e6] text-white text-sm font-bold shadow-md shadow-[#696cff]/25 transition-all active:scale-95">
                <i class="bx bx-left-arrow-alt"></i>
                Quay về trang Shadowing
            </a>
        </div>
    </div>
    @else
    {{-- Main 2-Column Grid Workspace (LEFT 65% / RIGHT 35%) --}}
    <div class="max-w-[1600px] w-full mx-auto p-3 sm:p-4 grid grid-cols-1 lg:grid-cols-12 gap-4 items-start">

        {{-- LEFT COLUMN: Media Player + Compact Controls + Prominent Karaoke Card + Recording --}}
        <div :class="showTranscriptPanel ? 'lg:col-span-8' : 'lg:col-span-12'" class="space-y-3 transition-all duration-300">

            {{-- Media Player Area (Constrained Video Height max-h-[380px] on PC) --}}
            <div class="w-full bg-black rounded-2xl overflow-hidden shadow-sm relative transition-all duration-300"
                :class="largeVideo ? 'max-w-none max-h-[70vh]' : 'max-w-full max-h-[380px] mx-auto'">

                @if(($this->lesson?->media_type ?? 'audio') === 'youtube' && !empty($this->lesson?->youtube_video_id))
                    {{-- YouTube Player Iframe --}}
                    <div class="relative w-full aspect-video bg-black"
                         :class="largeVideo ? 'max-h-[70vh]' : 'max-h-[380px]'">
                        <iframe id="youtube-player"
                            class="w-full h-full border-0"
                            src="https://www.youtube-nocookie.com/embed/{{ $this->lesson?->youtube_video_id }}?enablejsapi=1&autoplay=0&rel=0&controls=1&modestbranding=1"
                            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                            allowfullscreen>
                        </iframe>
                    </div>
                @else
                    {{-- Audio Only Visualizer Banner --}}
                    <div class="p-6 text-center bg-gradient-to-br from-indigo-50/50 via-white to-purple-50/50 space-y-3">
                        <div class="w-12 h-12 rounded-2xl bg-[#696cff]/10 text-[#696cff] border border-[#696cff]/20 flex items-center justify-center text-2xl mx-auto shadow-xs">
                            🎙️
                        </div>
                        <div>
                            <h3 class="font-bold text-slate-900 text-base">{{ $this->lesson?->title ?? 'Chưa chọn bài' }}</h3>
                            <p class="text-xs text-slate-500 mt-0.5">Đoạn {{ $currentIndex }} / {{ count($this->studentSegments) }} · {{ $this->currentStudentSegment->speaker ?? 'Mẫu chuẩn' }}</p>
                        </div>
                        <audio x-ref="masterAudio" class="hidden" src="{{ $this->lesson?->audio_url ?? '' }}"></audio>
                    </div>
                @endif
            </div>

            {{-- Compact Control Toggles & Playback Action Buttons Bar (h-12 = 48px) --}}
            <div class="flex flex-wrap items-center justify-between gap-3 text-xs text-slate-600 bg-white px-3.5 py-2 rounded-xl border border-slate-200/80 shadow-2xs h-12">
                {{-- Left Toggles --}}
                <div class="flex items-center gap-4">
                    <label class="flex items-center gap-1.5 cursor-pointer select-none">
                        <input type="checkbox" x-model="autoPause" @change="onAutoPauseToggleChange()" class="sr-only peer">
                        <div class="w-8 h-4 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-3 after:w-3 after:transition-all peer-checked:bg-[#696cff] relative"></div>
                        <span class="font-bold text-slate-700 text-[11px]">Tự dừng</span>
                    </label>

                    <label class="flex items-center gap-1.5 cursor-pointer select-none">
                        <input type="checkbox" x-model="largeVideo" class="sr-only peer">
                        <div class="w-8 h-4 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-3 after:w-3 after:transition-all peer-checked:bg-[#696cff] relative"></div>
                        <span class="font-bold text-slate-700 text-[11px]">Video lớn</span>
                    </label>
                </div>

                {{-- Center Playback Action Buttons (Circular Outline Group) --}}
                <div class="flex items-center gap-1.5 bg-slate-100/80 p-1 rounded-full border border-slate-200">
                    <button type="button" @click="prevSegment()" class="w-7 h-7 rounded-full bg-white hover:bg-slate-50 text-slate-700 flex items-center justify-center transition-all shadow-2xs border border-slate-200 active:scale-95" title="Câu trước (←)">
                        <i class="bx bx-first-page text-base"></i>
                    </button>
                    <button type="button" @click="quickRewind(2.0)" class="w-7 h-7 rounded-full bg-white hover:bg-slate-50 text-slate-700 flex items-center justify-center transition-all shadow-2xs border border-slate-200 active:scale-95" title="Lùi 2 giây (Z)">
                        <span class="text-[10px] font-extrabold leading-none">↶2s</span>
                    </button>
                    <button type="button" @click="replayCurrentSegment()" class="w-7 h-7 rounded-full bg-white hover:bg-slate-50 text-slate-700 flex items-center justify-center transition-all shadow-2xs border border-slate-200 active:scale-95" title="Nghe lại câu (R)">
                        <i class="bx bx-refresh text-base"></i>
                    </button>
                    <button type="button" @click="togglePlay()" class="w-7 h-7 rounded-full bg-[#696cff] hover:bg-[#5f61e6] text-white flex items-center justify-center transition-all shadow-sm shadow-[#696cff]/25 active:scale-95" title="Phát / Dừng (Space)">
                        <i class="bx" :class="isPlaying ? 'bx-pause' : 'bx-play'" class="text-lg"></i>
                    </button>
                    <button type="button" @click="nextSegment()" class="w-7 h-7 rounded-full bg-white hover:bg-slate-50 text-slate-700 flex items-center justify-center transition-all shadow-2xs border border-slate-200 active:scale-95" title="Câu tiếp theo (→)">
                        <i class="bx bx-last-page text-base"></i>
                    </button>
                    <button type="button" @click="cycleLoopMode()"
                        :class="loopMode !== 'once' ? 'text-[#696cff] bg-purple-50 border-[#696cff]/30' : 'text-slate-500 bg-white border-slate-200'"
                        class="w-7 h-7 rounded-full flex items-center justify-center transition-all shadow-2xs border active:scale-95"
                        :title="loopMode === 'once' ? 'Nghe 1 lần (L)' : loopMode === 'loop_3' ? 'Lặp 3 lần (L)' : 'Lặp vô hạn (L)'">
                        <span class="text-[10px] font-black leading-none" x-text="loopMode === 'once' ? '1×' : loopMode === 'loop_3' ? '3×' : '∞'"></span>
                    </button>
                </div>

                {{-- Right Tools (Speed + Settings) --}}
                <div class="flex items-center gap-2">
                    <div class="flex items-center gap-1 text-slate-600">
                        <button type="button" @click="adjustSpeed(-0.25)" class="w-5 h-5 rounded bg-slate-100 hover:bg-slate-200 text-slate-600 flex items-center justify-center text-[11px] font-bold transition-all active:scale-90" title="Giảm tốc độ (-)">−</button>
                        <span class="text-[11px] font-extrabold text-slate-700 min-w-[32px] text-center tabular-nums" x-text="playbackRate.toFixed(2) + 'x'"></span>
                        <button type="button" @click="adjustSpeed(0.25)" class="w-5 h-5 rounded bg-slate-100 hover:bg-slate-200 text-slate-600 flex items-center justify-center text-[11px] font-bold transition-all active:scale-90" title="Tăng tốc độ (+)">+</button>
                    </div>
                </div>
            </div>

            {{-- PROMINENT KARAOKE SENTENCE CARD (CENTERPIECE OF SHADOWING WORKSPACE!) --}}
            <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-xs space-y-3 text-center transition-all duration-300">
                <div class="flex items-center justify-center gap-2">
                    <span class="px-2.5 py-0.5 rounded-full text-[11px] font-extrabold bg-purple-50 text-[#696cff] border border-purple-100">
                        CÂU #<span x-text="currentIndex"></span>
                    </span>
                </div>

                {{-- Word by Word Active Highlight --}}
                <div class="flex flex-wrap items-center justify-center gap-x-3 gap-y-2 py-1">
                    <template x-for="(word, wIdx) in (currentSegment() ? currentSegment().transcript.split(' ') : [])" :key="wIdx">
                        <div class="inline-flex flex-col items-center group cursor-pointer hover:scale-105 transition-transform">
                            <span class="text-xl sm:text-2xl font-extrabold text-slate-900 tracking-wide" x-text="word"></span>
                            <template x-if="showIpa && currentSegment() && currentSegment().ipa">
                                <span class="text-[11px] font-semibold text-[#696cff] mt-0.5 tracking-normal bg-purple-50 px-1 py-0.2 rounded-md border border-purple-100"
                                      x-text="(currentSegment().ipa.split(' ')[wIdx] || '').replace(/[\/\[\]]/g, '')"></span>
                            </template>
                        </div>
                    </template>
                </div>

                @if($practiceMode === 'CHALLENGE' && !$revealedInChallenge)
                    <div class="pt-1 flex justify-center">
                        <button type="button" wire:click="revealChallengeTranscript"
                            class="px-3.5 py-1.5 rounded-xl bg-purple-50 hover:bg-purple-100 text-[#696cff] border border-purple-200 text-xs font-bold transition-all shadow-2xs flex items-center gap-1.5 active:scale-95 cursor-pointer">
                            <i class="bx bx-show text-sm"></i>
                            <span>Hiện câu (Phím R)</span>
                        </button>
                    </div>
                @endif

                <template x-if="showTranslation && currentSegment() && currentSegment().translation_vi">
                    <p class="text-xs font-medium text-slate-500 italic pt-2 border-t border-slate-100"
                       x-text="'&quot;' + currentSegment().translation_vi + '&quot;'"></p>
                </template>
            </div>

            {{-- RECORDING & PLAYBACK BUTTONS BAR --}}
            <div class="space-y-1.5">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <button type="button"
                        @click="playUserAudio()"
                        :disabled="!userAudioUrl || recordingState === 'uploading'"
                        class="h-11 rounded-xl border border-slate-200 bg-white hover:bg-slate-50 disabled:opacity-40 disabled:cursor-not-allowed text-slate-700 font-bold text-xs shadow-xs transition-all flex items-center justify-center gap-2 active:scale-95">
                        <i class="bx text-lg text-[#696cff]" :class="isPlayingUserAudio ? 'bx-pause-circle text-rose-600' : 'bx-play-circle'"></i>
                        <span x-text="isPlayingUserAudio ? '⏸️ DỪNG GIỌNG TÔI' : '🎙️ GIỌNG TÔI (PHÁT LẠI)'"></span>
                    </button>

                    <button type="button"
                        @click="toggleRecording()"
                        :disabled="recordingState === 'uploading'"
                        :class="recordingState === 'recording' ? 'bg-rose-600 hover:bg-rose-700 animate-pulse' : recordingState === 'uploading' ? 'bg-amber-600' : 'bg-[#696cff] hover:bg-[#5f61e6]'"
                        class="h-11 rounded-xl text-white font-bold text-xs shadow-md shadow-[#696cff]/25 transition-all flex items-center justify-center gap-2 active:scale-95 disabled:opacity-50">
                        <i class="bx text-lg" :class="recordingState === 'recording' ? 'bx-stop-circle' : recordingState === 'uploading' ? 'bx-loader-alt animate-spin' : 'bx-microphone'"></i>
                        <span x-text="recordingState === 'recording' ? 'ĐANG GHI ÂM (BẤM ĐỂ DỪNG)...' : recordingState === 'uploading' ? 'ĐANG TẢI LÊN...' : '🎙️ GHI ÂM GIỌNG TÔI'"></span>
                    </button>
                </div>

                <template x-if="recordingErrorMessage">
                    <div class="p-2 rounded-lg bg-rose-50 border border-rose-200 text-rose-700 text-xs text-center font-semibold flex items-center justify-center gap-1.5">
                        <i class="bx bx-error-circle text-base"></i>
                        <span x-text="recordingErrorMessage"></span>
                    </div>
                </template>

                <p class="text-[11px] text-center text-slate-400 font-medium">
                    <kbd class="px-1 py-0.5 bg-slate-100 border border-slate-200 rounded text-slate-600 font-bold">SPACE</kbd> Phát/Dừng Mẫu ·
                    <kbd class="px-1 py-0.5 bg-slate-100 border border-slate-200 rounded text-slate-600 font-bold">R</kbd> Nghe lại Mẫu ·
                    <kbd class="px-1 py-0.5 bg-slate-100 border border-slate-200 rounded text-slate-600 font-bold">←</kbd><kbd class="px-1 py-0.5 bg-slate-100 border border-slate-200 rounded text-slate-600 font-bold">→</kbd> Câu trước/sau ·
                    <kbd class="px-1 py-0.5 bg-slate-100 border border-slate-200 rounded text-slate-600 font-bold">M</kbd> Ghi âm Giọng tôi
                </p>
            </div>
        </div>

        {{-- RIGHT COLUMN (~35% = 4 cols): BÀN CHÉP Sidebar (Dynamic Compact Cards) --}}
        <aside x-show="showTranscriptPanel" class="lg:col-span-4 bg-white rounded-2xl border border-slate-200/80 shadow-xs flex flex-col overflow-hidden max-h-[calc(100vh-76px)] sticky top-16 transition-all duration-300">

            {{-- Panel Header --}}
            <div class="p-3.5 border-b border-slate-200/80 flex items-center justify-between gap-2 bg-slate-50/50">
                <div class="flex items-center gap-2">
                    <h2 class="text-xs font-black uppercase tracking-widest text-slate-800">BÀN CHÉP</h2>
                    <button type="button" wire:click="$toggle('weakOnlyFilter')"
                        class="px-2 py-0.5 rounded-lg font-bold transition-all border flex items-center gap-1 cursor-pointer select-none text-[11px]
                            {{ $weakOnlyFilter ? 'bg-amber-50 text-amber-700 border-amber-300' : 'bg-slate-100 text-slate-500 border-slate-200' }}">
                        <i class="bx bx-filter-alt text-xs"></i>
                        <span>{{ $weakOnlyFilter ? 'Tất cả' : 'Câu yếu' }}</span>
                    </button>
                </div>

                <div class="flex items-center gap-2 text-xs">
                    <button type="button" @click="showIpa = !showIpa"
                        :class="showIpa ? 'bg-purple-50 text-[#696cff] border-purple-200' : 'bg-slate-100 text-slate-500 border-slate-200'"
                        class="px-2 py-0.5 rounded-lg font-bold transition-all border flex items-center gap-1 cursor-pointer select-none text-[11px]">
                        <i class="bx" :class="showIpa ? 'bx-show' : 'bx-hide'"></i> IPA
                    </button>
                    <button type="button" @click="showTranslation = !showTranslation"
                        :class="showTranslation ? 'bg-purple-50 text-[#696cff] border-purple-200' : 'bg-slate-100 text-slate-500 border-slate-200'"
                        class="px-2 py-0.5 rounded-lg font-bold transition-all border flex items-center gap-1 cursor-pointer select-none text-[11px]">
                        <i class="bx" :class="showTranslation ? 'bx-show' : 'bx-hide'"></i> Trans
                    </button>
                    <span class="px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-800 border border-emerald-300 text-[11px] font-bold">
                        {{ round(($this->completedCount / max(1, count($this->studentSegments))) * 100) }}%
                    </span>
                </div>
            </div>

            {{-- Scrollable List of Segment Cards (Compact Inactive / Expanded Active) --}}
            <div id="transcript-scroll-container" @scroll="handleUserScroll()" class="flex-1 overflow-y-auto p-3 space-y-2 custom-scrollbar">
                @foreach($this->studentSegments as $seg)
                    @php
                        $attempt = $userAttempts[$seg->segment_index] ?? null;
                        $masteryStatus = $attempt['mastery_status'] ?? 'unseen';
                        $isMastered = $masteryStatus === 'mastered';
                    @endphp
                    <div id="segment-card-{{ $seg->segment_index }}" @click="goToSegment({{ $seg->segment_index }})"
                        :class="currentIndex === {{ $seg->segment_index }} ? 'bg-purple-50/60 border-[#696cff] ring-2 ring-[#696cff]/20 shadow-xs p-3.5' : 'bg-white border-slate-200/80 hover:border-indigo-300 hover:shadow-xs p-2.5'"
                        class="rounded-xl border transition-all cursor-pointer relative group text-left space-y-1
                            {{ $weakOnlyFilter && $isMastered ? 'opacity-40' : '' }}">

                        <div class="flex items-center justify-between gap-2">
                            <div class="flex items-center gap-1.5">
                                {{-- Mastery Status Dot Badge --}}
                                <span class="w-2 h-2 rounded-full shrink-0
                                    @switch($masteryStatus)
                                        @case('mastered')    bg-emerald-500 @break
                                        @case('needs_review') bg-amber-500 @break
                                        @case('practicing')  bg-yellow-400 @break
                                        @default             bg-slate-300
                                    @endswitch"
                                    title="{{ match($masteryStatus) {
                                        'mastered' => 'Tốt',
                                        'needs_review' => 'Cần ôn',
                                        'practicing' => 'Đang luyện',
                                        default => 'Chưa học'
                                    } }}"></span>
                                <span :class="currentIndex === {{ $seg->segment_index }} ? 'text-[#696cff] bg-[#696cff]/15' : 'text-slate-500 bg-slate-100'" class="px-2 py-0.5 rounded-md text-[11px] font-black">#{{ $seg->segment_index }}</span>
                            </div>
                            @if($attempt && ($attempt['practice_count'] ?? 0) > 0)
                                <span class="text-[10px] font-bold text-slate-400 tabular-nums">{{ $attempt['practice_count'] }}×</span>
                            @endif
                        </div>

                        {{-- English Transcript Text --}}
                        <p class="text-xs font-bold text-slate-800 leading-snug">
                            {{ $seg->transcript }}
                        </p>

                        {{-- IPA (Shown if active OR if global showIpa is toggled ON) --}}
                        @if(!empty($seg->ipa))
                            <p x-show="showIpa || currentIndex === {{ $seg->segment_index }}" class="text-[11px] font-mono text-[#696cff]">
                                {{ $seg->ipa }}
                            </p>
                        @endif

                        {{-- Vietnamese Translation Subtext (Shown if active OR if global showTranslation is toggled ON) --}}
                        @if(!empty($seg->translation_vi))
                            <p x-show="showTranslation || currentIndex === {{ $seg->segment_index }}" class="text-xs italic text-slate-500 font-medium">
                                {{ $seg->translation_vi }}
                            </p>
                        @endif
                    </div>
                @endforeach
            </div>

        </aside>

    </div>

    {{-- Audio Player & Web MediaRecorder Controller Script --}}
    <script>
        function shadowingEngine(config) {
            return {
                lessonType: config.lessonType,
                youtubeId: config.youtubeId,
                audioUrl: config.audioUrl,
                segments: config.segments,
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

                    const rec = this.$wire && this.$wire.userRecordings ? this.$wire.userRecordings[seg.id] : null;
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

                async toggleRecording() {
                    if (this.recordingState === 'recording') {
                        this.stopRecording();
                    } else {
                        await this.startRecording();
                    }
                },

                async startRecording() {
                    this.recordingErrorMessage = '';
                    if (typeof MediaRecorder === 'undefined' || !navigator.mediaDevices?.getUserMedia) {
                        this.recordingState = 'error';
                        this.recordingErrorMessage = 'Trình duyệt của bạn không hỗ trợ ghi âm trực tiếp. Vui lòng sử dụng Chrome, Edge hoặc Safari.';
                        return;
                    }

                    // 1. Pause sample YouTube / audio playback before recording starts
                    this.pausePlayback();
                    this.stopUserAudio();

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

                        this.mediaRecorder.onstop = async () => {
                            const recordedMime = this.mediaRecorder.mimeType || mimeType || 'audio/webm';
                            const audioBlob = new Blob(this.audioChunks, { type: recordedMime });
                            this.userAudioUrl = URL.createObjectURL(audioBlob);

                            // Stop tracks
                            stream.getTracks().forEach(track => track.stop());

                            await this.uploadRecordingBlob(audioBlob, recordedMime);
                        };

                        this.mediaRecorder.start();
                        this.isRecording = true;
                        this.recordingState = 'recording';
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

                async uploadRecordingBlob(audioBlob, mimeType) {
                    const seg = this.currentSegment();
                    if (!seg || !this.$wire || !this.$wire.lesson) return;

                    this.recordingState = 'uploading';
                    this.recordingErrorMessage = '';

                    const formData = new FormData();
                    const ext = mimeType.includes('mp4') || mimeType.includes('m4a') ? 'm4a' : mimeType.includes('ogg') ? 'ogg' : mimeType.includes('wav') ? 'wav' : 'webm';
                    formData.append('audio', audioBlob, `recording.${ext}`);
                    formData.append('lesson_id', this.$wire.lesson.id);
                    formData.append('segment_id', seg.id);
                    formData.append('duration_ms', 4000);

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

                            // Update Livewire userRecordings array locally
                            if (this.$wire.userRecordings) {
                                this.$wire.userRecordings[seg.id] = {
                                    public_id: data.public_id,
                                    playback_url: data.playback_url,
                                    duration_ms: data.duration_ms,
                                    size_bytes: data.size_bytes,
                                };
                            }
                            this.$wire.recordAttempt(null, data.playback_url, data.duration_ms);
                        } else {
                            this.recordingState = 'error';
                            this.recordingErrorMessage = data.message || 'Lỗi tải lên file ghi âm.';
                        }
                    } catch (err) {
                        this.recordingState = 'error';
                        this.recordingErrorMessage = 'Lỗi kết nối khi tải lên file ghi âm. File ghi âm bản địa tạm thời vẫn được giữ lại.';
                    }
                },

                playUserAudio() {
                    if (!this.userAudioUrl) return;

                    if (this.isPlayingUserAudio && this.userAudioElement) {
                        this.stopUserAudio();
                        return;
                    }

                    // 1. Pause sample YouTube / Audio playback when playing student recording
                    this.pausePlayback();

                    this.userAudioElement = new Audio(this.userAudioUrl);
                    this.isPlayingUserAudio = true;

                    this.userAudioElement.onended = () => {
                        this.isPlayingUserAudio = false;
                    };
                    this.userAudioElement.onpause = () => {
                        this.isPlayingUserAudio = false;
                    };
                    this.userAudioElement.onerror = () => {
                        this.isPlayingUserAudio = false;
                    };

                    this.userAudioElement.play();
                },

                stopUserAudio() {
                    if (this.userAudioElement) {
                        try {
                            this.userAudioElement.pause();
                            this.userAudioElement.currentTime = 0;
                        } catch(e) {}
                        this.userAudioElement = null;
                    }
                    this.isPlayingUserAudio = false;
                },

                goToSegment(index) {
                    if (index < 1 || index > this.segments.length) return;
                    this.currentIndex = index;
                    this.updateRecordingForActiveSegment();
                    this.playCurrentSegment();
                },

                prevSegment() {
                    let target = this.currentIndex - 1;
                    if (this.$wire && this.$wire.weakOnlyFilter) {
                        while (target >= 1) {
                            const attempt = this.$wire.userAttempts?.[target];
                            if (!attempt || attempt.mastery_status !== 'mastered') break;
                            target--;
                        }
                    }
                    if (target >= 1) {
                        this.currentIndex = target;
                        this.loopCounter = 0;
                        this.updateRecordingForActiveSegment();
                        this.playCurrentSegment();
                    }
                },

                nextSegment() {
                    let target = this.currentIndex + 1;
                    if (this.$wire && this.$wire.weakOnlyFilter) {
                        while (target <= this.segments.length) {
                            const attempt = this.$wire.userAttempts?.[target];
                            if (!attempt || attempt.mastery_status !== 'mastered') break;
                            target++;
                        }
                    }
                    if (target <= this.segments.length) {
                        this.currentIndex = target;
                        this.loopCounter = 0;
                        this.updateRecordingForActiveSegment();
                        this.playCurrentSegment();
                    }
                },

                initYouTubePlayer() {
                    if (window.YT && window.YT.Player) {
                        this.createYTPlayer();
                    } else {
                        const tag = document.createElement('script');
                        tag.src = "https://www.youtube.com/iframe_api";
                        document.head.appendChild(tag);
                        window.onYouTubeIframeAPIReady = () => this.createYTPlayer();
                    }
                },

                createYTPlayer() {
                    this.ytPlayer = new YT.Player('youtube-player', {
                        events: {
                            'onStateChange': (e) => {
                                this.isPlaying = (e.data === YT.PlayerState.PLAYING);
                                if (this.isPlaying) {
                                    this.syncPlaybackStateOnPlay();
                                    this.startTimerCheck();
                                } else {
                                    this.stopTimerCheck();
                                }
                            }
                        }
                    });
                },

                syncPlaybackStateOnPlay() {
                    if (!this.autoPause) {
                        this.playbackMode = 'continuous';
                        this.autoPauseArmed = false;
                        this.targetEndMs = null;
                        return;
                    }

                    let currentSec = 0;
                    if (this.lessonType === 'youtube' && this.ytPlayer && typeof this.ytPlayer.getCurrentTime === 'function') {
                        currentSec = this.ytPlayer.getCurrentTime();
                    } else if (this.$refs.masterAudio) {
                        currentSec = this.$refs.masterAudio.currentTime;
                    }
                    const currentMs = currentSec * 1000;

                    // Check if currentMs is inside an active chunk or in a gap
                    const activeSeg = this.segments.find(s => currentMs >= (s.start_ms - 200) && currentMs < (s.end_ms + this.postPaddingMs - 30));

                    if (activeSeg) {
                        this.playbackMode = 'chunk_practice';
                        this.autoPauseArmed = true;
                        this.targetEndMs = activeSeg.end_ms + this.postPaddingMs;
                        this.currentIndex = activeSeg.segment_index;
                    } else {
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
                        // MANUAL PLAY BUTTON / SPACEBAR: RESUME CURRENT TIMELINE
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
                    this.loopCounter = 0;
                    this.playCurrentSegment();
                },

                quickRewind(seconds = 2.0) {
                    let currentSec = 0;
                    if (this.lessonType === 'youtube' && this.ytPlayer && typeof this.ytPlayer.getCurrentTime === 'function') {
                        currentSec = this.ytPlayer.getCurrentTime();
                    } else if (this.$refs.masterAudio) {
                        currentSec = this.$refs.masterAudio.currentTime;
                    }
                    const targetSec = Math.max(0, currentSec - seconds);
                    if (this.lessonType === 'youtube' && this.ytPlayer) {
                        this.ytPlayer.seekTo(targetSec, true);
                    } else if (this.$refs.masterAudio) {
                        this.$refs.masterAudio.currentTime = targetSec;
                    }
                },

                adjustSpeed(delta) {
                    const newRate = Math.max(0.25, Math.min(2.0, this.playbackRate + delta));
                    this.playbackRate = parseFloat(newRate.toFixed(2));
                    this.changeSpeed(this.playbackRate);
                },

                cycleLoopMode() {
                    const modes = ['once', 'loop_3', 'loop_infinite'];
                    const idx = modes.indexOf(this.loopMode);
                    this.loopMode = modes[(idx + 1) % modes.length];
                    this.loopCounter = 0;
                    localStorage.setItem('shadowing_loop_mode', this.loopMode);
                },

                startTimerCheck() {
                    this.stopTimerCheck();
                    this.timerCheck = setInterval(() => {
                        let currentSec = 0;
                        if (this.lessonType === 'youtube' && this.ytPlayer && typeof this.ytPlayer.getCurrentTime === 'function') {
                            currentSec = this.ytPlayer.getCurrentTime();
                        } else if (this.$refs.masterAudio) {
                            currentSec = this.$refs.masterAudio.currentTime;
                        }

                        const currentMs = currentSec * 1000;

                        // SEEK GUARD: Ignore auto-pause & card sync while YouTube is seeking
                        if (this.isSeeking) {
                            if (this.expectedStartMs !== null && Math.abs(currentMs - this.expectedStartMs) < 600) {
                                this.isSeeking = false;
                                this.autoPauseArmed = true; // Seek complete! Arm auto-pause now!
                            }
                            return;
                        }

                        // AWAITING NEXT CHUNK AUTO-ARMING:
                        // If autoPause is ON and we are in a gap / awaiting_next_chunk, as soon as video timeline enters a chunk, auto-arm it!
                        if (this.autoPause && (!this.autoPauseArmed || this.playbackMode === 'awaiting_next_chunk')) {
                            const upcomingSeg = this.segments.find(s => currentMs >= (s.start_ms - 200) && currentMs < (s.end_ms + this.postPaddingMs - 30));
                            if (upcomingSeg) {
                                this.playbackMode = 'chunk_practice';
                                this.autoPauseArmed = true;
                                this.targetEndMs = upcomingSeg.end_ms + this.postPaddingMs;
                                this.currentIndex = upcomingSeg.segment_index;
                            }
                        }

                        // 1. High-precision Auto-pause / Loop ONLY IF ARMED and "Tự dừng mỗi đoạn" is ON
                        if (this.autoPause && this.autoPauseArmed && this.targetEndMs) {
                            if (currentMs >= (this.targetEndMs - 30)) {
                                if (this.loopMode === 'loop_infinite') {
                                    // Infinite loop: seek back to chunk start, never auto-stop
                                    this.playCurrentSegment();
                                } else if (this.loopMode === 'loop_3') {
                                    this.loopCounter++;
                                    if (this.loopCounter >= 3) {
                                        this.pausePlayback();
                                        this.loopCounter = 0;
                                        this.autoPauseArmed = false;
                                        this.targetEndMs = null;
                                        this.scrollToActiveCard(this.currentIndex);
                                    } else {
                                        // Replay chunk (loop iteration)
                                        this.playCurrentSegment();
                                    }
                                } else {
                                    // 'once' mode: pause at chunk end (original behavior)
                                    this.pausePlayback();
                                    this.autoPauseArmed = false;
                                    this.targetEndMs = null;
                                    this.scrollToActiveCard(this.currentIndex);
                                }
                                return;
                            }
                        }

                        // 2. Continuous 2-way Video Timestamp to Active Sentence Card Sync (when playing)
                        if (this.isPlaying) {
                            const matchedSeg = this.segments.find(s => currentMs >= s.start_ms && currentMs < s.end_ms);
                            if (matchedSeg && matchedSeg.segment_index !== this.currentIndex) {
                                this.currentIndex = matchedSeg.segment_index;
                                this.scrollToActiveCard(this.currentIndex);
                            }
                        }
                    }, 50);
                },

                scrollToActiveCard(index, force = false) {
                    // If user manually scrolled within the last 4 seconds, pause auto-scroll unless forced by explicit click
                    if (!force && (Date.now() - this.userScrolledTimestamp) < 4000) {
                        return;
                    }

                    setTimeout(() => {
                        const cardEl = document.getElementById('segment-card-' + index);
                        const container = document.getElementById('transcript-scroll-container');

                        if (cardEl && container) {
                            const containerTop = container.getBoundingClientRect().top;
                            const containerHeight = container.clientHeight;
                            const cardTop = cardEl.getBoundingClientRect().top;
                            const cardHeight = cardEl.clientHeight;

                            const relativeTop = cardTop - containerTop;
                            const targetScrollTop = container.scrollTop + relativeTop - (containerHeight * 0.50) + (cardHeight / 2);

                            container.scrollTo({
                                top: Math.max(0, targetScrollTop),
                                behavior: 'smooth'
                            });
                        }
                    }, 50);
                },

                handleUserScroll() {
                    this.userScrolledTimestamp = Date.now();
                },

                stopTimerCheck() {
                    if (this.timerCheck) {
                        clearInterval(this.timerCheck);
                        this.timerCheck = null;
                    }
                },

                goToSegment(index) {
                    if (index < 1 || index > this.segments.length) return;
                    this.currentIndex = index;
                    this.playCurrentSegment();
                },

                prevSegment() {
                    let target = this.currentIndex - 1;
                    // Skip mastered segments when weak filter is active
                    if (this.$wire && this.$wire.weakOnlyFilter) {
                        while (target >= 1) {
                            const attempt = this.$wire.userAttempts?.[target];
                            if (!attempt || attempt.mastery_status !== 'mastered') break;
                            target--;
                        }
                    }
                    if (target >= 1) {
                        this.currentIndex = target;
                        this.loopCounter = 0;
                        this.playCurrentSegment();
                    }
                },

                nextSegment() {
                    let target = this.currentIndex + 1;
                    // Skip mastered segments when weak filter is active
                    if (this.$wire && this.$wire.weakOnlyFilter) {
                        while (target <= this.segments.length) {
                            const attempt = this.$wire.userAttempts?.[target];
                            if (!attempt || attempt.mastery_status !== 'mastered') break;
                            target++;
                        }
                    }
                    if (target <= this.segments.length) {
                        this.currentIndex = target;
                        this.loopCounter = 0;
                        this.playCurrentSegment();
                    }
                },

                changeSpeed(rate) {
                    this.playbackRate = parseFloat(rate);
                    if (this.lessonType === 'youtube' && this.ytPlayer && typeof this.ytPlayer.setPlaybackRate === 'function') {
                        this.ytPlayer.setPlaybackRate(this.playbackRate);
                    } else if (this.$refs.masterAudio) {
                        this.$refs.masterAudio.playbackRate = this.playbackRate;
                    }
                },

                async toggleRecording() {
                    if (this.isRecording) {
                        this.stopRecording();
                    } else {
                        await this.startRecording();
                    }
                },

                async startRecording() {
                    try {
                        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                        this.mediaRecorder = new MediaRecorder(stream);
                        this.audioChunks = [];

                        this.mediaRecorder.ondataavailable = (e) => {
                            if (e.data.size > 0) this.audioChunks.push(e.data);
                        };

                        this.mediaRecorder.onstop = () => {
                            const audioBlob = new Blob(this.audioChunks, { type: 'audio/webm' });
                            this.userAudioUrl = URL.createObjectURL(audioBlob);
                            @this.recordAttempt(null, this.userAudioUrl, 4000);
                        };

                        this.mediaRecorder.start();
                        this.isRecording = true;
                        this.playCurrentSegment();
                    } catch (err) {
                        alert('Không thể truy cập Microphone trên trình duyệt.');
                    }
                },

                stopRecording() {
                    if (this.mediaRecorder && this.isRecording) {
                        this.mediaRecorder.stop();
                        this.isRecording = false;
                    }
                },

                playUserAudio() {
                    if (this.userAudioUrl) {
                        const audio = new Audio(this.userAudioUrl);
                        audio.play();
                    }
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
    @endif
</div>
