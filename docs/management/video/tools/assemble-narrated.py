#!/usr/bin/env python3
"""Mux the full original narration; optionally prepare an editing MOV. No network access."""
import argparse
import json
from pathlib import Path
import shutil
import subprocess


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--ffmpeg', default=shutil.which('ffmpeg'))
    parser.add_argument('--picture', type=Path)
    parser.add_argument('--prores', action='store_true')
    args = parser.parse_args()
    if not args.ffmpeg:
        parser.error('Specify --ffmpeg /absolute/path/to/ffmpeg')
    folder = Path(__file__).resolve().parents[1]
    plan = json.loads((folder / 'NARRATED_CHAPTERS.json').read_text())
    duration = sum(row['duration_frames'] for row in plan) / 30
    picture = args.picture or folder / 'preview/narrated/ARM_narrated_picture.mp4'
    voice = folder.parent / 'recording.mp3'
    movie = folder / 'preview/ARM_overview_narrated.mp4'
    for file in [picture, voice]:
        if not file.is_file():
            parser.error(f'Missing input: {file}')
    # Refuse to overwrite existing user artifacts. Original MP3 and silent edition are never modified.
    subprocess.run([
        args.ffmpeg, '-hide_banner', '-nostdin', '-n', '-i', str(picture), '-i', str(voice),
        '-map', '0:v:0', '-map', '1:a:0', '-c:v', 'copy', '-c:a', 'aac', '-b:a', '192k',
        '-ar', '48000', '-af', 'apad', '-t', f'{duration:.9f}', '-movflags', '+faststart',
        '-metadata', 'title=АРМ ТЧ-15 — презентация с авторской озвучкой', str(movie),
    ], check=True)
    if args.prores:
        # Use the ORIGINAL MP3, not an extra AAC generation, for the PCM editing soundtrack.
        subprocess.run([
            args.ffmpeg, '-hide_banner', '-nostdin', '-n', '-i', str(picture), '-i', str(voice),
            '-map', '0:v:0', '-map', '1:a:0', '-c:v', 'prores_ks', '-profile:v', '2',
            '-pix_fmt', 'yuv422p10le', '-vendor', 'apl0', '-threads', '4',
            '-c:a', 'pcm_s16le', '-ar', '48000', '-af', 'apad', '-t', f'{duration:.9f}',
            str(folder / 'preview/ARM_overview_narrated_ProRes.mov'),
        ], check=True)
    print(f'ASSEMBLED seconds={duration:.6f} file={movie}')


if __name__ == '__main__':
    main()
