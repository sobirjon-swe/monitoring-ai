"""Actual-machine gate. No public/source endpoint and no inferred probe passes."""
import ctypes as C
import ipaddress
import json
from pathlib import Path
import socket
import subprocess
import sys
import threading
import time

if __package__:
    from .windows_broker import Native, BrokerError, REQUIRED_PROBES, digest, validate_manifest, check_activation, check_child_state
    from .probe_child import memory_control
else:
    sys.path.insert(0, str(Path(__file__).resolve().parent))
    from windows_broker import Native, BrokerError, REQUIRED_PROBES, digest, validate_manifest, check_activation, check_child_state
    from probe_child import memory_control


def verify(manifest_path):
    repo = Path(__file__).resolve().parents[2]
    report = {'version': 'e04-probes-v1', 'actual': True, 'activated': False,
              'broker_version': 'e04-broker-v1', 'manifest_hash': digest(manifest_path),
              'passed': {name: False for name in REQUIRED_PROBES}, 'details': {},
              'outbound_target': {'independent_stack': False, 'reason': 'non_loopback_probe_unavailable'}}
    try:
        manifest = validate_manifest(manifest_path)
        native = Native()
    except BrokerError as error:
        report['details']['manifest'] = {'reason': str(error)}
        return save_report(report, manifest_path, repo)

    def child(mode, **data):
        raw = json.dumps({'mode': mode, **data}, separators=(',', ':')).encode('utf-8')
        result = native.launch(raw, manifest, 'probe_child.py')
        return json.loads(result['output']), result

    # Startup is a prerequisite, never turn a failed import into a security denial.
    try:
        startup, result = child('startup')
        report['passed']['token_job'] = True
        report['details']['startup'] = {'result': startup, 'state': result['state']}
        if startup != {'site_loaded': False, 'owned_path': True, 'bytecode_disabled': True, 'isolated': 1}:
            report['details']['startup']['reason'] = 'startup_policy'
            return save_report(report, manifest_path, repo)
    except BrokerError as error:
        report['details']['startup'] = {'reason': str(error), **error.metadata}
        if 'state' in error.metadata:
            try:
                check_child_state(error.metadata['state'])
                report['passed']['token_job'] = True
            except BrokerError:
                pass
        # Pre-resume job cleanup can be evidenced despite a later loader failure.
        report['details']['crash_cleanup'] = crash_cleanup(manifest_path, native, healthy_startup=False)
        report['passed']['crash_cleanup'] = all(report['details']['crash_cleanup'].get(phase) is True for phase in ('created', 'verified', 'resumed'))
        return save_report(report, manifest_path, repo)

    fixtures = repo / 'analytics/tests/fixtures'
    qualification = qualify_parser(native, manifest_path, manifest, fixtures)
    report['passed']['parser'] = qualification['qualified']
    report['details']['parser'] = qualification

    canary_root = repo / '.local/e04-probes'
    canary_root.mkdir(parents=True, exist_ok=True)
    private = canary_root / 'private-config-canary.txt'
    private.write_bytes(b'E04 synthetic private canary; no credentials')
    report['details']['private_read'] = {'broker_control': private.read_bytes() == b'E04 synthetic private canary; no credentials'}
    try:
        value, _ = child('read', path=str(private))
        report['passed']['private_read'] = value.get('denied') is True
        report['details']['private_read'].update(value)
    except BrokerError as error:
        report['details']['private_read']['reason'] = str(error)

    # Write probes require broker-readable existing files with the owned policy.
    for name, path in (('runtime_write', Path(manifest['root'])), ('profile_write', Path(manifest['profile']['storage_root']))):
        control = path / 'write-canary.txt'
        # Created during provisioning, its descriptor is in the manifest.
        if not control.is_file():
            report['details'][name] = {'reason': 'write_control_missing'}
            continue
        try:
            broker_control = control.read_bytes() == b'E04 synthetic write canary'
            value, _ = child('write', path=str(path))
            report['passed'][name] = broker_control and value.get('denied') is True
            report['details'][name] = {'broker_control': broker_control, **value}
        except BrokerError as error:
            report['details'][name] = {'reason': str(error)}

    # Controlled ephemeral same-host listener establishes LOOPBACK only.
    listener = socket.socket()
    try:
        listener.bind(('127.0.0.1', 0))
        listener.listen(2)
        listener.settimeout(3)
        port = listener.getsockname()[1]
        control = socket.create_connection(('127.0.0.1', port), timeout=2)
        accepted, _ = listener.accept()
        accepted.close()
        control.close()
        value, _ = child('connect', host='127.0.0.1', port=port)
        report['passed']['loopback'] = value.get('denied') is True
        report['details']['loopback'] = {'broker_control': True, **value}
    except (BrokerError, OSError) as error:
        report['details']['loopback'] = {'reason': str(error) if isinstance(error, BrokerError) else 'listener_control_failed'}
    finally:
        listener.close()

    target_path = canary_root / 'outbound-target.json'
    if target_path.is_file():
        try:
            target = json.loads(target_path.read_text(encoding='utf-8'))
            address = ipaddress.IPv4Address(target['host'])
            private_ranges = [ipaddress.IPv4Network(cidr) for cidr in ('10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16')]
            own_addresses = {x[4][0] for x in socket.getaddrinfo(socket.gethostname(), None, socket.AF_INET)}
            if not any(address in network for network in private_ranges) or str(address) in own_addresses \
                    or target.get('independent_stack') is not True or target.get('user_authorized') is not True \
                    or not isinstance(target.get('topology_evidence'), str) or not 1 <= len(target['topology_evidence']) <= 256 \
                    or type(target['port']) is not int or not 1 <= target['port'] <= 65535:
                raise ValueError('invalid_controlled_target')
            control = socket.create_connection((str(address), target['port']), timeout=2)
            control.close()
            value, _ = child('connect', host=str(address), port=target['port'])
            report['passed']['outbound'] = value.get('denied') is True
            report['outbound_target'] = {'independent_stack': True, 'target_class': 'controlled_rfc1918',
                                         'topology_evidence': target['topology_evidence'], 'broker_control': True}
            report['details']['outbound'] = value
        except (BrokerError, ValueError, KeyError, OSError):
            report['details']['outbound'] = {'reason': 'controlled_target_gate_failed'}

    for name in ('spawn', 'memory'):
        try:
            value, _ = child(name)
            if name == 'memory':
                control = memory_control()
                report['passed'][name] = control['small_success'] and control['small_freed'] and not control['large_denied'] and control['large_freed'] \
                    and value.get('small_success') is True and value.get('small_freed') is True and value.get('large_denied') is True
                report['details'][name] = {'broker_control': control, 'child': value}
            else:
                control = subprocess.run([str(manifest_path.parent / 'python.exe'), '-I', '-B', '-S', '-c', 'pass'],
                                         env=broker_environment(manifest_path.parent, native), stdin=subprocess.DEVNULL,
                                         stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                                         timeout=3, creationflags=0x08000000)
                report['passed'][name] = value.get('denied') is True and control.returncode == 0
                report['details'][name] = {'broker_control': control.returncode == 0, **value}
        except (BrokerError, OSError, subprocess.TimeoutExpired) as error:
            report['details'][name] = {'reason': str(error), **error.metadata} if isinstance(error, BrokerError) else {'reason': 'broker_control_failed'}

    for mode, expected_reason in (('cpu', 'child_exit'), ('wall', 'wall_limit'), ('stdout', 'output_limit'), ('stderr', 'stderr_limit')):
        try:
            child(mode)
            report['details'][mode] = {'reason': 'limit_not_enforced'}
        except BrokerError as error:
            passed = str(error) == expected_reason
            if mode == 'cpu':
                passed = passed and error.metadata.get('user_cpu_100ns', 0) >= 30000000 and error.metadata.get('elapsed', 99) < 10
            report['passed'][mode] = passed
            report['details'][mode] = {'reason': str(error), **error.metadata}

    report['details']['crash_cleanup'] = crash_cleanup(manifest_path, native)
    report['passed']['crash_cleanup'] = all(report['details']['crash_cleanup'].get(phase) is True for phase in ('created', 'verified', 'resumed'))
    try:
        validate_manifest(manifest_path)
        check_activation(report, digest(manifest_path))
        report['activated'] = True
    except BrokerError as error:
        report['details']['activation'] = {'reason': str(error)}
    return save_report(report, manifest_path, repo)


def save_report(report, manifest_path, repo):
    directory = repo / '.local/e04-probes'
    directory.mkdir(parents=True, exist_ok=True)
    raw = json.dumps(report, sort_keys=True, separators=(',', ':'))
    (directory / 'actual-probes.json').write_text(raw, encoding='utf-8')
    # This existing owned file keeps its frozen ACL; writing it cannot add access grants.
    (manifest_path.parent / 'activation.json').write_text(raw, encoding='utf-8')
    return report


def qualify_parser(native, manifest_path, manifest, fixtures):
    """Qualify only both comparable outputs plus unchanged owned inventories."""
    try:
        validate_manifest(manifest_path)
        raw = (fixtures / 'contract-input.json').read_bytes()
        expected = json.loads((fixtures / 'contract-output.json').read_bytes())
        for _ in range(2):
            actual = json.loads(native.launch(raw, manifest, 'parser.py')['output'])
            if actual != expected:
                raise BrokerError('parser_probe_mismatch')
            validate_manifest(manifest_path)
        return {'qualified': True}
    except (BrokerError, ValueError, OSError) as error:
        return {'qualified': False, 'reason': str(error) if isinstance(error, BrokerError) else 'parser_probe_invalid'}


def broker_environment(root, native):
    windows = C.create_unicode_buffer(32768)
    length = native.k.GetWindowsDirectoryW(windows, len(windows))
    if not 0 < length < len(windows):
        raise BrokerError('windows_directory')
    return {'SystemRoot': windows.value, 'WINDIR': windows.value,
            'PATH': str(root) + ';' + windows.value + '\\System32'}


def crash_cleanup(manifest_path, native, healthy_startup=True):
    # A doomed loader can exit naturally after resume; that cannot prove crash cleanup.
    results = {} if healthy_startup else {'resumed': False, 'resumed_reason': 'healthy_startup_unavailable'}
    phases = ('created', 'verified', 'resumed') if healthy_startup else ('created', 'verified')
    for phase in phases:
        broker = subprocess.Popen([str(manifest_path.parent / 'python.exe'), '-I', '-B', '-S', str(Path(__file__).resolve()), '--crash-child', phase],
                                  stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                                  env=broker_environment(manifest_path.parent, native), close_fds=True, creationflags=0x08000000)
        lines = []
        reader = threading.Thread(target=lambda: lines.append(broker.stdout.readline(128)), daemon=True)
        reader.start()
        reader.join(5)
        process = None
        try:
            if not lines:
                results[phase] = False
                continue
            notification = json.loads(lines[0])
            process = native.k.OpenProcess(0x101000, False, notification['pid'])
            if not process:
                results[phase] = False
                continue
            if native.k.WaitForSingleObject(process, 0) == 0:
                results[phase] = False
                continue
            # Only a process query/synchronize handle is held. No Job handle duplication.
            broker.terminate()
            broker.wait(timeout=3)
            results[phase] = native.k.WaitForSingleObject(process, 2000) == 0
        except (ValueError, KeyError, OSError, subprocess.TimeoutExpired):
            results[phase] = False
        finally:
            if broker.poll() is None:
                broker.kill()
                broker.wait(timeout=3)
            broker.stdout.close()
            if process:
                native.k.CloseHandle(process)
    return results


def main():
    manifest_path = Path(__file__).resolve().parents[2] / '.local/e04-runtime/manifest.json'
    if len(sys.argv) == 3 and sys.argv[1] == '--crash-child' and sys.argv[2] in ('created', 'verified', 'resumed'):
        manifest = validate_manifest(manifest_path)
        def observer(phase, pid):
            if phase == sys.argv[2]:
                print(json.dumps({'phase': phase, 'pid': pid}), flush=True)
                time.sleep(20)
        Native().launch(b'{"mode":"wall"}', manifest, 'probe_child.py', observer=observer)
        return 1
    if sys.argv[1:]:
        return 1
    report = verify(manifest_path)
    print(json.dumps({'activated': report['activated'], 'passed': report['passed'], 'details': report['details']}))
    return 0 if report['activated'] else 1


if __name__ == '__main__':
    raise SystemExit(main())
