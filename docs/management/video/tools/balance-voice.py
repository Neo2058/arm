#!/usr/bin/env python3
"""Gentle speech EQ/compression and measured loudness normalization; video stream copy."""
import argparse
import json
from pathlib import Path
import subprocess

PRESET = ('highpass=f=65,'
          'equalizer=f=250:t=q:w=0.9:g=-1.3,'
          'equalizer=f=2800:t=q:w=0.8:g=0.8,'
          'acompressor=threshold=0.10:ratio=1.8:attack=18:release=180:knee=4:makeup=1:detection=rms')
TARGET = 'loudnorm=I=-16:TP=-1.5:LRA=7'


def execute(command):
    result = subprocess.run(command, stdout=subprocess.DEVNULL, stderr=subprocess.PIPE, text=True)
    if result.returncode:
        raise RuntimeError(result.stderr[-4000:])
    return result.stderr


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('source', type=Path)
    p.add_argument('destination', type=Path)
    p.add_argument('--ffmpeg', required=True)
    p.add_argument('--duration', type=float, required=True)
    p.add_argument('--wav', type=Path)
    a = p.parse_args()
    for file in [a.destination, a.wav]:
        if file and file.exists():
            p.error(f'Refusing to overwrite: {file}')
    analysis = execute([a.ffmpeg, '-hide_banner', '-nostdin', '-i', str(a.source), '-vn',
                        '-af', PRESET + ',' + TARGET + ':print_format=json', '-f', 'null', '-'])
    measured = json.JSONDecoder().raw_decode(analysis[analysis.rfind('{'):])[0]
    norm = (TARGET + ':measured_I=' + measured['input_i'] + ':measured_TP=' + measured['input_tp']
            + ':measured_LRA=' + measured['input_lra'] + ':measured_thresh=' + measured['input_thresh']
            + ':offset=' + measured['target_offset'] + ':linear=false:print_format=json')
    filters = PRESET + ',' + norm + ',aresample=48000,apad'
    command = [a.ffmpeg, '-hide_banner', '-nostdin', '-n', '-i', str(a.source)]
    command += ['-map', '0:v:0', '-map', '0:a:0', '-c:v', 'copy', '-c:a', 'aac', '-b:a', '192k',
                '-af', filters, '-t', str(a.duration), '-movflags', '+faststart', str(a.destination)]
    if a.wav:
        command += ['-map', '0:a:0', '-vn', '-c:a', 'pcm_s24le', '-af', filters,
                    '-t', str(a.duration), str(a.wav)]
    execute(command)
    report = dict(source=a.source.name, destination=a.destination.name, preset=PRESET,
                  target_lufs=-16, true_peak_limit_dbtp=-1.5, filters=filters,
                  analysis_after_eq_compression=measured, duration_seconds=a.duration,
                  video='stream copy', speech_speed='unchanged', pitch='unchanged')
    Path(str(a.destination) + '.processing.json').write_text(json.dumps(report, ensure_ascii=False, indent=2) + '\n')
    print('VOICE_BALANCED', a.destination)


if __name__ == '__main__':
    main()
