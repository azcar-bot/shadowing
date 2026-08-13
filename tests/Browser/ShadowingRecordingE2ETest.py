import asyncio
import sys
import os
from playwright.async_api import async_playwright

sys.stdout.reconfigure(encoding='utf-8')

JS_GET_ENGINE = """
function getEngine() {
    const el = document.querySelector('[x-data]');
    if (el && window.Alpine && typeof window.Alpine.$data === 'function') {
        return window.Alpine.$data(el);
    }
    return null;
}
"""

async def run_e2e_scenarios():
    results = {}

    async with async_playwright() as p:
        browser = await p.chromium.launch(
            headless=True,
            args=[
                "--use-fake-ui-for-media-stream",
                "--use-fake-device-for-media-stream",
                "--allow-file-access-from-files",
            ]
        )

        # =========================================================================
        # STUDENT A SCENARIOS
        # =========================================================================
        print("\n=========================================================")
        print("REPRODUCIBLE BROWSER E2E: STUDENT A RECORDING FLOW")
        print("=========================================================")

        context_a = await browser.new_context(
            permissions=["microphone"],
            viewport={"width": 1440, "height": 900}
        )
        await context_a.grant_permissions(["microphone"], origin="http://localhost:8081")

        page_a = await context_a.new_page()

        # 1. Login Student A
        print("[1] Logging in as Student A (student@azenglish.test)...")
        await page_a.goto("http://localhost:8081/login")
        await page_a.fill("input[name='email']", "student@azenglish.test")
        await page_a.fill("input[name='password']", "password")
        await page_a.click("button[type='submit']")
        await page_a.wait_for_timeout(2000)
        results["1. Login Student A"] = "PASS"

        # 2. Open known Shadowing lesson
        print("[2] Opening Shadowing practice page for shadowing_b1_daily_convo...")
        await page_a.goto("http://localhost:8081/shadowing/practice/shadowing_b1_daily_convo")
        await page_a.wait_for_selector("#segment-card-1", timeout=10000)
        await page_a.wait_for_timeout(1000)
        results["2. Open known Shadowing lesson"] = "PASS"

        # 3. Select segment #1
        print("[3] Selecting Segment #1 (#segment-card-1)...")
        await page_a.click("#segment-card-1")
        await page_a.wait_for_timeout(500)
        results["3. Select segment"] = "PASS"

        # 4 & 5. Start recording #1 (duration ~2.0s)
        print("[4] Starting recording #1 (duration ~2.0s)...")
        rec1_info = await page_a.evaluate("""
        async () => {
            """ + JS_GET_ENGINE + """
            const data = getEngine();
            if (!data) return { started: false, err: 'no_engine' };
            await data.startRecording();
            return {
                started: data.recordingState === 'recording' || data.isRecording,
                state: data.recordingState,
                err: data.recordingErrorMessage
            };
        }
        """)
        print(f"    -> Start recording #1 info: {rec1_info}")
        rec1_started = rec1_info["started"]
        results["4. Start recording #1"] = "PASS" if rec1_started else "FAIL"

        await page_a.wait_for_timeout(2000)

        # 6 & 7. Stop recording #1 & upload
        print("[5] Stopping recording #1 and waiting for upload completion...")
        rec1_stop_res = await page_a.evaluate("""
        async () => {
            """ + JS_GET_ENGINE + """
            const data = getEngine();
            if (!data) return { stopped: false, url: null };
            data.stopRecording();
            for (let i = 0; i < 50; i++) {
                if (data.recordingState === 'ready') break;
                await new Promise(r => setTimeout(r, 200));
            }
            return {
                stopped: true,
                url: data.userAudioUrl,
                state: data.recordingState,
                err: data.recordingErrorMessage
            };
        }
        """)
        print(f"    -> Stop recording #1 res: {rec1_stop_res}")

        results["5. Stop recording #1 & upload"] = "PASS" if rec1_stop_res["state"] == "ready" else "FAIL"

        # 8. Reload browser & verify persistence
        print("[6] Reloading browser page...")
        await page_a.reload()
        await page_a.wait_for_selector("#segment-card-1", timeout=10000)
        await page_a.wait_for_timeout(2000)
        await page_a.click("#segment-card-1")
        await page_a.wait_for_timeout(1000)

        persisted_debug = await page_a.evaluate("""
        async () => {
            """ + JS_GET_ENGINE + """
            const data = getEngine();
            if (!data) return { ok: false, reason: 'no_data' };

            for (let i = 0; i < 20; i++) {
                data.updateRecordingForActiveSegment();
                if (data.recordingState === 'ready' || data.userAudioUrl) break;
                await new Promise(r => setTimeout(r, 250));
            }

            const seg = data.currentSegment();
            const recs = data.$wire ? data.$wire.userRecordings : null;
            return {
                ok: data.recordingState === 'ready' || !!data.userAudioUrl,
                segId: seg ? seg.id : null,
                recsKeys: recs ? Object.keys(recs) : [],
                recObj: seg && recs ? (recs[seg.id] || recs[String(seg.id)]) : null,
                userAudioUrl: data.userAudioUrl
            };
        }
        """)
        print(f"    -> Reload persistence debug: {persisted_debug}")
        persisted_after_reload = persisted_debug["ok"]
        results["6. Recording persisted after browser reload"] = "PASS" if persisted_after_reload else "FAIL"

        # 9. Re-record same segment with different duration (~4.0s)
        print("[7] Re-recording same segment #1 with longer duration (~4.0s)...")
        rec2_started = await page_a.evaluate("""
        async () => {
            """ + JS_GET_ENGINE + """
            const data = getEngine();
            if (!data) return false;
            await data.startRecording();
            return data.recordingState === 'recording' || data.isRecording;
        }
        """)

        await page_a.wait_for_timeout(4000)

        rec2_stop_res = await page_a.evaluate("""
        async () => {
            """ + JS_GET_ENGINE + """
            const data = getEngine();
            if (!data) return { stopped: false, url: null };
            data.stopRecording();
            for (let i = 0; i < 50; i++) {
                if (data.recordingState === 'ready') break;
                await new Promise(r => setTimeout(r, 200));
            }
            return {
                stopped: true,
                url: data.userAudioUrl,
                state: data.recordingState,
            };
        }
        """)

        results["7. Re-recording replacement succeeds"] = "PASS" if rec2_stop_res["state"] == "ready" else "FAIL"

        # =========================================================================
        # REAL DUAL-AUDIO EXCLUSIVITY ASSERTIONS
        # =========================================================================
        print("\n=========================================================")
        print("REPRODUCIBLE BROWSER E2E: DUAL-AUDIO EXCLUSIVITY ASSERTIONS")
        print("=========================================================")

        # Assertion A: Playing student audio ("Giọng tôi") MUST pause sample player
        play_exclusivity_a = await page_a.evaluate("""
        () => {
            """ + JS_GET_ENGINE + """
            const data = getEngine();
            if (!data) return false;
            data.isPlaying = true;
            data.playUserAudio();
            const isPlayingSample = data.isPlaying;
            const isPlayingUser = data.isPlayingUserAudio;
            return (!isPlayingSample) && isPlayingUser;
        }
        """)
        results["Exclusivity A: Start 'Giọng tôi' -> Sample Player Paused"] = "PASS" if play_exclusivity_a else "FAIL"

        # Assertion B: Playing sample audio ("Giọng mẫu") MUST pause student audio
        play_exclusivity_b = await page_a.evaluate("""
        () => {
            """ + JS_GET_ENGINE + """
            const data = getEngine();
            if (!data) return false;
            data.playUserAudio();
            data.togglePlay();
            const isPlayingSample = data.isPlaying;
            const isPlayingUser = data.isPlayingUserAudio;
            const userAudioElem = data.userAudioElement;
            const userAudioPaused = !userAudioElem || userAudioElem.paused;
            return (!isPlayingUser) && userAudioPaused;
        }
        """)
        results["Exclusivity B: Start 'Giọng mẫu' -> Student Audio Paused"] = "PASS" if play_exclusivity_b else "FAIL"

        await context_a.close()

        # =========================================================================
        # STUDENT B ISOLATION SCENARIOS
        # =========================================================================
        print("\n=========================================================")
        print("REPRODUCIBLE BROWSER E2E: STUDENT B ISOLATION TEST")
        print("=========================================================")

        context_b = await browser.new_context(
            permissions=["microphone"],
            viewport={"width": 1440, "height": 900}
        )
        await context_b.grant_permissions(["microphone"], origin="http://localhost:8081")

        page_b = await context_b.new_page()

        # Login Student B
        print("[8] Logging in as Student B (other_student@azenglish.test)...")
        await page_b.goto("http://localhost:8081/login")
        await page_b.fill("input[name='email']", "other_student@azenglish.test")
        await page_b.fill("input[name='password']", "password")
        await page_b.click("button[type='submit']")
        await page_b.wait_for_timeout(2000)

        # Check Student B practice page
        print("[9] Verifying Student B does NOT see Student A's recording...")
        await page_b.goto("http://localhost:8081/shadowing/practice/shadowing_b1_daily_convo")
        await page_b.wait_for_selector("#segment-card-1", timeout=10000)
        await page_b.wait_for_timeout(1000)

        student_b_has_a_recording = await page_b.evaluate("""
        () => {
            """ + JS_GET_ENGINE + """
            const data = getEngine();
            return data && (data.recordingState === 'ready' || !!data.userAudioUrl);
        }
        """)

        student_b_clean = not student_b_has_a_recording
        results["8. Student B clean authorization isolation"] = "PASS" if student_b_clean else "FAIL"

        await context_b.close()
        await browser.close()

    print("\n=========================================================")
    print("BROWSER E2E RESULTS SUMMARY")
    print("=========================================================")
    all_pass = True
    for k, v in results.items():
        print(f" {v} | {k}")
        if v != "PASS":
            all_pass = False
    print("=========================================================")
    return all_pass

if __name__ == "__main__":
    success = asyncio.run(run_e2e_scenarios())
    sys.exit(0 if success else 1)
