#!/usr/bin/env python3
"""
YouTube Caption Fetcher - Helper script for AZEnglish Shadowing Module.

Uses youtube-transcript-api library to reliably fetch YouTube captions
that PHP HTTP scraping cannot access due to YouTube's bot protection (429).

Usage:
    python3 fetch_youtube_captions.py <video_id> [language_code]

Output:
    JSON to stdout with structure:
    {
        "success": true,
        "source": "youtube_manual_caption" | "youtube_auto_caption",
        "language": "en",
        "items": [
            {"text": "Hello world", "start_ms": 1200, "end_ms": 3400},
            ...
        ]
    }

    On error:
    {"success": false, "error": "Error message"}
"""

import json
import sys

try:
    from youtube_transcript_api import YouTubeTranscriptApi
except ImportError:
    print(json.dumps({'success': False, 'error': 'youtube-transcript-api not installed. Run: pip3 install youtube-transcript-api'}))
    sys.exit(1)

def fetch_captions(video_id: str, language: str = 'en') -> dict:

    try:
        api = YouTubeTranscriptApi()
        transcript_list = api.list(video_id)

        # Try to find manual (human-uploaded) transcript first
        transcript = None
        is_manual = False

        try:
            # Prefer manually created transcripts
            for t in transcript_list:
                if t.language_code.startswith(language) and not t.is_generated:
                    transcript = t
                    is_manual = True
                    break

            # Fallback to auto-generated
            if transcript is None:
                for t in transcript_list:
                    if t.language_code.startswith(language) and t.is_generated:
                        transcript = t
                        is_manual = False
                        break

            # Last resort: find_transcript with language preference
            if transcript is None:
                transcript = transcript_list.find_transcript([language])
                is_manual = not transcript.is_generated
        except Exception:
            transcript = transcript_list.find_transcript([language])
            is_manual = not transcript.is_generated

        if transcript is None:
            return {
                'success': False,
                'error': f'No {language} transcript found for video {video_id}',
            }

        data = transcript.fetch()

        items = []
        for snippet in data:
            start_ms = int(snippet.start * 1000)
            duration_ms = int(snippet.duration * 1000)
            text = snippet.text.strip()
            if text:
                items.append({
                    'text': text,
                    'start_ms': start_ms,
                    'end_ms': start_ms + duration_ms,
                })

        if not items:
            return {
                'success': False,
                'error': f'Transcript found but contains no text for video {video_id}',
            }

        return {
            'success': True,
            'source': 'youtube_manual_caption' if is_manual else 'youtube_auto_caption',
            'language': transcript.language_code,
            'items': items,
        }

    except Exception as e:
        error_type = type(e).__name__
        return {
            'success': False,
            'error': f'{error_type}: {str(e)}',
        }


if __name__ == '__main__':
    if len(sys.argv) < 2:
        print(json.dumps({'success': False, 'error': 'Usage: fetch_youtube_captions.py <video_id> [language]'}))
        sys.exit(1)

    video_id = sys.argv[1]
    language = sys.argv[2] if len(sys.argv) > 2 else 'en'

    result = fetch_captions(video_id, language)
    print(json.dumps(result, ensure_ascii=False))
    sys.exit(0 if result['success'] else 1)
