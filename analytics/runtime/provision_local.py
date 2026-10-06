"""Explicit local provisioning only. Never repairs an unknown profile collision."""
import ctypes as C
import json
from pathlib import Path
import shutil
import sys

if __package__:
    from .windows_broker import Native, PROFILE_NAME, BrokerError, digest, inventory, require
else:
    sys.path.insert(0, str(Path(__file__).resolve().parent))
    from windows_broker import Native, PROFILE_NAME, BrokerError, digest, inventory, require

EXCLUDED = {'site-packages', '__pycache__', 'test', 'tests', 'tkinter', 'idlelib', 'turtledemo', 'ensurepip'}


def verify_profile_path(path):
    require(path.is_dir() and path.name == 'AC' and path.parent.name == PROFILE_NAME
            and path.parent.parent.name == 'Packages' and not path.lstat().st_file_attributes & 0x400,
            'profile_scope')


def allowed_source(path):
    return not any(part in EXCLUDED for part in path.parts) and path.suffix not in ('.pth', '.pyc', '.pyo') \
        and path.name not in ('sitecustomize.py', 'usercustomize.py')


def copy_file(source, target):
    require(source.is_file() and not source.lstat().st_file_attributes & 0x400
            and source.stat().st_nlink == 1, 'source_link')
    target.parent.mkdir(parents=True, exist_ok=True)
    shutil.copyfile(source, target)


def harden_tree(native, root, user, sid):
    paths = [root] + sorted(root.rglob('*'), key=lambda p: len(p.parts))
    # Harden descendants explicitly; inherited ACLs alone do not remove explicit grants.
    for path in paths:
        native.harden(path, user, sid)
    return {'.' if p == root else p.relative_to(root).as_posix(): native.security_hash(p) for p in paths}


def provision(source_runtime, owned_root):
    repo = Path(__file__).resolve().parents[2]
    source_runtime, owned_root = source_runtime.resolve(), owned_root.resolve()
    require(owned_root == (repo / '.local' / 'e04-runtime').resolve(), 'owned_root_scope')
    evidence_root = repo / '.local' / 'e04-probes'
    evidence_root.mkdir(parents=True, exist_ok=True)
    ownership_path = evidence_root / 'ownership.json'
    # New-only provisioning is deliberate. Repairing an incomplete owned tree is explicit.
    require(not owned_root.exists(), 'provision_existing')
    native = Native()
    sid = C.c_void_p()
    ownership = None
    if ownership_path.exists():
        ownership = json.loads(ownership_path.read_text(encoding='utf-8'))
        require(ownership.get('created_owned') is True and ownership.get('runtime') == str(owned_root)
                and ownership.get('name') == PROFILE_NAME, 'profile_ownership')
        sid = native.profile_sid()
        require(native.sid_string(sid) == ownership.get('sid'), 'profile_sid_drift')
    else:
        status = native.u.CreateAppContainerProfile(PROFILE_NAME, PROFILE_NAME, 'Owned E04 deterministic parser',
                                                    None, 0, C.byref(sid))
        require(status == 0, 'profile_create:' + str(status))
    try:
        sid_text = native.sid_string(sid)
        profile_root = native.profile_path(sid_text)
        # Record successful creation before any later validation can fail.
        if ownership is not None:
            require(str(profile_root) == ownership['path'], 'profile_path_drift')
        ownership = {'name': PROFILE_NAME, 'sid': sid_text, 'path': str(profile_root),
                     'created_owned': True, 'runtime': str(owned_root)}
        ownership_path.write_text(json.dumps(ownership, sort_keys=True), encoding='utf-8')
        verify_profile_path(profile_root)
        owned_root.mkdir()
        for name in ('python.exe', 'python312.dll', 'python3.dll', 'vcruntime140.dll', 'vcruntime140_1.dll', 'LICENSE.txt'):
            copy_file(source_runtime / name, owned_root / name)
        for folder in ('Lib', 'DLLs'):
            for source in (source_runtime / folder).rglob('*'):
                relative = source.relative_to(source_runtime)
                if allowed_source(relative) and source.is_file():
                    copy_file(source, owned_root / relative)
        copy_file(repo / 'analytics' / 'developer_analysis' / 'parser.py', owned_root / 'parser.py')
        copy_file(Path(__file__).parent / 'probe_child.py', owned_root / 'probe_child.py')
        (owned_root / 'write-canary.txt').write_bytes(b'E04 synthetic write canary')
        storage = profile_root.parent
        (storage / 'write-canary.txt').write_bytes(b'E04 synthetic write canary')
        (profile_root / 'write-canary.txt').write_bytes(b'E04 synthetic write canary')
        # Create metadata files before inventory ACLs are frozen. Child cannot change them.
        manifest_path = owned_root / 'manifest.json'
        manifest_path.write_text('{}', encoding='utf-8')
        (owned_root / 'activation.json').write_text('{"actual":false}', encoding='utf-8')
        user = native.user_sid()
        profile_acl = harden_tree(native, storage, user, sid_text)
        acl = harden_tree(native, owned_root, user, sid_text)
        manifest = {'version': 'e04-runtime-v1', 'broker_version': 'e04-broker-v1', 'root': str(owned_root),
                    'source_python_version': '3.12.14', 'files': inventory(owned_root), 'acl': acl,
                    'profile': {**ownership, 'storage_root': str(storage), 'acl': profile_acl},
                    'startup': ['-I', '-B', '-S'], 'scratch': 'none', 'profile_registry': 'not_claimed'}
        manifest_path.write_text(json.dumps(manifest, sort_keys=True, separators=(',', ':')), encoding='utf-8')
        native.verify_owned(manifest)
        return {'provisioned': True, 'activated': False, 'manifest_hash': digest(manifest_path),
                'files': len(manifest['files']), 'profile': PROFILE_NAME}
    finally:
        native.a.FreeSid(sid)


def refresh_owned(manifest_path):
    """Explicit reviewed-code refresh; unknown or drifted ownership is never repaired."""
    if __package__:
        from .windows_broker import validate_manifest
    else:
        from windows_broker import validate_manifest
    manifest = validate_manifest(manifest_path)
    native = Native()
    repo = Path(__file__).resolve().parents[2]
    root, profile = Path(manifest['root']), Path(manifest['profile']['storage_root'])
    user, sid = native.user_sid(), manifest['profile']['sid']
    copy_file(repo / 'analytics/developer_analysis/parser.py', root / 'parser.py')
    copy_file(Path(__file__).parent / 'probe_child.py', root / 'probe_child.py')
    for owned in (root, profile):
        (owned / 'write-canary.txt').write_bytes(b'E04 synthetic write canary')
    (Path(manifest['profile']['path']) / 'write-canary.txt').write_bytes(b'E04 synthetic write canary')
    manifest['acl'] = harden_tree(native, root, user, sid)
    manifest['profile']['acl'] = harden_tree(native, profile, user, sid)
    manifest['files'] = inventory(root)
    manifest_path.write_text(json.dumps(manifest, sort_keys=True, separators=(',', ':')), encoding='utf-8')
    (root / 'activation.json').write_text('{"actual":false}', encoding='utf-8')
    native.verify_owned(manifest)
    return {'refreshed': True, 'activated': False, 'manifest_hash': digest(manifest_path)}


if __name__ == '__main__':
    try:
        require(len(sys.argv) == 2, 'source_runtime_required')
        root = Path(__file__).resolve().parents[2] / '.local' / 'e04-runtime'
        result = refresh_owned(root / 'manifest.json') if sys.argv[1] == '--refresh-owned' else provision(Path(sys.argv[1]), root)
        print(json.dumps(result, sort_keys=True))
    except (BrokerError, OSError) as error:
        # Safe codes only, not arbitrary filesystem errors which can expose content/path data.
        print(json.dumps({'provisioned': False, 'reason': str(error) if isinstance(error, BrokerError) else 'filesystem_error'}))
        raise SystemExit(1)
