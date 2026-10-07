#!/usr/bin/env python3
"""Synthetic local PHP 8.3 FPM activation rehearsal; never contacts Production.

Run: python3 scripts/qa/p0-runtime-rehearsal.py
Creates a private temporary directory, its own Unix socket and its own FPM
master. Only that child process receives signals. Retains the fixture and JSON
result for inspection; no existing server, release, socket or database is used.
"""
import grp
import importlib.util
import json
import os
from pathlib import Path
import pwd
import shutil
import signal
import subprocess
import sys
import tempfile
import time

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[2]
FPM = Path('/opt/homebrew/opt/php@8.3/sbin/php-fpm')
OLD_SHA = '1' * 40
CANDIDATE_SHA = '2' * 40


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def load_collector():
    spec = importlib.util.spec_from_file_location(
        'p0_rehearsal_collector', ROOT / 'scripts/production/p0_release_evidence.py')
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def release_fixture(directory, sha):
    probe = directory / 'scripts/production/p0_fpm_probe.php'
    probe.parent.mkdir(parents=True)
    shutil.copyfile(ROOT / 'scripts/production/p0_fpm_probe.php', probe)
    (directory / '.release-commit').write_text(sha + '\n')
    (directory / '.release-manifest.json').write_text(json.dumps({
        'commit_sha': sha, 'release_name': directory.name, 'synthetic': True}))
    for path in directory.rglob('*'):
        path.chmod(0o555 if path.is_dir() else 0o444)
    directory.chmod(0o555)


def assert_probe(probe, directory, sha):
    require(probe['release_path'] == str(directory) and probe['release'] == str(directory)
            and probe['release_sha'] == sha and probe['sha'] == sha
            and probe['sapi'] == 'fpm-fcgi' and probe['php_version'].startswith('8.3.'),
            'Live FPM candidate identity mismatch')


def atomic_activate(active, candidate, expected_active, fail_before_switch=False):
    require(active.is_symlink() and active.resolve() == expected_active,
            'Private active alias changed unexpectedly')
    pending = active.parent / 'active.next'
    require(not pending.exists() and not pending.is_symlink(), 'Unexpected pending alias')
    pending.symlink_to(candidate, target_is_directory=True)
    if fail_before_switch:
        # An interrupted activation must not mutate the existing active alias.
        raise RuntimeError('simulated pre-switch activation failure')
    os.replace(pending, active)


def owned_master(process, pidfile):
    require(process.poll() is None, 'Owned local FPM exited unexpectedly')
    require(pidfile.is_file() and int(pidfile.read_text().strip()) == process.pid,
            'Owned FPM PID identity mismatch')


def wait_probe(collector, socket_path, script_path, process, deadline):
    last_error = None
    while time.monotonic() < deadline:
        require(process.poll() is None, 'Owned local FPM exited while awaiting probe')
        try:
            return collector.fcgi_request(str(socket_path), script_path)
        except (OSError, RuntimeError, ValueError, KeyError) as error:
            last_error = error
            time.sleep(0.05)
    raise RuntimeError('Private FPM probe did not become ready: ' + str(last_error))


def main():
    require(len(sys.argv) == 1, 'This local rehearsal accepts no target overrides')
    require(os.geteuid() != 0, 'Run this local rehearsal as an unprivileged user')
    require(FPM.is_file(), 'Local Homebrew PHP 8.3 FPM is required')
    version = subprocess.run([str(FPM), '-n', '-v'], capture_output=True,
                             text=True, timeout=10, check=True).stdout
    require('PHP 8.3.' in version, 'Local FPM executable is not PHP 8.3')
    collector = load_collector()
    directory = Path(tempfile.mkdtemp(prefix='p0-runtime-', dir='/private/tmp')).resolve()
    directory.chmod(0o700)
    old = directory / 'releases/old'
    candidate = directory / 'releases/candidate'
    release_fixture(old, OLD_SHA)
    release_fixture(candidate, CANDIDATE_SHA)
    active = directory / 'active'
    active.symlink_to(old, target_is_directory=True)
    socket_path = directory / 'fpm.sock'
    pidfile = directory / 'fpm.pid'
    config = directory / 'fpm.conf'
    config.write_text('\n'.join([
        '[global]', 'daemonize = no', 'pid = ' + str(pidfile),
        'error_log = ' + str(directory / 'fpm-error.log'),
        '[p0_local_rehearsal]', 'user = ' + pwd.getpwuid(os.geteuid()).pw_name,
        'group = ' + grp.getgrgid(os.getegid()).gr_name,
        'listen = ' + str(socket_path), 'listen.mode = 0600',
        'pm = static', 'pm.max_children = 1', 'clear_env = yes',
        'catch_workers_output = yes', 'security.limit_extensions = .php',
        'chdir = ' + str(directory), 'php_admin_value[realpath_cache_ttl] = 600', '',
    ]))
    config.chmod(0o600)
    script_path = active / 'scripts/production/p0_fpm_probe.php'
    results = {'synthetic': True, 'fixture': str(directory), 'checks': []}
    process = None
    try:
        with (directory / 'fpm-launch.log').open('wb') as log:
            process = subprocess.Popen(
                [str(FPM), '-n', '--nodaemonize', '--fpm-config', str(config)],
                cwd=directory, stdin=subprocess.DEVNULL, stdout=log,
                stderr=subprocess.STDOUT, start_new_session=True,
                env={'PATH': '/usr/bin:/bin', 'TMPDIR': str(directory), 'PHP_INI_SCAN_DIR': ''})
            old_probe = wait_probe(collector, socket_path, script_path, process,
                                   time.monotonic() + 10)
            owned_master(process, pidfile)
            assert_probe(old_probe, old, OLD_SHA)
            results['checks'].append('Actual PHP 8.3 FPM serves immutable old release')
            try:
                assert_probe(old_probe, candidate, CANDIDATE_SHA)
                raise AssertionError('Old runtime accepted as candidate')
            except RuntimeError as error:
                require(str(error) == 'Live FPM candidate identity mismatch', str(error))
            results['checks'].append('Old runtime is denied candidate identity')
            try:
                atomic_activate(active, candidate, old, fail_before_switch=True)
                raise AssertionError('Expected simulated activation failure')
            except RuntimeError as error:
                require(str(error) == 'simulated pre-switch activation failure', str(error))
            require(active.resolve() == old, 'Failed activation changed active release')
            failure_probe = collector.fcgi_request(str(socket_path), script_path)
            assert_probe(failure_probe, old, OLD_SHA)
            results['checks'].append('Interrupted pre-switch activation retains old live release')
            # Remove only the explicit symlink this process just created.
            pending = directory / 'active.next'
            require(pending.is_symlink() and pending.resolve() == candidate,
                    'Private pending alias identity mismatch')
            pending.unlink()
            atomic_activate(active, candidate, old)
            owned_master(process, pidfile)
            process.send_signal(signal.SIGUSR2)
            deadline = time.monotonic() + 10
            candidate_probe = None
            while time.monotonic() < deadline:
                observed = wait_probe(collector, socket_path, script_path, process, deadline)
                if (observed.get('pid') != old_probe['pid']
                        and observed.get('release_sha') == CANDIDATE_SHA):
                    candidate_probe = observed
                    break
                time.sleep(0.05)
            require(candidate_probe is not None, 'Reload did not replace the old FPM worker')
            owned_master(process, pidfile)
            assert_probe(candidate_probe, candidate, CANDIDATE_SHA)
            require(active.resolve() == candidate, 'Atomic alias switch did not persist')
            results['checks'].append('Atomic switch plus graceful FPM reload serves candidate')
            results['checks'].append('Candidate is served by a fresh PHP 8.3 FPM worker')
            results.update({'old_probe': old_probe, 'candidate_probe': candidate_probe,
                            'owned_master_pid': process.pid, 'status': 'PASS'})
    finally:
        if process is not None and process.poll() is None:
            # Popen owns this exact child; never signal a discovered PID, process
            # group, system service or an unrelated pre-existing local server.
            process.send_signal(signal.SIGQUIT)
            try:
                process.wait(timeout=10)
            except subprocess.TimeoutExpired:
                process.terminate()
                try:
                    process.wait(timeout=5)
                except subprocess.TimeoutExpired:
                    process.kill()
                    process.wait(timeout=5)
        results['owned_process_stopped'] = process is None or process.poll() is not None
        (directory / 'result.json').write_text(json.dumps(results, indent=2) + '\n')
    require(results['owned_process_stopped'], 'Owned local FPM did not stop')
    for check in results['checks']:
        print('PASS ' + check)
    print('PASS Owned local FPM stopped; unrelated servers untouched')
    print('P0_LOCAL_RUNTIME_REHEARSAL_PASS ' + str(directory / 'result.json'))


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print('P0_LOCAL_RUNTIME_REHEARSAL_FAIL: ' + str(error), file=sys.stderr)
        sys.exit(1)
