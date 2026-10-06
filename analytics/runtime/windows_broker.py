"""Trusted Windows broker. No shell, caller paths, writable scratch or fallback."""
import ctypes as C
from ctypes import wintypes as W
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
import threading
import time

VERSION = 'e04-broker-v1'
PROFILE_NAME = 'monitoring-ai-e04-parser-v1'
INPUT_LIMIT, OUTPUT_LIMIT, STDERR_LIMIT = 1048576, 262144, 8192
REQUIRED_PROBES = ('parser', 'token_job', 'loopback', 'outbound', 'private_read',
                   'runtime_write', 'profile_write', 'spawn', 'memory', 'cpu',
                   'wall', 'stdout', 'stderr', 'crash_cleanup')


class BrokerError(RuntimeError):
    """Safe reason codes only; never attach raw input, stderr or environment."""
    def __init__(self, reason):
        super().__init__(reason)
        self.metadata = {}


def require(condition, reason):
    if not condition:
        raise BrokerError(reason)


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def read_json(path):
    try:
        require(path.stat().st_size <= 1048576, 'manifest_limit')
        return json.loads(path.read_text(encoding='utf-8'))
    except (OSError, ValueError, RecursionError):
        raise BrokerError('manifest_unavailable') from None


def check_child_state(state):
    require(state == {'is_appcontainer': True, 'sid_matches': True, 'capabilities': 0,
                      'in_job': True, 'limit_flags': 0x210a, 'active_process_limit': 1,
                      'memory_limit': 134217728, 'cpu_100ns': 30000000}, 'child_policy')


def check_handles(child, inheritable):
    require(len(child) == 3 and len(set(child)) == 3 and set(child) == set(inheritable), 'handle_policy')


def check_result(output, exit_code, input_complete):
    require(input_complete, 'pipe_input')
    require(len(output) <= OUTPUT_LIMIT, 'output_limit')
    require(exit_code == 0, 'child_exit')
    try:
        value = json.loads(output.decode('utf-8', 'strict'))
        require(type(value) is dict, 'output_json')
    except (ValueError, UnicodeError, RecursionError):
        raise BrokerError('output_json') from None
    return output


def check_activation(report, manifest_hash):
    require(report.get('version') == 'e04-probes-v1' and report.get('actual') is True
            and report.get('manifest_hash') == manifest_hash
            and all(report.get('passed', {}).get(name) is True for name in REQUIRED_PROBES)
            and report.get('outbound_target', {}).get('independent_stack') is True,
            'activation_incomplete')


def child_environment(root, profile, windows):
    # LOCALAPPDATA is required by Windows AppContainer redirection before Python starts.
    values = {'LOCALAPPDATA': str(profile), 'SystemRoot': windows, 'WINDIR': windows,
              'PATH': str(root) + ';' + windows + '\\System32'}
    return '\0'.join(key + '=' + values[key] for key in sorted(values, key=str.casefold)) + '\0\0'


def inventory(root):
    files = {}
    for path in root.rglob('*'):
        st = path.lstat()
        require(not st.st_file_attributes & 0x400 and st.st_nlink == 1, 'runtime_links')
        if path.is_file() and path.name not in ('manifest.json', 'activation.json'):
            files[path.relative_to(root).as_posix()] = digest(path)
    return files


def validate_manifest(path, native=None):
    manifest = read_json(path)
    expected_root = Path(__file__).resolve().parents[2] / '.local' / 'e04-runtime'
    root = Path(manifest.get('root', '')).resolve()
    require(manifest.get('version') == 'e04-runtime-v1' and root == expected_root.resolve()
            and path.resolve() == root / 'manifest.json', 'manifest_scope')
    require(manifest.get('files') == inventory(root), 'runtime_hash_drift')
    require(all(name in manifest['files'] for name in ('python.exe', 'parser.py', 'probe_child.py')), 'runtime_inventory')
    native = native or Native()
    native.verify_owned(manifest)
    return manifest


def run_isolated(input_bytes, manifest_path):
    require(type(input_bytes) is bytes and len(input_bytes) <= INPUT_LIMIT, 'input_limit')
    manifest = validate_manifest(manifest_path)
    check_activation(read_json(manifest_path.parent / 'activation.json'), digest(manifest_path))
    return Native().launch(input_bytes, manifest, 'parser.py')['output']


# SDK structures use native alignment and pointer-sized HANDLE/SIZE_T.
class SA(C.Structure):
    _fields_ = [('length', W.DWORD), ('descriptor', C.c_void_p), ('inherit', W.BOOL)]


class SIDATTR(C.Structure):
    _fields_ = [('sid', C.c_void_p), ('attributes', W.DWORD)]


class CAPS(C.Structure):
    _fields_ = [('sid', C.c_void_p), ('caps', C.POINTER(SIDATTR)), ('count', W.DWORD), ('reserved', W.DWORD)]


class STARTUP(C.Structure):
    _fields_ = [('cb', W.DWORD), ('reserved', W.LPWSTR), ('desktop', W.LPWSTR), ('title', W.LPWSTR),
                ('x', W.DWORD), ('y', W.DWORD), ('xs', W.DWORD), ('ys', W.DWORD),
                ('xc', W.DWORD), ('yc', W.DWORD), ('fill', W.DWORD), ('flags', W.DWORD),
                ('show', W.WORD), ('reserved2size', W.WORD), ('reserved2', C.c_void_p),
                ('stdin', W.HANDLE), ('stdout', W.HANDLE), ('stderr', W.HANDLE)]


class STARTUPEX(C.Structure):
    _fields_ = [('info', STARTUP), ('attrs', C.c_void_p)]


class PROCESS(C.Structure):
    _fields_ = [('process', W.HANDLE), ('thread', W.HANDLE), ('pid', W.DWORD), ('tid', W.DWORD)]


class BASICLIMIT(C.Structure):
    _fields_ = [('cpu', C.c_longlong), ('jobcpu', C.c_longlong), ('flags', W.DWORD),
                ('minws', C.c_size_t), ('maxws', C.c_size_t), ('active', W.DWORD),
                ('affinity', C.c_size_t), ('priority', W.DWORD), ('scheduling', W.DWORD)]


class IO(C.Structure):
    _fields_ = [(name, C.c_ulonglong) for name in ('ro', 'wo', 'oo', 'rt', 'wt', 'ot')]


class LIMIT(C.Structure):
    _fields_ = [('basic', BASICLIMIT), ('io', IO), ('memory', C.c_size_t), ('jobmemory', C.c_size_t),
                ('peak', C.c_size_t), ('jobpeak', C.c_size_t)]


class Native:
    def __init__(self):
        require(os.name == 'nt' and C.sizeof(C.c_void_p) == 8, 'windows_amd64_required')
        require([C.sizeof(x) for x in (SA, SIDATTR, CAPS, STARTUP, STARTUPEX, PROCESS, BASICLIMIT, IO, LIMIT)]
                == [24, 16, 24, 104, 112, 24, 64, 48, 144], 'win32_abi')
        self.k = C.WinDLL('kernel32', use_last_error=True)
        self.a = C.WinDLL('advapi32', use_last_error=True)
        self.u = C.WinDLL('userenv', use_last_error=True)
        self.f = C.WinDLL('FirewallAPI', use_last_error=True)
        self.o = C.WinDLL('ole32', use_last_error=True)
        self._bind()

    def _api(self, dll, name, result, *args):
        fn = getattr(dll, name)
        fn.restype, fn.argtypes = result, list(args)

    def _bind(self):
        P, H, D, B, S = C.c_void_p, W.HANDLE, W.DWORD, W.BOOL, W.LPCWSTR
        api = self._api
        for name in ('CloseHandle',):
            api(self.k, name, B, H)
        api(self.k, 'GetCurrentProcess', H)
        api(self.k, 'GetProcessHeap', H)
        api(self.k, 'HeapFree', B, H, D, P)
        api(self.k, 'LocalFree', P, P)
        api(self.k, 'GetWindowsDirectoryW', W.UINT, W.LPWSTR, W.UINT)
        api(self.k, 'CreateJobObjectW', H, P, S)
        api(self.k, 'SetInformationJobObject', B, H, C.c_int, P, D)
        api(self.k, 'QueryInformationJobObject', B, H, C.c_int, P, D, P)
        api(self.k, 'IsProcessInJob', B, H, H, C.POINTER(B))
        api(self.k, 'TerminateJobObject', B, H, W.UINT)
        api(self.k, 'TerminateProcess', B, H, W.UINT)
        api(self.k, 'CreatePipe', B, C.POINTER(H), C.POINTER(H), C.POINTER(SA), D)
        api(self.k, 'SetHandleInformation', B, H, D, D)
        api(self.k, 'GetHandleInformation', B, H, C.POINTER(D))
        api(self.k, 'InitializeProcThreadAttributeList', B, P, D, D, C.POINTER(C.c_size_t))
        api(self.k, 'UpdateProcThreadAttribute', B, P, D, C.c_size_t, P, C.c_size_t, P, P)
        api(self.k, 'DeleteProcThreadAttributeList', None, P)
        api(self.k, 'CreateProcessW', B, S, W.LPWSTR, P, P, B, D, P, S, C.POINTER(STARTUPEX), C.POINTER(PROCESS))
        api(self.k, 'ResumeThread', D, H)
        api(self.k, 'WaitForSingleObject', D, H, D)
        api(self.k, 'GetExitCodeProcess', B, H, C.POINTER(D))
        api(self.k, 'GetProcessTimes', B, H, P, P, P, P)
        api(self.k, 'OpenProcess', H, D, B, D)
        api(self.k, 'ReadFile', B, H, P, D, C.POINTER(D), P)
        api(self.k, 'WriteFile', B, H, P, D, C.POINTER(D), P)
        api(self.k, 'PeekNamedPipe', B, H, P, D, P, C.POINTER(D), P)
        api(self.k, 'CancelSynchronousIo', B, H)
        api(self.k, 'OpenThread', H, D, B, D)
        api(self.a, 'OpenProcessToken', B, H, D, C.POINTER(H))
        api(self.a, 'GetTokenInformation', B, H, C.c_int, P, D, C.POINTER(D))
        api(self.a, 'EqualSid', B, P, P)
        api(self.a, 'ConvertSidToStringSidW', B, P, C.POINTER(W.LPWSTR))
        api(self.a, 'ConvertStringSidToSidW', B, S, C.POINTER(P))
        api(self.a, 'ConvertStringSecurityDescriptorToSecurityDescriptorW', B, S, D, C.POINTER(P), P)
        api(self.a, 'GetSecurityDescriptorDacl', B, P, C.POINTER(B), C.POINTER(P), C.POINTER(B))
        api(self.a, 'GetSecurityDescriptorSacl', B, P, C.POINTER(B), C.POINTER(P), C.POINTER(B))
        api(self.a, 'SetNamedSecurityInfoW', D, S, C.c_int, D, P, P, P, P)
        api(self.a, 'GetNamedSecurityInfoW', D, S, C.c_int, D, P, P, P, P, C.POINTER(P))
        api(self.a, 'GetSecurityDescriptorLength', D, P)
        api(self.u, 'CreateAppContainerProfile', C.c_long, S, S, S, P, D, C.POINTER(P))
        api(self.u, 'DeriveAppContainerSidFromAppContainerName', C.c_long, S, C.POINTER(P))
        api(self.u, 'GetAppContainerFolderPath', C.c_long, S, C.POINTER(W.LPWSTR))
        api(self.u, 'FreeSid', P, P) if hasattr(self.u, 'FreeSid') else None
        api(self.a, 'FreeSid', P, P)
        api(self.o, 'CoTaskMemFree', None, P)
        api(self.f, 'NetworkIsolationGetAppContainerConfig', D, C.POINTER(D), C.POINTER(C.POINTER(SIDATTR)))

    def ok(self, result, reason):
        require(bool(result), reason + ':' + str(C.get_last_error()))

    def token_info(self, token, kind):
        n = W.DWORD()
        result = self.a.GetTokenInformation(token, kind, None, 0, C.byref(n))
        require(not result and C.get_last_error() == 122 and 0 < n.value <= 65536, 'token_size')
        buf = C.create_string_buffer(n.value)
        self.ok(self.a.GetTokenInformation(token, kind, buf, n, C.byref(n)), 'token_query')
        return buf

    def sid_string(self, sid):
        out = W.LPWSTR()
        self.ok(self.a.ConvertSidToStringSidW(sid, C.byref(out)), 'sid_string')
        try:
            return out.value
        finally:
            self.k.LocalFree(C.cast(out, C.c_void_p))

    def user_sid(self):
        token = W.HANDLE()
        self.ok(self.a.OpenProcessToken(self.k.GetCurrentProcess(), 8, C.byref(token)), 'broker_token')
        try:
            buf = self.token_info(token, 1)
            return self.sid_string(C.cast(buf, C.POINTER(SIDATTR)).contents.sid)
        finally:
            self.k.CloseHandle(token)

    def profile_sid(self):
        sid = C.c_void_p()
        require(self.u.DeriveAppContainerSidFromAppContainerName(PROFILE_NAME, C.byref(sid)) == 0, 'profile_derive')
        return sid

    def profile_path(self, sid_string):
        out = W.LPWSTR()
        require(self.u.GetAppContainerFolderPath(sid_string, C.byref(out)) == 0, 'profile_folder')
        try:
            return Path(out.value).resolve()
        finally:
            self.o.CoTaskMemFree(C.cast(out, C.c_void_p))

    def no_exemption(self, sid):
        count, array = W.DWORD(), C.POINTER(SIDATTR)()
        require(self.f.NetworkIsolationGetAppContainerConfig(C.byref(count), C.byref(array)) == 0, 'exemption_query')
        try:
            require(count.value <= 4096, 'exemption_count')
            require(not any(self.a.EqualSid(sid, array[i].sid) for i in range(count.value)), 'loopback_exemption')
        finally:
            heap = self.k.GetProcessHeap()
            for i in range(count.value):
                if array[i].sid:
                    self.k.HeapFree(heap, 0, array[i].sid)
            if array:
                self.k.HeapFree(heap, 0, array)

    def security_hash(self, path):
        sd = C.c_void_p()
        # Query owner, DACL and mandatory label only, without unrelated SACL audit data.
        require(self.a.GetNamedSecurityInfoW(str(path), 1, 0x15, None, None, None, None, C.byref(sd)) == 0, 'acl_query')
        try:
            return hashlib.sha256(C.string_at(sd, self.a.GetSecurityDescriptorLength(sd))).hexdigest()
        finally:
            self.k.LocalFree(sd)

    def harden(self, path, user_sid, app_sid):
        require(not path.lstat().st_file_attributes & 0x400, 'acl_link')
        inheritance = 'OICI' if path.is_dir() else ''
        text = 'D:P(A;'+inheritance+';FA;;;SY)(A;'+inheritance+';FA;;;BA)(A;'+inheritance+';FA;;;'+user_sid+')(A;'+inheritance+';0x1200a9;;;'+app_sid+')S:(ML;'+inheritance+';NW;;;ME)'
        sd, dacl, sacl = C.c_void_p(), C.c_void_p(), C.c_void_p()
        present, default = W.BOOL(), W.BOOL()
        self.ok(self.a.ConvertStringSecurityDescriptorToSecurityDescriptorW(text, 1, C.byref(sd), None), 'acl_descriptor')
        try:
            self.ok(self.a.GetSecurityDescriptorDacl(sd, C.byref(present), C.byref(dacl), C.byref(default)), 'acl_dacl')
            require(present.value and dacl.value, 'acl_empty')
            self.ok(self.a.GetSecurityDescriptorSacl(sd, C.byref(present), C.byref(sacl), C.byref(default)), 'acl_label')
            require(self.a.SetNamedSecurityInfoW(str(path), 1, 0x80000014, None, None, dacl, sacl) == 0, 'acl_set')
        finally:
            self.k.LocalFree(sd)
        return self.security_hash(path)

    def verify_owned(self, manifest):
        require(manifest['profile'].get('name') == PROFILE_NAME and manifest['profile'].get('created_owned') is True,
                'profile_ownership')
        sid = self.profile_sid()
        try:
            require(self.sid_string(sid) == manifest['profile']['sid'], 'profile_sid_drift')
            profile = self.profile_path(manifest['profile']['sid'])
            require(str(profile) == manifest['profile']['path'], 'profile_path_drift')
            self.no_exemption(sid)
            storage = Path(manifest['profile'].get('storage_root', ''))
            require(storage == profile.parent and storage.name == PROFILE_NAME and storage.parent.name == 'Packages', 'profile_storage_scope')
            for root, expected in ((Path(manifest['root']), manifest['acl']), (storage, manifest['profile']['acl'])):
                paths = [root] + list(root.rglob('*'))
                require({'.' if p == root else p.relative_to(root).as_posix() for p in paths} == set(expected), 'acl_inventory_drift')
                for p in paths:
                    require(not p.lstat().st_file_attributes & 0x400, 'acl_link')
                    require(self.security_hash(p) == expected['.' if p == root else p.relative_to(root).as_posix()], 'acl_drift')
        finally:
            self.a.FreeSid(sid)

    def _child_state(self, process, job, sid):
        token = W.HANDLE()
        self.ok(self.a.OpenProcessToken(process, 8, C.byref(token)), 'child_token')
        try:
            app = self.token_info(token, 29)
            app_sid = self.token_info(token, 31)
            caps = self.token_info(token, 30)
            in_job, limits = W.BOOL(), LIMIT()
            self.ok(self.k.IsProcessInJob(process, job, C.byref(in_job)), 'job_membership')
            self.ok(self.k.QueryInformationJobObject(job, 9, C.byref(limits), C.sizeof(limits), None), 'job_query')
            state = {'is_appcontainer': C.cast(app, C.POINTER(W.DWORD)).contents.value == 1,
                     'sid_matches': bool(self.a.EqualSid(C.cast(app_sid, C.POINTER(C.c_void_p)).contents.value, sid)),
                     'capabilities': C.cast(caps, C.POINTER(W.DWORD)).contents.value,
                     'in_job': bool(in_job.value), 'limit_flags': limits.basic.flags,
                     'active_process_limit': limits.basic.active, 'memory_limit': limits.memory,
                     'cpu_100ns': limits.basic.cpu}
            check_child_state(state)
            return state
        finally:
            self.k.CloseHandle(token)

    def measurements(self, process, job):
        creation, exit_time, kernel, user = (C.c_ulonglong() for _ in range(4))
        limits = LIMIT()
        self.ok(self.k.GetProcessTimes(process, C.byref(creation), C.byref(exit_time), C.byref(kernel), C.byref(user)), 'cpu_query')
        self.ok(self.k.QueryInformationJobObject(job, 9, C.byref(limits), C.sizeof(limits), None), 'peak_query')
        return {'user_cpu_100ns': user.value, 'kernel_cpu_100ns': kernel.value, 'peak_memory_bytes': limits.peak}

    def launch(self, input_bytes, manifest, module, observer=None):
        require(module in ('parser.py', 'probe_child.py'), 'module_policy')
        require(len(input_bytes) <= INPUT_LIMIT, 'input_limit')
        deadline = time.monotonic() + 10
        handles, attrs, initialized, pi, sid = [], None, False, PROCESS(), None
        writer = None
        result = {'output': b'', 'stderr_bytes': 0, 'input_complete': False}
        try:
            sid = self.profile_sid()
            self.no_exemption(sid)
            job = self.k.CreateJobObjectW(None, None)
            self.ok(job, 'job_create')
            handles.append(job)
            limits = LIMIT()
            limits.basic.flags, limits.basic.active, limits.basic.cpu, limits.memory = 0x210a, 1, 30000000, 134217728
            self.ok(self.k.SetInformationJobObject(job, 9, C.byref(limits), C.sizeof(limits)), 'job_limits')
            ends = []
            sa = SA(C.sizeof(SA), None, True)
            for _ in range(3):
                read, write = W.HANDLE(), W.HANDLE()
                self.ok(self.k.CreatePipe(C.byref(read), C.byref(write), C.byref(sa), 4096), 'pipe_create')
                handles.extend((read.value, write.value))
                ends.append((read.value, write.value))
            child = [ends[0][0], ends[1][1], ends[2][1]]
            parent = [ends[0][1], ends[1][0], ends[2][0]]
            for handle in parent:
                self.ok(self.k.SetHandleInformation(handle, 1, 0), 'pipe_flags')
            inheritable = []
            for handle in handles:
                flags = W.DWORD()
                self.ok(self.k.GetHandleInformation(handle, C.byref(flags)), 'handle_query')
                if flags.value & 1:
                    inheritable.append(handle)
            check_handles(child, inheritable)
            size = C.c_size_t()
            require(not self.k.InitializeProcThreadAttributeList(None, 4, 0, C.byref(size))
                    and C.get_last_error() == 122 and 0 < size.value < 65536, 'attribute_size')
            attrs = C.create_string_buffer(size.value)
            self.ok(self.k.InitializeProcThreadAttributeList(attrs, 4, 0, C.byref(size)), 'attribute_init')
            initialized = True
            capabilities, child_handles, jobs, policy = CAPS(sid, None, 0, 0), (W.HANDLE * 3)(*child), (W.HANDLE * 1)(job), W.DWORD(1)
            for key, value in ((0x20009, capabilities), (0x20002, child_handles), (0x2000d, jobs), (0x2000e, policy)):
                self.ok(self.k.UpdateProcThreadAttribute(attrs, 0, key, C.byref(value), C.sizeof(value), None, None), 'attribute_set')
            startup = STARTUPEX()
            startup.info.cb, startup.info.flags, startup.attrs = C.sizeof(STARTUPEX), 0x100, C.cast(attrs, C.c_void_p)
            startup.info.stdin, startup.info.stdout, startup.info.stderr = child
            root = Path(manifest['root'])
            exe = str(root / 'python.exe')
            command = C.create_unicode_buffer(subprocess.list2cmdline([exe, '-I', '-B', '-S', str(root / module)]))
            windir = C.create_unicode_buffer(32768)
            n = self.k.GetWindowsDirectoryW(windir, len(windir))
            require(0 < n < len(windir), 'windows_directory')
            env = C.create_unicode_buffer(child_environment(root, Path(manifest['profile']['path']), windir.value))
            self.ok(self.k.CreateProcessW(exe, command, None, None, True, 0x8080404,
                                         env, str(root), C.byref(startup), C.byref(pi)), 'process_create')
            handles.extend((pi.process, pi.thread))
            if observer:
                observer('created', pi.pid)
            result['state'] = self._child_state(pi.process, job, sid)
            if observer:
                observer('verified', pi.pid)
            for handle in child:
                self.k.CloseHandle(handle)
                handles.remove(handle)
            require(self.k.ResumeThread(pi.thread) == 1, 'resume')
            if observer:
                observer('resumed', pi.pid)

            def write_input():
                try:
                    offset = 0
                    while offset < len(input_bytes):
                        chunk = input_bytes[offset:offset+4096]
                        buffer, count = C.create_string_buffer(chunk), W.DWORD()
                        if not self.k.WriteFile(parent[0], buffer, len(chunk), C.byref(count), None) or not count.value:
                            return
                        offset += count.value
                    result['input_complete'] = True
                finally:
                    self.k.CloseHandle(parent[0])

            writer = threading.Thread(target=write_input, daemon=True)
            writer.start()
            # The writer is the sole owner once started, even if cancellation is delayed.
            handles.remove(parent[0])
            output = bytearray()
            open_pipes = {parent[1]: 'stdout', parent[2]: 'stderr'}
            exited = False
            while open_pipes or not exited or writer.is_alive():
                require(time.monotonic() < deadline, 'wall_limit')
                for handle, kind in list(open_pipes.items()):
                    available = W.DWORD()
                    if not self.k.PeekNamedPipe(handle, None, 0, None, C.byref(available), None):
                        require(C.get_last_error() == 109, 'pipe_peek')
                        del open_pipes[handle]
                        continue
                    if available.value:
                        size_read = min(available.value, 4096)
                        buffer, count = C.create_string_buffer(size_read), W.DWORD()
                        self.ok(self.k.ReadFile(handle, buffer, size_read, C.byref(count), None), 'pipe_read')
                        if kind == 'stdout':
                            require(len(output) + count.value <= OUTPUT_LIMIT, 'output_limit')
                            output.extend(buffer.raw[:count.value])
                        else:
                            result['stderr_bytes'] += count.value
                            require(result['stderr_bytes'] <= STDERR_LIMIT, 'stderr_limit')
                exited = self.k.WaitForSingleObject(pi.process, 0) == 0
                time.sleep(.005)
            code = W.DWORD()
            self.ok(self.k.GetExitCodeProcess(pi.process, C.byref(code)), 'exit_query')
            result['exit_code'] = code.value
            result['output'] = check_result(bytes(output), code.value, result['input_complete'])
            result['elapsed'] = 10 - (deadline - time.monotonic())
            result.update(self.measurements(pi.process, job))
            return result
        except BrokerError as error:
            error.metadata = {'elapsed': 10 - (deadline - time.monotonic()),
                              'stderr_bytes': result['stderr_bytes'], 'child_created': bool(pi.process)}
            if 'state' in result:
                error.metadata['state'] = result['state']
            if 'exit_code' in result:
                error.metadata['exit_code'] = result['exit_code']
            if pi.process:
                try:
                    error.metadata.update(self.measurements(pi.process, job))
                except BrokerError:
                    pass
            raise
        finally:
            # Kill before closing pipes; release an I/O writer without reusing a live handle.
            if handles:
                self.k.TerminateJobObject(handles[0], 1)
            if pi.process:
                self.k.TerminateProcess(pi.process, 1)
                self.k.WaitForSingleObject(pi.process, 2000)
            if writer:
                writer.join(2)
                if writer.is_alive():
                    thread_handle = self.k.OpenThread(1, False, writer.native_id)
                    if thread_handle:
                        self.k.CancelSynchronousIo(thread_handle)
                        self.k.CloseHandle(thread_handle)
                    writer.join(1)
            for handle in reversed(handles):
                self.k.CloseHandle(handle)
            if initialized:
                self.k.DeleteProcThreadAttributeList(attrs)
            if sid:
                self.a.FreeSid(sid)


def main():
    try:
        root = Path(__file__).resolve().parents[2] / '.local' / 'e04-runtime'
        manifest = root / 'manifest.json'
        if sys.argv[1:] == ['--check']:
            validate_manifest(manifest)
            check_activation(read_json(root / 'activation.json'), digest(manifest))
            sys.stdout.buffer.write(b'{"ready":true}\n')
        else:
            require(not sys.argv[1:], 'broker_arguments')
            data = sys.stdin.buffer.read(INPUT_LIMIT + 1)
            sys.stdout.buffer.write(run_isolated(data, manifest))
        return 0
    except (BrokerError, OSError, KeyError, ValueError, TypeError) as error:
        sys.stderr.buffer.write(b'runner_unavailable\n')
        return 2 if isinstance(error, BrokerError) and str(error) in ('output_json', 'output_limit') else 1


if __name__ == '__main__':
    raise SystemExit(main())
