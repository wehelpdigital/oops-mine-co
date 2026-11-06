"""
The sounds of a piece arriving, on a live stream and on a shoppable video.

Made here in code, so there is nobody else's recording to credit or clear: bells and harps are made of
a few pure tones decaying at their own rates, which additive synthesis does well. (Drums, claps and
whooshes on their own do not, which is why the plain effects in the library are real recordings.)

    python .ftp-sync/tools/make-reveal-sounds.py

Writes wp-content/plugins/whd/assets/live-sounds/reveal/*.mp3, each levelled to -16 LUFS like the
library's other effects, and prints what it measured. Needs numpy, scipy and ffmpeg. Seeded, so running
it twice gives the same files. Two more come from the CC0 recordings already in fx/, trimmed to length.
"""
import json
import os
import re
import subprocess
import tempfile
import wave

import numpy as np
from scipy.signal import butter, fftconvolve, sosfilt

SR = 48000
FFMPEG = os.environ.get('FFMPEG', 'C:/ffmpeg/ffmpeg-2025/bin/ffmpeg.exe')
ROOT = os.path.join(os.path.dirname(__file__), '..', '..', 'wp-content', 'plugins', 'whd', 'assets', 'live-sounds')
OUT = os.path.join(ROOT, 'reveal')
TARGET = -16.0
PEAK_LIMIT = -1.5


def note(name):
    """A note by name, E6 = 1318.5 Hz."""
    steps = {'C': -9, 'C#': -8, 'D': -7, 'D#': -6, 'E': -5, 'F': -4, 'F#': -3, 'G': -2, 'G#': -1, 'A': 0, 'A#': 1, 'B': 2}
    return 440.0 * 2 ** ((steps[name[:-1]] + (int(name[-1]) - 4) * 12) / 12)


def times(seconds):
    return np.arange(int(seconds * SR)) / SR


def place(mix, mono, at, pan=0.0, gain=1.0):
    """Into the stereo mix at a time, panned at constant power."""
    start = int(at * SR)
    end = min(len(mix), start + len(mono))
    angle = (pan + 1) * np.pi / 4
    mix[start:end, 0] += mono[:end - start] * np.cos(angle) * gain
    mix[start:end, 1] += mono[:end - start] * np.sin(angle) * gain


def bell(freq, seconds=1.6, warm=False):
    """A struck glass bell: inharmonic partials, the high ones dying first, each a pair a hair apart so it shimmers."""
    parts = [(1, 1, 3.0), (2.0, .34, 5), (3.01, .12, 9)] if warm else [(1, 1, 3.4), (2.0, .55, 4.8), (3.01, .3, 7.5), (4.16, .17, 12), (5.43, .1, 18), (6.79, .05, 26)]
    t = times(seconds)
    out = np.zeros_like(t)
    for ratio, amp, decay in parts:
        f = freq * ratio
        if f > 17000:
            continue
        out += amp * np.exp(-decay * t) * (np.sin(2 * np.pi * f * t) + .5 * np.sin(2 * np.pi * f * 1.0013 * t + .7)) / 1.5
    return out * (1 - np.exp(-t / .0016))


def pluck(freq, seconds=1.5):
    """A plucked string: whole-number partials, a little noise at the moment of the pluck."""
    t = times(seconds)
    out = np.zeros_like(t)
    for n in range(1, 11):
        f = freq * n * (1 + .00012 * n * n)
        if f > 17000:
            break
        out += np.exp(-(1.9 + 1.25 * n) * t) * np.sin(2 * np.pi * f * t + n) / n ** 1.05
    return out * (1 - np.exp(-t / .0012))


def grains(mix, rng, count, start, spread, level, low=4200, high=10500):
    """Tiny high glints, thinning out: the sparkle over the top."""
    for _ in range(count):
        at = start + spread * rng.random() ** 1.6
        length = rng.uniform(.018, .06)
        t = times(length)
        f = rng.uniform(low, high)
        blip = np.sin(2 * np.pi * f * t) * np.hanning(len(t)) ** 2
        place(mix, blip, at, rng.uniform(-.8, .8), level * rng.uniform(.3, 1) * np.exp(-(at - start) * 2.6))


def room(mix, seconds=1.3, amount=.2, seed=3):
    """A soft room: decaying filtered noise, different in each ear, convolved in."""
    rng = np.random.default_rng(seed)
    t = times(seconds)
    shape = butter(2, [260, 6500], btype='bandpass', fs=SR, output='sos')
    wet = np.zeros_like(mix)
    for ch in range(2):
        tail = sosfilt(shape, rng.standard_normal(len(t))) * np.exp(-t / .3)
        tail = np.concatenate([np.zeros(int(.012 * SR)), tail])
        tail /= np.sqrt(np.sum(tail ** 2))
        wet[:, ch] = fftconvolve(mix[:, ch], tail)[:len(mix)]
    return mix + wet * amount


def finish(mix, fade=.09):
    """A fade at the end, so nothing stops with a click."""
    n = int(fade * SR)
    mix[-n:] *= np.linspace(1, 0, n)[:, None]
    return mix


def shimmer():
    """A rising arpeggio that opens into a held note, with sparkle over it: the default."""
    rng = np.random.default_rng(11)
    mix = np.zeros((int(1.7 * SR), 2))
    for i, name in enumerate(['E6', 'G#6', 'B6', 'E7']):
        place(mix, bell(note(name), 1.45), .07 * i, -.42 + .28 * i, .6 + .09 * i)
    place(mix, bell(note('E5'), 1.45, warm=True), .21, -.1, .42)
    place(mix, bell(note('B5'), 1.4, warm=True), .21, .15, .24)
    grains(mix, rng, 44, .08, 1.0, .1)
    return finish(room(mix, amount=.26))


def harp():
    """A quick run up a pentatonic scale on a plucked string."""
    mix = np.zeros((int(1.75 * SR), 2))
    scale = ['C5', 'D5', 'E5', 'G5', 'A5', 'C6', 'D6', 'E6', 'G6', 'A6', 'C7']
    for i, name in enumerate(scale):
        place(mix, pluck(note(name), 1.3), .036 * i, -.5 + i * .1, .5 + .018 * i)
    return finish(room(mix, amount=.24))


def whoosh_chime():
    """Air rushing in, with a bell as it lands."""
    rng = np.random.default_rng(5)
    mix = np.zeros((int(1.75 * SR), 2))
    length = int(.36 * SR)
    noise = rng.standard_normal(length)
    # A band of noise sweeping upwards, as a resonant filter moved by hand.
    low = band = 0.0
    swept = np.zeros(length)
    for i in range(length):
        k = i / length
        f = 2 * np.sin(np.pi * min(.24, (420 * (14 ** k)) / SR))
        low += f * band
        high = noise[i] - low - .62 * band
        band += f * high
        swept[i] = band
    swept *= (np.linspace(0, 1, length) ** 2.2) * np.minimum(1, (length - np.arange(length)) / (.015 * SR))
    swept /= np.max(np.abs(swept))
    place(mix, swept, 0, -.25, .55)
    place(mix, swept[::-1] * 0, 0)
    hit = .34
    for name, gain, pan in [('E6', .62, -.18), ('B6', .46, .2), ('E7', .34, 0)]:
        place(mix, bell(note(name), 1.35), hit, pan, gain)
    place(mix, bell(note('E5'), 1.35, warm=True), hit, 0, .44)
    grains(mix, rng, 36, hit, .9, .11)
    return finish(room(mix, amount=.28))


def door():
    """Two warm notes, like the bell over a shop door."""
    mix = np.zeros((int(1.75 * SR), 2))
    place(mix, bell(note('G#5'), 1.45, warm=True) + .3 * bell(note('G#6'), 1.45), 0, -.12, .8)
    place(mix, bell(note('E5'), 1.45, warm=True) + .3 * bell(note('E6'), 1.45), .27, .12, .85)
    return finish(room(mix, amount=.3))


def pop():
    """A quick round pop and a glint: the lightest of them."""
    rng = np.random.default_rng(7)
    mix = np.zeros((int(.95 * SR), 2))
    for at, base, top, gain in [(0, 280, 1120, .85), (.085, 520, 1660, .55)]:
        t = times(.14)
        freq = base + (top - base) * (1 - np.exp(-t / .022))
        place(mix, np.sin(2 * np.pi * np.cumsum(freq) / SR) * np.exp(-t * 26) * (1 - np.exp(-t / .001)), at, 0, gain)
    place(mix, bell(note('E7'), .75), .13, .15, .34)
    grains(mix, rng, 22, .1, .55, .1)
    return finish(room(mix, seconds=.7, amount=.16), fade=.06)


def write_wav(path, mix):
    pcm = (np.clip(mix, -1, 1) * 32767).astype('<i2')
    with wave.open(path, 'wb') as w:
        w.setnchannels(2)
        w.setsampwidth(2)
        w.setframerate(SR)
        w.writeframes(pcm.tobytes())


def measure(path):
    """Loudness and true peak, as ffmpeg's EBU R128 meter reads them."""
    run = subprocess.run([FFMPEG, '-hide_banner', '-nostats', '-i', path, '-af', 'ebur128=peak=true', '-f', 'null', '-'], capture_output=True, text=True, encoding='utf-8', errors='replace')
    summary = run.stderr[run.stderr.rfind('Summary:'):]
    return float(re.search(r'I:\s+(-?[\d.]+) LUFS', summary).group(1)), float(re.search(r'Peak:\s+(-?[\d.]+) dBFS', summary).group(1))


def level_and_save(key, source, tmp):
    """Levelled with a straight gain, so the attack stays as it was made, and never past the peak limit."""
    loud, peak = measure(source)
    gain = min(TARGET - loud, PEAK_LIMIT - peak)
    dest = os.path.join(OUT, key + '.mp3')
    subprocess.run([FFMPEG, '-v', 'error', '-y', '-i', source, '-af', 'volume=%.2fdB' % gain, '-ar', '48000', '-ac', '2', '-c:a', 'libmp3lame', '-b:a', '128k', dest], check=True)
    decoded = os.path.join(tmp, key + '-check.wav')
    subprocess.run([FFMPEG, '-v', 'error', '-y', '-i', dest, decoded], check=True)
    loud2, peak2 = measure(decoded)
    with wave.open(decoded) as w:
        seconds = w.getnframes() / w.getframerate()
    print('%-12s %.2f s  %6.1f LUFS  peak %5.1f dBTP  %5.1f KB' % (key, seconds, loud2, peak2, os.path.getsize(dest) / 1024))
    return seconds


def trimmed(source, seconds, tmp, key):
    """The start of a recording from fx/, faded out."""
    dest = os.path.join(tmp, key + '.wav')
    subprocess.run([FFMPEG, '-v', 'error', '-y', '-i', os.path.join(ROOT, source), '-t', str(seconds), '-af', 'afade=t=out:st=%.2f:d=0.3' % (seconds - .3), '-ar', str(SR), '-ac', '2', dest], check=True)
    return dest


def main():
    os.makedirs(OUT, exist_ok=True)
    made = [('shimmer', shimmer()), ('harp', harp()), ('whoosh-chime', whoosh_chime()), ('door', door()), ('pop', pop())]
    with tempfile.TemporaryDirectory() as tmp:
        for key, mix in made:
            path = os.path.join(tmp, key + '.wav')
            write_wav(path, mix / max(1e-6, np.max(np.abs(mix))) * .89)
            level_and_save(key, path, tmp)
        level_and_save('sparkle', trimmed('fx/sparkle.mp3', 1.7, tmp, 'sparkle-cut'), tmp)
        level_and_save('camera', trimmed('fx/camera-flash.mp3', 1.7, tmp, 'camera-cut'), tmp)


if __name__ == '__main__':
    main()
