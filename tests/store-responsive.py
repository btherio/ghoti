#!/usr/bin/env python3
"""Isolated Chromium smoke checks. Run: python tests/store-responsive.py.
Requires PHP and Chromium on PATH; never starts the app or contacts PayPal.
"""
import concurrent.futures
import pathlib
import re
import shutil
import subprocess
import tempfile

ROOT = pathlib.Path(__file__).resolve().parent.parent
THEMES = ('prosimii', 'smurfius', 'ghoticms', 'cyber', 'mahogany', 'ironhide', 'spore', 'veil', 'hianxiety')


def main():
    chromium = shutil.which('chromium') or shutil.which('chromium-browser')
    if not chromium:
        raise SystemExit('Install Chromium to run responsive browser checks.')
    with tempfile.TemporaryDirectory(prefix='ghoti-store-check-') as directory:
        base = pathlib.Path(directory)
        jobs = []
        for theme in THEMES:
            for view in ('catalog', 'cart', 'checkout', 'subscription', 'promotions', 'settings'):
                fixture = base / f'{theme}-{view}.html'
                rendered = subprocess.run(['php', 'tests/store-preview.php', '--test',
                                           f'--theme={theme}', f'--view={view}'], cwd=ROOT,
                                          check=True, capture_output=True).stdout
                fixture.write_bytes(rendered)
                widths = (390, 768, 1366) if view == 'settings' else (390, 1366)
                for width in widths:
                    jobs.append((theme, view, width, fixture))

        def check(job):
            theme, view, width, fixture = job
            profile = base / f'profile-{theme}-{view}-{width}'
            result = subprocess.run([chromium, '--headless', '--no-sandbox', '--disable-gpu',
                                     '--force-dark-mode', f'--user-data-dir={profile}',
                                     f'--window-size={width},900', '--dump-dom', fixture.as_uri()],
                                    capture_output=True, text=True, timeout=45, check=True)
            dom = result.stdout
            if 'data-overflow="false"' not in dom:
                raise RuntimeError(f'{theme}/{view}/{width}: overflow or fixture did not load')
            if 'data-test-result="PASS:' not in dom:
                failure = re.search(r'data-test-result="([^"]*)', dom)
                raise RuntimeError(f'{theme}/{view}/{width}: {failure.group(1) if failure else "tests did not run"}')
            surface = re.search(r'data-surface="([^"]+)', dom)
            if not surface:
                raise RuntimeError(f'{theme}/{view}/{width}: theme surface missing')
            if view == 'settings':
                expected_columns = '2' if width == 1366 else '1'
                if f'data-settings-columns="{expected_columns}"' not in dom:
                    raise RuntimeError(f'{theme}/{view}/{width}: settings fields are still cramped')
            return f'PASS {theme}/{view}/{width} surface={surface.group(1)}'

        with concurrent.futures.ThreadPoolExecutor(max_workers=3) as pool:
            for result in pool.map(check, jobs):
                print(result, flush=True)
        print(f'PASS: {len(jobs)} responsive/theme fixtures; all browser assertions passed')


if __name__ == '__main__':
    main()
