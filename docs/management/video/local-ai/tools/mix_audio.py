#!/usr/bin/env python3
"""Add narration to the local AI presentation without a video editor. Standard library only."""
import argparse
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tempfile
import wave

BASE = Path(__file__).resolve().parents[1]


def run(command):
    result = subprocess.run(command, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    if result.returncode:
        raise RuntimeError(result.stderr[-3000:])
    return result


def ffmpeg_path():
    paths = [os.environ.get('ARM_FFMPEG'), shutil.which('ffmpeg'), str(BASE / 'output/tools/ffmpeg')]
    for candidate in paths:
        if candidate and os.path.isfile(candidate) and os.access(candidate, os.X_OK):
            return candidate
    raise RuntimeError('FFmpeg не найден. Укажите ARM_FFMPEG или сохраните бинарник в output/tools/ffmpeg.')


def choose(script):
    result = run(['/usr/bin/osascript', '-e', script]).stdout.strip()
    if not result or result == 'false':
        raise RuntimeError('Выбор отменён.')
    return Path(result)


def source_wave(ffmpeg, source, destination):
    if not source.is_file():
        raise RuntimeError(f'Нет записи: {source}')
    run([ffmpeg, '-hide_banner', '-nostdin', '-v', 'error', '-n', '-i', str(source),
         '-vn', '-ar', '48000', '-ac', '1', '-c:a', 'pcm_s16le', str(destination)])
    with wave.open(str(destination), 'rb') as file:
        return file.getnframes() / file.getframerate()


def unused_output():
    first = BASE / 'output/ARM_local_AI_with_voice.mp4'
    candidate = first
    n = 2
    while candidate.exists():
        candidate = first.with_name(f'{first.stem}_{n:02}{first.suffix}')
        n += 1
    return candidate


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    group = parser.add_mutually_exclusive_group()
    group.add_argument('--single', type=Path, help='Одна дорожка, записанная по таймкодам')
    group.add_argument('--scene-dir', type=Path, help='Отдельные записи по SCENES.json')
    parser.add_argument('--video', type=Path, default=BASE / 'output/ARM_local_AI_silent.mp4')
    parser.add_argument('--output', type=Path)
    parser.add_argument('--check-only', action='store_true')
    args = parser.parse_args()
    if not args.single and not args.scene_dir:
        if sys.platform != 'darwin':
            parser.error('Укажите --single FILE или --scene-dir FOLDER')
        mode = run(['/usr/bin/osascript', '-e', 'choose from list {"Одна дорожка", "Записи по сценам"} with prompt "Как записана озвучка?" default items {"Записи по сценам"}']).stdout.strip()
        if mode == 'Одна дорожка':
            args.single = choose('POSIX path of (choose file with prompt "Выберите запись озвучки")')
        elif mode == 'Записи по сценам':
            args.scene_dir = choose('POSIX path of (choose folder with prompt "Выберите папку с записями сцен")')
        else:
            raise RuntimeError('Выбор отменён.')
    ffmpeg = ffmpeg_path()
    scenes = json.loads((BASE / 'SCENES.json').read_text())
    total = sum(scene['seconds'] for scene in scenes)
    if not args.video.is_file():
        raise RuntimeError(f'Не найден немой ролик: {args.video}')
    probe = subprocess.run([ffmpeg, '-hide_banner', '-i', str(args.video)], stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    match = re.search(r'Duration: (\d+):(\d+):([\d.]+)', probe.stderr)
    if not match:
        raise RuntimeError('Не удалось прочитать длительность видео.')
    video_duration = int(match[1]) * 3600 + int(match[2]) * 60 + float(match[3])
    if abs(video_duration - total) > .05:
        raise RuntimeError(f'Видео {video_duration} с не совпадает с планом {total} с.')
    with tempfile.TemporaryDirectory(prefix='arm-ai-voice-') as working:
        folder = Path(working)
        if args.single:
            source = folder / 'source.wav'
            seconds = source_wave(ffmpeg, args.single, source)
            if seconds > total:
                raise RuntimeError(f'Запись {seconds:.3f} с длиннее видео {total} с. Сократите запись или измените монтаж; обрезки не будет.')
            print(f'Одна запись: {seconds:.3f} с / {total} с. Остаток заполнится тишиной.')
            audio = folder / 'padded.wav'
            if not args.check_only:
                run([ffmpeg, '-hide_banner', '-nostdin', '-v', 'error', '-n', '-i', str(source),
                     '-af', 'apad', '-t', str(total), '-c:a', 'pcm_s16le', str(audio)])
        else:
            audio = folder / 'complete.wav'
            with wave.open(str(audio), 'wb') as joined:
                joined.setparams((1, 2, 48000, 0, 'NONE', 'not compressed'))
                for index, scene in enumerate(scenes, 1):
                    stem = f'{index:02}-{scene["code"].lower()}'
                    files = [p for p in args.scene_dir.glob(stem + '.*') if p.suffix.lower() in {'.wav', '.mp3', '.m4a', '.aac', '.flac', '.aiff', '.aif', '.ogg'}]
                    if len(files) != 1:
                        raise RuntimeError(f'Нужна ровно одна запись {stem} с аудиорасширением; найдено {len(files)}.')
                    source = folder / f'{index:02}.wav'
                    seconds = source_wave(ffmpeg, files[0], source)
                    if seconds > scene['seconds']:
                        raise RuntimeError(f'Сцена {index:02}: запись {seconds:.3f} с длиннее окна {scene["seconds"]} с. Сократите её; речь не обрезается.')
                    print(f'Сцена {index:02}: {seconds:.3f} с / {scene["seconds"]} с')
                    if args.check_only:
                        continue
                    with wave.open(str(source), 'rb') as file:
                        count = file.getnframes()
                        while True:
                            block = file.readframes(48000)
                            if not block:
                                break
                            joined.writeframesraw(block)
                    left = scene['seconds'] * 48000 - count
                    while left:
                        size = min(left, 48000)
                        joined.writeframesraw(b'\0\0' * size)
                        left -= size
        if args.check_only:
            print('Записи подходят по длительности. Видео не создавалось.')
            return
        destination = args.output or unused_output()
        if destination.exists():
            raise RuntimeError(f'Выходной файл уже существует: {destination}')
        destination.parent.mkdir(parents=True, exist_ok=True)
        run([ffmpeg, '-hide_banner', '-nostdin', '-v', 'error', '-n', '-i', str(args.video), '-i', str(audio),
             '-map', '0:v:0', '-map', '1:a:0', '-c:v', 'copy', '-c:a', 'aac', '-b:a', '192k',
             '-movflags', '+faststart', '-t', str(total), str(destination)])
        print(f'Готово: {destination}')


if __name__ == '__main__':
    try:
        main()
    except (RuntimeError, OSError, ValueError) as error:
        print(f'Ошибка: {error}', file=sys.stderr)
        sys.exit(1)
