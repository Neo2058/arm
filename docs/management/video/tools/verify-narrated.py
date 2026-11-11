#!/usr/bin/env python3
"""Verify narrated deliverables locally. Requires PyAV, NumPy and FFmpeg."""
import argparse
import hashlib
import json
from pathlib import Path
import subprocess
import av
import numpy as np


def digest(file):
    result = hashlib.sha256()
    with file.open('rb') as source:
        for chunk in iter(lambda: source.read(1024 * 1024), b''):
            result.update(chunk)
    return result.hexdigest()


def pcm(ffmpeg, file, start, seconds=4):
    result = subprocess.run([
        ffmpeg, '-v', 'error', '-ss', str(start), '-i', str(file), '-t', str(seconds),
        '-vn', '-ac', '1', '-ar', '16000', '-f', 'f32le', 'pipe:1',
    ], check=True, stdout=subprocess.PIPE)
    return np.frombuffer(result.stdout, dtype='<f4').astype(np.float64)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('file', type=Path)
    parser.add_argument('--ffmpeg', required=True)
    args = parser.parse_args()
    directory = Path(__file__).resolve().parents[1]
    plan = json.loads((directory / 'NARRATED_CHAPTERS.json').read_text())
    alignment = json.loads((directory / 'NARRATION_ALIGNMENT.json').read_text())
    expected = sum(c['duration_frames'] for c in plan) / 30
    with av.open(str(args.file)) as container:
        assert len(container.streams.video) == len(container.streams.audio) == 1
        video, audio = container.streams.video[0], container.streams.audio[0]
        duration = float(container.duration / av.time_base)
        assert (video.width, video.height) == (1920, 1080)
        assert abs(float(video.average_rate) - 30) < .001
        assert abs(duration - expected) < .1
        report = dict(file=args.file.name, duration_seconds=duration, width=video.width,
                      height=video.height, fps=float(video.average_rate),
                      video_codec=video.codec_context.name, audio_codec=audio.codec_context.name,
                      sample_rate=audio.codec_context.sample_rate, audio_channels=audio.codec_context.channels)
    # Decode each scene midpoint and both ends, not just container headers.
    checks = [.5, expected - 1] + [(c['start_frame'] + c['end_frame']) / 60 for c in plan]
    with av.open(str(args.file)) as container:
        stream = container.streams.video[0]
        for second in checks:
            container.seek(round(second / stream.time_base), stream=stream, backward=True)
            frame = next((f for f in container.decode(stream) if float(f.time) >= second - .04), None)
            assert frame is not None, f'No decoded video at {second}'
    report['decoded_scene_checks'] = len(checks)
    original = directory.parent / 'recording.mp3'
    assert digest(original) == alignment['audio_sha256']
    audio_checks = []
    for second in [3, 100, 250, 400, 600, 610]:
        a, b = pcm(args.ffmpeg, original, second), pcm(args.ffmpeg, args.file, second)
        n = min(len(a), len(b))
        a, b = a[:n], b[:n]
        assert n >= 16000
        assert np.sqrt(np.mean(b * b)) > .0001, 'Silent narration'
        size = 1 << (2 * n - 1).bit_length()
        cross = np.fft.irfft(np.fft.rfft(b, size) * np.conj(np.fft.rfft(a, size)), size)
        offsets = np.arange(-1600, 1601)
        lag = int(offsets[np.argmax(cross[offsets % size])])
        if lag >= 0:
            aa, bb = a[:n-lag], b[lag:]
        else:
            aa, bb = a[-lag:], b[:n+lag]
        correlation = float(np.dot(aa, bb) / np.sqrt(np.dot(aa, aa) * np.dot(bb, bb)))
        assert abs(lag) / 16000 < .04 and correlation > .94, (second, lag, correlation)
        audio_checks.append(dict(second=second, offset_ms=lag / 16, correlation=correlation))
    report['audio_matches_original'] = audio_checks
    report['sha256'] = digest(args.file)
    destination = Path(str(args.file) + '.verification.json')
    destination.write_text(json.dumps(report, ensure_ascii=False, indent=2) + '\n')
    print(json.dumps(report, ensure_ascii=False, indent=2))


if __name__ == '__main__':
    main()
