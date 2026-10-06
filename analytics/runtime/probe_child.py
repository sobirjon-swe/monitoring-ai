"""Fixed, hashed synthetic probes; provisioning-only, never selected by API input."""
import ctypes as C
import json
import os
from pathlib import Path
import socket
import subprocess
import sys
import time


def memory_control():
    kernel = C.WinDLL('kernel32', use_last_error=True)
    kernel.VirtualAlloc.restype = C.c_void_p
    kernel.VirtualAlloc.argtypes = [C.c_void_p, C.c_size_t, C.c_ulong, C.c_ulong]
    kernel.VirtualFree.restype = C.c_int
    kernel.VirtualFree.argtypes = [C.c_void_p, C.c_size_t, C.c_ulong]
    small = kernel.VirtualAlloc(None, 8 * 1024 * 1024, 0x3000, 4)
    if not small:
        return {'small_success': False, 'small_freed': False, 'large_denied': False, 'large_freed': False}
    small_freed = bool(kernel.VirtualFree(small, 0, 0x8000))
    large = kernel.VirtualAlloc(None, 129 * 1024 * 1024, 0x3000, 4)
    code = C.get_last_error()
    large_freed = bool(kernel.VirtualFree(large, 0, 0x8000)) if large else False
    return {'small_success': True, 'small_freed': small_freed, 'large_denied': not bool(large),
            'large_freed': large_freed, 'error_code': code if not large else 0}


def probe(value):
    mode = value['mode']
    if mode == 'startup':
        root = Path(sys.executable).parent.resolve()
        return {'site_loaded': 'site' in sys.modules,
                'owned_path': all(Path(p).resolve().is_relative_to(root) for p in sys.path),
                'bytecode_disabled': sys.dont_write_bytecode, 'isolated': sys.flags.isolated}
    if mode == 'read':
        try:
            with open(value['path'], 'rb') as file:
                file.read(1)
            return {'denied': False}
        except PermissionError as error:
            return {'denied': True, 'error_code': error.winerror}
    if mode == 'write':
        root = Path(value['path'])
        denied = []
        operations = ('create', 'modify', 'rename', 'delete')
        for operation in operations:
            try:
                if operation == 'create':
                    with open(root / 'synthetic-child-created', 'xb') as file:
                        file.write(b'synthetic')
                elif operation == 'modify':
                    with open(root / 'write-canary.txt', 'ab') as file:
                        file.write(b'synthetic')
                elif operation == 'rename':
                    os.rename(root / 'write-canary.txt', root / 'renamed-canary.txt')
                else:
                    os.unlink(root / 'write-canary.txt')
                denied.append(False)
            except PermissionError:
                denied.append(True)
            except OSError:
                # Missing controls cannot establish policy denial.
                denied.append(False)
        advapi = C.WinDLL('advapi32', use_last_error=True)
        advapi.SetNamedSecurityInfoW.restype = C.c_ulong
        advapi.SetNamedSecurityInfoW.argtypes = [C.c_wchar_p, C.c_int, C.c_ulong] + [C.c_void_p] * 4
        # Synthetic file only: a failed WRITE_DAC attempt must return access denied.
        acl_status = advapi.SetNamedSecurityInfoW(str(root / 'write-canary.txt'), 1, 4, None, None, None, None)
        return {'denied': all(denied) and acl_status == 5,
                'operations': {**dict(zip(operations, denied)), 'acl_change': acl_status == 5}, 'acl_error_code': acl_status}
    if mode == 'connect':
        sock = socket.socket()
        sock.settimeout(2)
        try:
            sock.connect((value['host'], value['port']))
            return {'denied': False}
        except PermissionError as error:
            return {'denied': True, 'error_code': error.winerror}
        except OSError as error:
            return {'denied': False, 'error_code': error.winerror}
        finally:
            sock.close()
    if mode == 'spawn':
        try:
            child = subprocess.Popen([sys.executable, '-I', '-B', '-S', '-c', 'pass'], env={'SystemRoot': os.environ['SystemRoot']})
            child.wait(timeout=2)
            return {'denied': False}
        except OSError as error:
            return {'denied': error.winerror in (5, 50, 129, 1816), 'error_code': error.winerror}
    if mode == 'memory':
        return memory_control()
    if mode == 'cpu':
        while True:
            pass
    if mode == 'wall':
        time.sleep(20)
    if mode == 'stdout':
        sys.stdout.buffer.write(b'x' * 262145)
    if mode == 'stderr':
        sys.stderr.buffer.write(b'x' * 8193)
    return {'unsupported': True}


if __name__ == '__main__':
    value = json.loads(sys.stdin.buffer.read(4096))
    sys.stdout.buffer.write(json.dumps(probe(value), sort_keys=True).encode('utf-8'))
