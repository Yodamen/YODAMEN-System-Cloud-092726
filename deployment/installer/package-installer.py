#!/usr/bin/env python3
"""Package a fresh installer using the same filtered app snapshot as the patch builder."""
import argparse
import hashlib
import json
from pathlib import Path
import shutil
import subprocess
import sys
import tarfile
import tempfile

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--output', required=True, type=Path)
parser.add_argument('--version', required=True)
parser.add_argument('--include-untracked', action='store_true', help='Include new app source in a recorded test build')
args = parser.parse_args()
script = Path(__file__).resolve().parent
patch = script.parent / 'patch'
args.output.mkdir(parents=True, exist_ok=True)
stage = args.output / ('yodamen-installer-' + args.version)
archive = args.output / (stage.name + '.tar.gz')
if stage.exists() or archive.exists(): parser.error('This version exists. Choose a new release version.')
with tempfile.TemporaryDirectory(prefix='yodamen-installer-build-') as tmp:
    # The patch builder validates the version and copies only filtered tracked
    # backend/frontend working files. Never clone the old deployed source snapshot.
    subprocess.run([sys.executable, str(patch / 'package-patch.py'), '--output', tmp, '--version', args.version] + (['--include-untracked'] if args.include_untracked else []), check=True, stdout=subprocess.DEVNULL)
    snapshot = Path(tmp) / ('yodamen-patch-' + args.version)
    stage.mkdir(mode=0o700)
    shutil.copytree(snapshot / 'source', stage / 'source')
    for name in ['install.sh', 'Install.command', 'Install-Yodamen.cmd', 'Install-Windows.ps1',
                 'compose.yaml', 'backup.sh', 'verify-install.php', 'README.md']:
        shutil.copy2(script / name, stage / name)
    for name in ['apache.conf', 'start.sh', 'check-health.php']:
        shutil.copy2(patch / name, stage / name)
    dockerfile = (patch / 'Dockerfile').read_text()
    marker = 'COPY verify-patch.php check-health.php ./'
    if marker not in dockerfile: raise SystemExit('Shared Dockerfile helper layout changed; review installer build.')
    (stage / 'Dockerfile').write_text(dockerfile.replace(marker, 'COPY verify-install.php check-health.php ./'))
    metadata = json.loads((snapshot / 'release.json').read_text())
    metadata['package_kind'] = 'fresh-installer'
    (stage / 'release.json').write_text(json.dumps(metadata, indent=2) + '\n')
    (stage / 'RELEASE_VERSION').write_text(args.version + '\n')
    (stage / '.dockerignore').write_text('.env\nruntime.env\n.install-state\n.installer-lock\nbackups\nreleases\nSHA256SUMS\n**/.env*\n**/vendor\n**/node_modules\n**/.git\n**/.DS_Store\n')
    (stage / '.gitignore').write_text('.env\nruntime.env\n.install-state/\n.installer-lock/\nbackups/\n')
    manifest = []
    for path in sorted(stage.rglob('*')):
        if path.is_file():
            if path.suffix in {'.sh', '.command'}: path.chmod(0o755)
            manifest.append(hashlib.sha256(path.read_bytes()).hexdigest() + '  ' + path.relative_to(stage).as_posix())
    (stage / 'SHA256SUMS').write_text('\n'.join(manifest) + '\n')
with tarfile.open(archive, 'w:gz') as tar: tar.add(stage, arcname=stage.name)
Path(str(archive) + '.sha256').write_text(hashlib.sha256(archive.read_bytes()).hexdigest() + '  ' + archive.name + '\n')
print(archive)
print('Current working app snapshot; no dump, uploads, environment files or signing key included.')
print('Docker/internet required. Windows launcher requires Windows 11 acceptance.')
