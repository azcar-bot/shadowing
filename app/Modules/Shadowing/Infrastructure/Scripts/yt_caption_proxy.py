#!/usr/bin/env python3
"""
YouTube Caption Proxy Server

Runs on the HOST machine (not inside Docker) to bypass YouTube's IP blocking
of cloud/container IPs. The Laravel app inside Docker calls this proxy via HTTP.

Usage:
    python3 yt_caption_proxy.py [port]

Default port: 9876

Endpoints:
    GET /captions?video_id=<id>&lang=en
    GET /health
"""

import json
import sys
from http.server import HTTPServer, BaseHTTPRequestHandler
from urllib.parse import urlparse, parse_qs

from youtube_transcript_api import YouTubeTranscriptApi


class CaptionHandler(BaseHTTPRequestHandler):

    def do_GET(self):
        parsed = urlparse(self.path)

        if parsed.path == '/health':
            self._respond(200, {'status': 'ok', 'service': 'yt-caption-proxy'})
            return

        if parsed.path == '/captions':
            params = parse_qs(parsed.query)
            video_id = params.get('video_id', [None])[0]
            lang = params.get('lang', ['en'])[0]

            if not video_id:
                self._respond(400, {'success': False, 'error': 'Missing video_id parameter'})
                return

            result = self._fetch(video_id, lang)
            status = 200 if result['success'] else 404
            self._respond(status, result)
            return

        self._respond(404, {'error': 'Not found'})

    def _fetch(self, video_id: str, language: str) -> dict:
        try:
            api = YouTubeTranscriptApi()
            transcript_list = api.list(video_id)

            transcript = None
            is_manual = False

            # Prefer manual captions
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

            if transcript is None:
                try:
                    transcript = transcript_list.find_transcript([language])
                    is_manual = not transcript.is_generated
                except Exception:
                    return {'success': False, 'error': f'No {language} transcript for {video_id}'}

            data = transcript.fetch()
            items = []
            for snippet in data:
                start_ms = int(snippet.start * 1000)
                dur_ms = int(snippet.duration * 1000)
                text = snippet.text.strip()
                if text:
                    items.append({'text': text, 'start_ms': start_ms, 'end_ms': start_ms + dur_ms})

            if not items:
                return {'success': False, 'error': f'Empty transcript for {video_id}'}

            return {
                'success': True,
                'source': 'youtube_manual_caption' if is_manual else 'youtube_auto_caption',
                'language': transcript.language_code,
                'items': items,
            }

        except Exception as e:
            return {'success': False, 'error': f'{type(e).__name__}: {str(e)}'}

    def _respond(self, status: int, body: dict):
        self.send_response(status)
        self.send_header('Content-Type', 'application/json; charset=utf-8')
        self.end_headers()
        self.wfile.write(json.dumps(body, ensure_ascii=False).encode('utf-8'))

    def log_message(self, fmt, *args):
        video_match = ''
        if args and 'video_id=' in str(args[0]):
            video_match = str(args[0])
        sys.stderr.write(f"[yt-caption-proxy] {self.address_string()} - {fmt % args}\n")


def main():
    port = int(sys.argv[1]) if len(sys.argv) > 1 else 9876
    server = HTTPServer(('0.0.0.0', port), CaptionHandler)
    print(f"YouTube Caption Proxy running on http://0.0.0.0:{port}")
    print(f"   Health: http://localhost:{port}/health")
    print(f"   Usage:  http://localhost:{port}/captions?video_id=dbtN9HOOqhk&lang=en")
    print(f"   Press Ctrl+C to stop")
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print("\nShutting down...")
        server.shutdown()


if __name__ == '__main__':
    main()
