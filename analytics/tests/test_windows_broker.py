"""Fail-closed policy tests. OS adapter doubles are NOT activation evidence."""
import json
import os
import io
import types
import ctypes as C
import threading
from collections import Counter
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))


class BrokerPolicyTests(unittest.TestCase):
    def setUp(self):
        from runtime import windows_broker
        self.broker = windows_broker

    def test_oversized_input_never_starts_worker(self):
        with self.assertRaisesRegex(self.broker.BrokerError, 'input_limit'):
            self.broker.run_isolated(b'x' * 1048577, Path('missing.json'))

    def test_missing_manifest_denies_execution(self):
        with self.assertRaisesRegex(self.broker.BrokerError, 'manifest_unavailable'):
            self.broker.run_isolated(b'{}', Path('missing.json'))

    def test_child_environment_has_owned_localappdata_without_parent_secret(self):
        with patch.dict(os.environ, {'E04_SYNTHETIC_SECRET': 'synthetic'}):
            block = self.broker.child_environment(Path('C:/owned'), Path('C:/profile/AC'), 'C:/Windows')
        entries = dict(entry.split('=', 1) for entry in block.rstrip('\0').split('\0'))
        self.assertEqual(str(Path('C:/profile/AC')), entries['LOCALAPPDATA'])
        self.assertNotIn('E04_SYNTHETIC_SECRET', entries)
        self.assertTrue(block.endswith('\0\0'))
        self.assertEqual(sorted(entries, key=str.casefold), list(entries))

    def test_extra_capability_denies_resume(self):
        state = self.good_state()
        state['capabilities'] = 1
        with self.assertRaisesRegex(self.broker.BrokerError, 'child_policy'):
            self.broker.check_child_state(state)

    def test_unbound_job_denies_resume(self):
        state = self.good_state()
        state['in_job'] = False
        with self.assertRaisesRegex(self.broker.BrokerError, 'child_policy'):
            self.broker.check_child_state(state)

    def test_extra_inherited_handle_denies_launch(self):
        with self.assertRaisesRegex(self.broker.BrokerError, 'handle_policy'):
            self.broker.check_handles([10, 11, 12, 13], [10, 11, 12, 13])

    def test_parent_pipe_inherited_denies_launch(self):
        with self.assertRaisesRegex(self.broker.BrokerError, 'handle_policy'):
            self.broker.check_handles([10, 11, 12], [10, 11, 12, 14])

    def test_broken_pipe_before_all_input_denies_result(self):
        with self.assertRaisesRegex(self.broker.BrokerError, 'pipe_input'):
            self.broker.check_result(b'{}', 0, False)

    def test_oversized_output_not_truncated_to_success(self):
        with self.assertRaisesRegex(self.broker.BrokerError, 'output_limit'):
            self.broker.check_result(b' ' * 262145, 0, True)

    def test_nonzero_exit_denies_result(self):
        with self.assertRaisesRegex(self.broker.BrokerError, 'child_exit'):
            self.broker.check_result(b'{}', 1, True)

    def test_primitive_output_denies_result(self):
        with self.assertRaisesRegex(self.broker.BrokerError, 'output_json'):
            self.broker.check_result(b'[]', 0, True)

    def test_unknown_activation_boolean_is_not_evidence(self):
        with self.assertRaisesRegex(self.broker.BrokerError, 'activation_incomplete'):
            self.broker.check_activation({'activated': True}, 'a' * 64)

    def test_broker_exit2_is_only_for_allowlisted_invalid_output(self):
        for reason in ('output_json', 'output_limit', 'child_exit', 'wall_limit', 'stderr_limit', 'child_policy', 'pipe_input'):
            stdin = types.SimpleNamespace(buffer=io.BytesIO(b'{}'))
            stderr = types.SimpleNamespace(buffer=io.BytesIO())
            stdout = types.SimpleNamespace(buffer=io.BytesIO())
            with patch.object(self.broker, 'run_isolated', side_effect=self.broker.BrokerError(reason)), \
                    patch.object(self.broker.sys, 'argv', ['broker']), \
                    patch.object(self.broker.sys, 'stdin', stdin), patch.object(self.broker.sys, 'stdout', stdout), \
                    patch.object(self.broker.sys, 'stderr', stderr):
                exit_code = self.broker.main()
            self.assertEqual(2 if reason in ('output_json', 'output_limit') else 1, exit_code, reason)
            self.assertEqual(b'runner_unavailable\n', stderr.buffer.getvalue())
            self.assertEqual(b'', stdout.buffer.getvalue())

    def test_parser_first_success_second_failure_cannot_qualify_activation(self):
        from runtime import probe_worker
        fixtures = ROOT / 'tests/fixtures'
        good = (fixtures / 'contract-output.json').read_bytes()
        class Parser:
            def __init__(self):
                self.calls = 0
            def launch(inner, *_args):
                inner.calls += 1
                if inner.calls == 2:
                    raise self.broker.BrokerError('child_exit')
                return {'output': good}
        parser = Parser()
        with patch.object(probe_worker, 'validate_manifest', return_value={}):
            qualified = probe_worker.qualify_parser(parser, Path('manifest.json'), {}, fixtures)
        self.assertFalse(qualified['qualified'])
        self.assertEqual('child_exit', qualified['reason'])
        report = {'version': 'e04-probes-v1', 'manifest_hash': 'a' * 64, 'actual': True,
                  'passed': {name: True for name in self.broker.REQUIRED_PROBES},
                  'outbound_target': {'independent_stack': True}}
        report['passed']['parser'] = qualified['qualified']
        with self.assertRaisesRegex(self.broker.BrokerError, 'activation_incomplete'):
            self.broker.check_activation(report, 'a' * 64)

    def test_parser_second_output_mismatch_cannot_qualify(self):
        from runtime import probe_worker
        fixtures = ROOT / 'tests/fixtures'
        outputs = iter([(fixtures / 'contract-output.json').read_bytes(), b'{}'])
        class Parser:
            def launch(inner, *_args):
                return {'output': next(outputs)}
        with patch.object(probe_worker, 'validate_manifest', return_value={}):
            result = probe_worker.qualify_parser(Parser(), Path('manifest.json'), {}, fixtures)
        self.assertFalse(result['qualified'])

    def test_parser_post_second_run_manifest_drift_cannot_qualify(self):
        from runtime import probe_worker
        fixtures = ROOT / 'tests/fixtures'
        output = (fixtures / 'contract-output.json').read_bytes()
        class Parser:
            def launch(inner, *_args):
                return {'output': output}
        with patch.object(probe_worker, 'validate_manifest', side_effect=[{}, {}, self.broker.BrokerError('acl_drift')]):
            result = probe_worker.qualify_parser(Parser(), Path('manifest.json'), {}, fixtures)
        self.assertFalse(result['qualified'])
        self.assertEqual('acl_drift', result['reason'])

    def test_parser_qualifies_only_after_two_comparable_valid_runs(self):
        from runtime import probe_worker
        fixtures = ROOT / 'tests/fixtures'
        output = (fixtures / 'contract-output.json').read_bytes()
        class Parser:
            def __init__(inner):
                inner.calls = 0
            def launch(inner, *_args):
                inner.calls += 1
                return {'output': output}
        parser = Parser()
        with patch.object(probe_worker, 'validate_manifest', return_value={}):
            result = probe_worker.qualify_parser(parser, Path('manifest.json'), {}, fixtures)
        self.assertTrue(result['qualified'])
        self.assertEqual(2, parser.calls)

    def test_same_host_outbound_probe_cannot_activate(self):
        report = {'version': 'e04-probes-v1', 'manifest_hash': 'a' * 64,
                  'actual': True, 'passed': {name: True for name in self.broker.REQUIRED_PROBES},
                  'outbound_target': {'independent_stack': False}}
        with self.assertRaisesRegex(self.broker.BrokerError, 'activation_incomplete'):
            self.broker.check_activation(report, 'a' * 64)

    def test_trusted_probe_error_carries_only_safe_measurements(self):
        error = self.broker.BrokerError('child_exit')
        self.assertEqual({}, error.metadata)

    def test_manifest_changed_file_denies_availability(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory) / '.local/e04-runtime'
            root.mkdir(parents=True)
            (root / 'python.exe').write_bytes(b'changed')
            manifest = {'version': 'e04-runtime-v1', 'root': str(root),
                        'files': {'python.exe': '0' * 64}, 'acl': {}, 'profile': {}}
            path = root / 'manifest.json'
            path.write_text(json.dumps(manifest))
            with patch.object(self.broker, '__file__', str(Path(directory) / 'analytics/runtime/windows_broker.py')):
                with self.assertRaisesRegex(self.broker.BrokerError, 'runtime_hash_drift'):
                    self.broker.validate_manifest(path)

    def test_extra_runtime_file_denies_availability(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory) / '.local/e04-runtime'
            root.mkdir(parents=True)
            empty_hash = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855'
            files = {name: empty_hash for name in ('python.exe', 'parser.py', 'probe_child.py')}
            for name in files:
                (root / name).write_bytes(b'')
            path = root / 'manifest.json'
            path.write_text(json.dumps({'version': 'e04-runtime-v1', 'root': str(root), 'files': files}))
            (root / 'unapproved.py').write_bytes(b'')
            with patch.object(self.broker, '__file__', str(Path(directory) / 'analytics/runtime/windows_broker.py')):
                with self.assertRaisesRegex(self.broker.BrokerError, 'runtime_hash_drift'):
                    self.broker.validate_manifest(path)

    def test_runtime_copy_excludes_executable_startup_hooks(self):
        from runtime.provision_local import allowed_source
        for name in ('Lib/site-packages/a.py', 'Lib/a.pth', 'Lib/sitecustomize.py', 'Lib/usercustomize.py'):
            self.assertFalse(allowed_source(Path(name)))
        self.assertTrue(allowed_source(Path('Lib/json/decoder.py')))

    def test_owned_profile_path_requires_fixed_name_and_ac_leaf(self):
        from runtime.provision_local import verify_profile_path
        with self.assertRaisesRegex(self.broker.BrokerError, 'profile_scope'):
            verify_profile_path(Path('C:/Users/x/AppData/Local/Packages/other/AC'))

    @staticmethod
    def good_state():
        return {'is_appcontainer': True, 'sid_matches': True, 'capabilities': 0,
                'in_job': True, 'limit_flags': 0x210a, 'active_process_limit': 1,
                'memory_limit': 134217728, 'cpu_100ns': 30000000}


class NativeLifecycleTests(unittest.TestCase):
    """Calls real Native.launch with fake Win32 APIs. Never activation evidence."""
    def build(self, mode='ok', output=b'{}', stderr=b''):
        from runtime import windows_broker as broker
        self.broker = broker
        kernel = FakeKernel(mode, output, stderr)
        native = broker.Native.__new__(broker.Native)
        native.k = kernel
        native.a = types.SimpleNamespace(FreeSid=lambda sid: kernel.freed_sids.append(sid.value))
        native.profile_sid = lambda: C.c_void_p(0xabc)
        native.no_exemption = lambda sid: None
        def state(*_):
            if mode in ('token_failure', 'job_query_failure'):
                raise broker.BrokerError('child_token' if mode == 'token_failure' else 'job_query')
            return BrokerPolicyTests.good_state()
        native._child_state = state
        native.measurements = lambda *_: {'user_cpu_100ns': 0, 'kernel_cpu_100ns': 0, 'peak_memory_bytes': 1}
        return native, kernel

    def launch(self, native):
        return native.launch(b'{}', {'root': 'C:/owned', 'profile': {'path': 'C:/profile/AC'}}, 'parser.py')

    def assert_cleanup(self, kernel):
        self.assertEqual(Counter(kernel.acquired), Counter(kernel.closed))
        self.assertEqual([0xabc], kernel.freed_sids)
        self.assertEqual(1 if kernel.attrs_initialized else 0, kernel.attrs_deleted)

    def test_partial_job_pipe_attribute_and_create_failures_close_exact_owned_handles(self):
        for mode, reason in (('job_create_failure', 'job_create'), ('job_limits_failure', 'job_limits'),
                             ('pipe_failure', 'pipe_create'), ('attribute_init_failure', 'attribute_init'),
                             ('attribute_update_failure', 'attribute_set'), ('create_failure', 'process_create')):
            with self.subTest(mode=mode):
                native, kernel = self.build(mode)
                with self.assertRaisesRegex(self.broker.BrokerError, reason):
                    self.launch(native)
                self.assertEqual([], kernel.resumes)
                self.assertEqual([], kernel.process_terminations)
                self.assert_cleanup(kernel)

    def test_failed_token_and_job_verification_never_resume_and_terminate_created_child(self):
        for mode in ('token_failure', 'job_query_failure'):
            with self.subTest(mode=mode):
                native, kernel = self.build(mode)
                with self.assertRaises(self.broker.BrokerError):
                    self.launch(native)
                self.assertEqual([], kernel.resumes)
                self.assertEqual([20], kernel.process_terminations)
                self.assertEqual([1], kernel.job_terminations)
                self.assert_cleanup(kernel)

    def test_broken_input_rejects_complete_json_and_cleans_created_process(self):
        native, kernel = self.build('broken_input')
        with self.assertRaisesRegex(self.broker.BrokerError, 'pipe_input'):
            self.launch(native)
        self.assertEqual([21], kernel.resumes)
        self.assertEqual([20], kernel.process_terminations)
        self.assert_cleanup(kernel)

    def test_output_and_stderr_overflow_terminate_without_truncated_success(self):
        for output, stderr, reason in ((b'x' * 262145, b'', 'output_limit'), (b'{}', b'x' * 8193, 'stderr_limit')):
            with self.subTest(reason=reason):
                native, kernel = self.build(output=output, stderr=stderr)
                with self.assertRaisesRegex(self.broker.BrokerError, reason):
                    self.launch(native)
                self.assertEqual([20], kernel.process_terminations)
                self.assert_cleanup(kernel)

    def test_normal_launch_returns_complete_result_and_closes_each_handle_once(self):
        native, kernel = self.build()
        self.assertEqual(b'{}', self.launch(native)['output'])
        self.assertEqual([21], kernel.resumes)
        self.assert_cleanup(kernel)

    def test_delayed_cancel_keeps_stdin_owned_until_writer_completes(self):
        native, kernel = self.build('blocked_input')
        threads = []
        real_thread = self.broker.threading.Thread
        def thread_factory(target, daemon):
            thread = DelayedWriter(target, kernel, real_thread)
            threads.append(thread)
            return thread
        with patch.object(self.broker.threading, 'Thread', side_effect=thread_factory), \
                patch.object(self.broker.time, 'monotonic', side_effect=[0, 11, 11]):
            with self.assertRaisesRegex(self.broker.BrokerError, 'wall_limit'):
                self.launch(native)
        self.assertEqual([30], kernel.cancellations)
        self.assertEqual([2, 1], threads[0].joins)
        self.assertNotIn(11, kernel.closed)
        self.assertEqual(Counter({11: 1}), Counter(kernel.acquired) - Counter(kernel.closed))
        threads[0].complete()
        self.assert_cleanup(kernel)


class DelayedWriter:
    def __init__(self, target, kernel, real_thread):
        self.thread = real_thread(target=target, daemon=True)
        self.kernel, self.joins = kernel, []
    @property
    def native_id(self):
        return self.thread.native_id
    def start(self):
        self.thread.start()
        if not self.kernel.writer_entered.wait(1):
            raise AssertionError('Writer did not enter pending WriteFile adapter')
    def join(self, timeout):
        self.joins.append(timeout)
        # Simulate timeout immediately while the real target remains inside WriteFile.
        self.thread.join(0)
    def is_alive(self):
        return self.thread.is_alive()
    def complete(self):
        self.kernel.writer_release.set()
        self.thread.join(1)
        if self.thread.is_alive():
            raise AssertionError('Released writer did not complete')


class FakeKernel:
    """Win32 allocation/pipe adapter with explicit resource state and SDK-shaped values."""
    def __init__(self, mode, output, stderr):
        self.mode = mode
        self.acquired, self.closed, self.freed_sids = [], [], []
        self.job_terminations, self.process_terminations, self.resumes, self.cancellations = [], [], [], []
        self.flags, self.buffers, self.offsets = {}, {12: output, 14: stderr}, {12: 0, 14: 0}
        self.pipes = 0
        self.writer_entered, self.writer_release = threading.Event(), threading.Event()
        self.attrs_initialized, self.attrs_deleted, self.updates = False, 0, 0
    def allocate(self, handle, inherit=False):
        self.acquired.append(handle)
        self.flags[handle] = int(inherit)
        return handle
    def fail(self, code=5):
        C.set_last_error(code)
        return False
    def CreateJobObjectW(self, *_):
        return self.fail() if self.mode == 'job_create_failure' else self.allocate(1)
    def SetInformationJobObject(self, *_):
        return self.fail() if self.mode == 'job_limits_failure' else True
    def CreatePipe(self, read, write, *_):
        if self.mode == 'pipe_failure' and self.pipes == 1:
            return self.fail()
        read._obj.value = self.allocate(10 + self.pipes * 2, True)
        write._obj.value = self.allocate(11 + self.pipes * 2, True)
        self.pipes += 1
        return True
    def SetHandleInformation(self, handle, _mask, value):
        self.flags[handle] = value
        return True
    def GetHandleInformation(self, handle, value):
        value._obj.value = self.flags[handle]
        return True
    def InitializeProcThreadAttributeList(self, attrs, _count, _flags, size):
        if attrs is None:
            size._obj.value = 256
            return self.fail(122)
        if self.mode == 'attribute_init_failure':
            return self.fail(87)
        self.attrs_initialized = True
        return True
    def UpdateProcThreadAttribute(self, *_):
        self.updates += 1
        return self.fail() if self.mode == 'attribute_update_failure' and self.updates == 3 else True
    def DeleteProcThreadAttributeList(self, *_):
        self.attrs_deleted += 1
    def GetWindowsDirectoryW(self, buffer, *_):
        buffer.value = 'C:\\Windows'
        return len(buffer.value)
    def CreateProcessW(self, *_args):
        if self.mode == 'create_failure':
            return self.fail()
        pi = _args[-1]._obj
        pi.process, pi.thread, pi.pid, pi.tid = self.allocate(20), self.allocate(21), 100, 101
        return True
    def ResumeThread(self, handle):
        self.resumes.append(handle)
        return 1
    def WriteFile(self, handle, _buffer, size, count, *_):
        if self.mode == 'broken_input':
            return self.fail(109)
        if self.mode == 'blocked_input':
            self.writer_entered.set()
            if not self.writer_release.wait(2):
                return self.fail(109)
        count._obj.value = size
        return True
    def PeekNamedPipe(self, handle, _buffer, _size, _read, available, _left):
        remaining = len(self.buffers[handle]) - self.offsets[handle]
        if not remaining:
            return self.fail(109)
        available._obj.value = remaining
        return True
    def ReadFile(self, handle, buffer, size, count, *_):
        start = self.offsets[handle]
        data = self.buffers[handle][start:start+size]
        C.memmove(buffer, data, len(data))
        self.offsets[handle] += len(data)
        count._obj.value = len(data)
        return True
    def WaitForSingleObject(self, *_):
        return 0
    def GetExitCodeProcess(self, _handle, value):
        value._obj.value = 0
        return True
    def TerminateJobObject(self, handle, *_):
        self.job_terminations.append(handle)
        return True
    def TerminateProcess(self, handle, *_):
        self.process_terminations.append(handle)
        return True
    def OpenThread(self, *_):
        return self.allocate(30)
    def CancelSynchronousIo(self, handle):
        self.cancellations.append(handle)
        return self.fail(1168)
    def CloseHandle(self, handle):
        if handle in self.closed:
            raise AssertionError('Double-close of owned handle')
        self.closed.append(handle)
        return True


if __name__ == '__main__':
    unittest.main()
