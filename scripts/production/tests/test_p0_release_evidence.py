"""Local negative contract checks; never connect to Production or COS."""
import importlib.util
import json
from pathlib import Path
import shutil
import struct
import subprocess
import tempfile
import time
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('evidence', Path(__file__).resolve().parents[1] / 'p0_release_evidence.py')
evidence = importlib.util.module_from_spec(spec)
spec.loader.exec_module(evidence)


class EvidenceContracts(unittest.TestCase):
    def fpm_identity(self):
        return [{'ActiveState': 'active', 'MainPID': '0', 'Type': 'forking',
                 'FragmentPath': '/run/systemd/generator.late/php-fpm-83.service', 'PIDFile': ''},
                '2507293', str(evidence.FPM_BINARY),
                'php-fpm: master process ('+str(evidence.FPM_CONFIG)+')',
                'pid = '+str(evidence.FPM_PIDFILE)+'\nlisten = /tmp/php-cgi-83.sock\n']

    def test_exact_sysv_service_uses_verified_pid_file_not_zero_mainpid(self):
        self.assertEqual(2507293, evidence.validate_fpm_service_identity(*self.fpm_identity()))

    def test_native_mainpid_must_agree_with_verified_pid_file(self):
        args = self.fpm_identity()
        args[0]['MainPID'] = args[1]
        self.assertEqual(2507293, evidence.validate_fpm_service_identity(*args))
        args[0]['MainPID'] = '1234'
        with self.assertRaisesRegex(RuntimeError, 'disagree'):
            evidence.validate_fpm_service_identity(*args)

    def test_unknown_sysv_topology_is_not_a_pid_bypass(self):
        for key, value in [('Type', 'simple'), ('FragmentPath', '/tmp/other.service'),
                           ('PIDFile', '/tmp/arbitrary.pid'), ('ActiveState', 'inactive')]:
            args = self.fpm_identity()
            args[0][key] = value
            with self.assertRaises(RuntimeError):
                evidence.validate_fpm_service_identity(*args)

    def test_wrong_fpm_executable_config_pid_or_socket_is_denied(self):
        for index, value in [(1, '0'), (1, '../1234'), (2, '/tmp/php-fpm'),
                             (3, 'php-fpm: master process (/tmp/frontdesk-sandbox/php-fpm.conf)'),
                             (4, 'pid = /tmp/other.pid\nlisten = /tmp/php-cgi-83.sock\n'),
                             (4, 'pid = '+str(evidence.FPM_PIDFILE)+'\nlisten = /tmp/other.sock\n')]:
            args = self.fpm_identity()
            args[index] = value
            with self.assertRaises(RuntimeError):
                evidence.validate_fpm_service_identity(*args)

    def test_wrong_recovery_baseline_denied_before_io(self):
        with self.assertRaisesRegex(RuntimeError, 'exact baseline'):
            evidence.verify_backup('eschool-prod-20261007T000000Z-deadbeefdead')

    def test_backup_path_traversal_denied_before_io(self):
        with self.assertRaises(RuntimeError):
            evidence.verify_backup('../../anything')

    def test_fields_duplicate_denied(self):
        with tempfile.TemporaryDirectory() as temporary:
            path = Path(temporary) / 'config'
            path.write_text('KEY=first\nKEY=second\n')
            with patch.object(evidence, 'trusted'), self.assertRaisesRegex(RuntimeError, 'duplicate'):
                evidence.fields(path)

    def test_fields_never_shell_expands(self):
        with tempfile.TemporaryDirectory() as temporary:
            path = Path(temporary) / 'config'
            path.write_text('KEY=$(touch /do-not-create)\n')
            with patch.object(evidence, 'trusted'):
                self.assertEqual('$(touch /do-not-create)', evidence.fields(path)['KEY'])

    def test_untrusted_symlink_denied(self):
        with tempfile.TemporaryDirectory() as temporary:
            path = Path(temporary) / 'linked'
            path.symlink_to('/etc/passwd')
            with self.assertRaisesRegex(RuntimeError, 'Unsafe'):
                evidence.trusted(path)

    def test_subprocess_error_does_not_expose_stdout_or_stderr(self):
        result = type('Result', (), {'returncode': 1, 'stdout': 'secret-value', 'stderr': 'secret-value'})()
        with patch.object(evidence.subprocess, 'run', return_value=result):
            with self.assertRaises(RuntimeError) as captured:
                evidence.run(['/usr/bin/coscli', 'stat'])
            self.assertNotIn('secret-value', str(captured.exception))

    def test_fastcgi_record_has_exact_length(self):
        value = evidence.record(4, b'abc')
        self.assertEqual((1, 4, 1, 3, 0, 0), struct.unpack('!BBHHBB', value[:8]))
        self.assertEqual(b'abc', value[8:])

    def test_nonroot_cannot_generate_authoritative_evidence(self):
        with patch.object(evidence.os, 'geteuid', return_value=501):
            with self.assertRaisesRegex(RuntimeError, 'Root deployment'):
                evidence.main()

    def test_reviewed_database_queue_command_passes(self):
        evidence.validate_worker_command(evidence.QUEUE_COMMAND, 'queue')

    def test_queue_backend_override_denied(self):
        command = list(evidence.QUEUE_COMMAND)
        command[3] = 'redis'
        with self.assertRaisesRegex(RuntimeError, 'backend override'):
            evidence.validate_worker_command(command, 'queue')

    def test_old_release_queue_launcher_denied(self):
        command = list(evidence.QUEUE_COMMAND)
        command[1] = '/www/wwwroot/releases/old-release/artisan'
        with self.assertRaises(RuntimeError):
            evidence.validate_worker_command(command, 'queue')

    def test_standalone_artisan_command_denied(self):
        with self.assertRaises(RuntimeError):
            evidence.validate_worker_command(['/usr/bin/php83', str(evidence.ACTIVE / 'artisan'), 'tinker'], 'queue')

    def test_additional_old_artisan_writer_denied(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            proc = root / '2799084'
            proc.mkdir()
            (proc / 'comm').write_text('php\n')
            (proc / 'cmdline').write_bytes(b'/usr/bin/php83\0artisan\0serve\0')
            (proc / 'cwd').symlink_to('/www/wwwroot/releases/eschool-rc-e904db9-frontdesk-onboarding-20260907')
            original = Path.iterdir
            def listing(path):
                return original(root) if path == Path('/proc') else original(path)
            with patch.object(Path, 'iterdir', listing), self.assertRaisesRegex(RuntimeError, '2799084'):
                evidence.reject_unreviewed_app_processes(set())

    def test_extra_queue_worker_not_silently_accepted(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            proc = root / '999'
            proc.mkdir()
            (proc / 'comm').write_text('php83\n')
            (proc / 'cmdline').write_bytes(b'/usr/bin/php83\0/www/wwwroot/43.160.241.126/artisan\0queue:work\0database\0')
            (proc / 'cwd').symlink_to('/')
            original = Path.iterdir
            def listing(path):
                return original(root) if path == Path('/proc') else original(path)
            with patch.object(Path, 'iterdir', listing), self.assertRaisesRegex(RuntimeError, '999'):
                evidence.reject_unreviewed_app_processes({10, 11})

    def test_python_evidence_contract_is_accepted_by_php_release_gate(self):
        release = Path('/www/wwwroot/releases/eschool-p0-disposable-contract')
        sha = 'a'*40
        backup = {'recovery_set': 'disposable-contract', 'completed_at': int(time.time()),
                  'sha256': 'b'*64, 'remote_verified_objects': 12}
        runtime = {'fpm_probe': {'release': str(release), 'sha': sha, 'sapi': 'fpm-fcgi', 'php_version': '8.3.0'},
                   'processes': [{'role': role, 'pid': index+1, 'start_ticks': 20,
                                  'exe': '/php83', 'cwd': '/', 'release_path': str(release), 'command_sha256': 'c'*64, 'php_version': '8.3.0'}
                                 for index, role in enumerate(['fpm_master', 'fpm_worker', 'queue', 'websocket'])]}
        for item in runtime['processes']:
            if item['role'] in ('queue', 'websocket'):
                item['command_sha256'] = 'c'*64
        proof = evidence.build_evidence(release, sha, 'open', release, sha, backup, runtime)
        root = Path(__file__).resolve().parents[3]
        php = shutil.which('php')
        self.assertIsNotNone(php, 'PHP required for the deployment contract test')
        program = "require $argv[1].'/vendor/autoload.php'; $e=json_decode($argv[2],true,512,JSON_THROW_ON_ERROR); $m=['commit_sha'=>$e['candidate_sha'],'release_name'=>basename($e['candidate_path'])]; App\\Services\\UnidentifiedDepositP0ReleaseGate::validateEvidence($e,'open',$e['candidate_path'],$e['active_path'],$e['candidate_sha'],$e['active_sha'],$m,$m,time()); echo 'PASS';"
        result = subprocess.run([php, '-r', program, str(root), json.dumps(proof)], text=True, capture_output=True)
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual('PASS', result.stdout)


if __name__ == '__main__':
    unittest.main()
