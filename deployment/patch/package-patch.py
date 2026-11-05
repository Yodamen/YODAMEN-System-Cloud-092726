#!/usr/bin/env python3
"""Build a source patch from this checkout without runtime files or signing keys."""
import argparse
import datetime
import hashlib
import json
from pathlib import Path
import re
import shutil
import subprocess
import tarfile

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--output', required=True, type=Path)
parser.add_argument('--version', required=True)
args = parser.parse_args()
if not re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9_.-]{0,100}', args.version):
    parser.error('Use a unique Docker-safe release version, maximum 101 characters.')
script = Path(__file__).resolve().parent
repo = script.parent.parent
commit = subprocess.check_output(['git', '-C', str(repo), 'rev-parse', 'HEAD'], text=True).strip()
tracked = subprocess.check_output(['git', '-C', str(repo), 'ls-files', '-z', '--', 'backend', 'frontend']).decode().split('\0')
args.output.mkdir(parents=True, exist_ok=True)
stage = args.output / ('yodamen-patch-' + args.version)
archive = args.output / (stage.name + '.tar.gz')
if stage.exists() or archive.exists():
    parser.error('This release already exists. Use a new version or inspect the existing output.')
stage.mkdir(mode=0o700)
source_hash = hashlib.sha256()
count = 0
for name in sorted(filter(None, tracked)):
    relative = Path(name)
    parts = relative.parts
    if any(p in {'vendor', 'node_modules', '.git', '.claude', 'tests', 'storage', 'dist'} for p in parts):
        continue
    if name.startswith('backend/bootstrap/cache/'):
        continue
    if relative.suffix.lower() in {'.sql', '.gz', '.zip', '.key', '.pem'}:
        continue
    if relative.name.startswith('.env') or relative.name in {'.mcp.json', '.npmrc', 'AGENTS.md', 'CLAUDE.md', 'boost.json'}:
        continue
    src = repo / relative
    if not src.is_file() or src.is_symlink():
        raise SystemExit('Tracked source missing or symlink: ' + name)
    content = src.read_bytes()
    target = stage / 'source' / relative
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_bytes(content)
    source_hash.update(name.encode() + b'\0' + content + b'\0')
    count += 1
for name in ['Dockerfile', 'apache.conf', 'start.sh', 'compose.patch.yaml', 'update.sh', 'Update.command',
             'Update-Yodamen.cmd', 'Update-Windows.ps1', 'capture-state.php', 'verify-patch.php', 'check-health.php', 'PATCH.md']:
    shutil.copy2(script / name, stage / name)
    if name.endswith('.sh') or name.endswith('.command'):
        (stage / name).chmod(0o755)
# Minimize Docker context. Publisher tools, environment, dumps and uploads are never copied.
(stage / '.dockerignore').write_text('**/.env*\n**/vendor\n**/node_modules\n**/.git\n**/.DS_Store\nSHA256SUMS\n')
(stage / 'RELEASE_VERSION').write_text(args.version + '\n')
dirty = bool(subprocess.check_output(['git', '-C', str(repo), 'status', '--porcelain', '--', 'backend', 'frontend']).strip())
(stage / 'release.json').write_text(json.dumps({
    'version': args.version, 'base_commit': commit, 'includes_uncommitted_app_changes': dirty,
    'source_sha256': source_hash.hexdigest(), 'source_files': count,
    'built_at_utc': datetime.datetime.now(datetime.timezone.utc).isoformat(),
    'policy': {'paid_plans': 'permanent', 'premium_trial_activities': 50},
}, indent=2) + '\n')
manifest = []
for path in sorted(stage.rglob('*')):
    if path.is_file():
        manifest.append(hashlib.sha256(path.read_bytes()).hexdigest() + '  ' + path.relative_to(stage).as_posix())
(stage / 'SHA256SUMS').write_text('\n'.join(manifest) + '\n')
with tarfile.open(archive, 'w:gz') as tar:
    tar.add(stage, arcname=stage.name)
archive.with_suffix(archive.suffix + '.sha256').write_text(hashlib.sha256(archive.read_bytes()).hexdigest() + '  ' + archive.name + '\n')
print(archive)
print(f'{count} app files; uncommitted app changes included: {dirty}')
print('Source patch: Docker builds on the target computer and requires internet access.')
