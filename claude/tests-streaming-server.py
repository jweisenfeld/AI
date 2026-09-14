"""Fake upstream model API that speaks both SSE dialects, for testing the proxy.

Routes:
  /anthropic        Anthropic Messages SSE
  /openai           OpenAI-compatible chat.completions SSE
  /openai-nousage   OpenAI SSE that never reports usage (tests estimation)
  /error            HTTP 400 JSON error (tests the pre-stream error path)
  /split            SSE deliberately cut mid-line across TCP writes
"""
import json
import time
from http.server import BaseHTTPRequestHandler, HTTPServer

PIECES = ["Here", " is", " code:\n```python\n", "def greet(n):\n", "    print(n)\n", "```"]
FULL = "".join(PIECES)


class Handler(BaseHTTPRequestHandler):
    def log_message(self, *args):
        pass

    def _sse_headers(self):
        self.send_response(200)
        self.send_header("Content-Type", "text/event-stream")
        self.send_header("Cache-Control", "no-cache")
        self.end_headers()

    def _w(self, s):
        self.wfile.write(s.encode("utf-8"))
        self.wfile.flush()

    def do_POST(self):
        length = int(self.headers.get("Content-Length", 0))
        body = json.loads(self.rfile.read(length) or "{}")
        path = self.path

        if path == "/error":
            payload = json.dumps({"error": {"type": "invalid_request_error",
                                            "message": "`temperature` is deprecated for this model."}})
            self.send_response(400)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(payload)))
            self.end_headers()
            self._w(payload)
            return

        if path == "/anthropic":
            self._sse_headers()
            self._w("event: message_start\ndata: " + json.dumps({
                "type": "message_start",
                "message": {"model": "claude-test-1", "usage": {"input_tokens": 11}},
            }) + "\n\n")
            for piece in PIECES:
                self._w("event: content_block_delta\ndata: " + json.dumps({
                    "type": "content_block_delta", "delta": {"type": "text_delta", "text": piece},
                }) + "\n\n")
                time.sleep(0.12)
            self._w("event: message_delta\ndata: " + json.dumps({
                "type": "message_delta", "delta": {"stop_reason": "end_turn"},
                "usage": {"output_tokens": 27},
            }) + "\n\n")
            return

        if path in ("/openai", "/openai-nousage"):
            # Echo back whether the caller asked for usage, so the test can prove
            # stream_options was actually sent.
            self._sse_headers()
            for piece in PIECES:
                self._w("data: " + json.dumps({
                    "model": "fake-model-1",
                    "choices": [{"delta": {"content": piece}, "finish_reason": None}],
                }) + "\n\n")
                time.sleep(0.12)
            self._w("data: " + json.dumps({
                "model": "fake-model-1",
                "choices": [{"delta": {}, "finish_reason": "stop"}],
            }) + "\n\n")
            if path == "/openai" and body.get("stream_options", {}).get("include_usage"):
                self._w("data: " + json.dumps({
                    "model": "fake-model-1", "choices": [],
                    "usage": {"prompt_tokens": 11, "completion_tokens": 27},
                }) + "\n\n")
            self._w("data: [DONE]\n\n")
            return

        if path == "/split":
            # Write SSE bytes in chunks that cut lines in half, to prove the
            # line splitter reassembles them.
            self._sse_headers()
            blob = ""
            for piece in PIECES:
                blob += "data: " + json.dumps({
                    "model": "fake-model-1",
                    "choices": [{"delta": {"content": piece}, "finish_reason": None}],
                }) + "\n\n"
            blob += "data: [DONE]\n\n"
            step = 7  # nothing lines up with a newline boundary
            for i in range(0, len(blob), step):
                self._w(blob[i:i + step])
                time.sleep(0.005)
            return

        self.send_response(404)
        self.end_headers()


if __name__ == "__main__":
    HTTPServer(("127.0.0.1", 8899), Handler).serve_forever()
