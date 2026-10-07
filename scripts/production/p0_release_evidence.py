#!/usr/bin/env python3
"""Root-only, fail-closed P0 deployment evidence; no migration or service changes.

Reads only release metadata, operational process state and encrypted backup
artifacts. COS calls are stat/HEAD only. Secrets remain in memory and a private
temporary COSCLI profile, never command arguments, output or persistent evidence.
"""
import datetime
import configparser
import fcntl
import grp
import hashlib
import json
import os
from pathlib import Path
import re
import secrets
import shlex
import socket
import stat
import struct
import subprocess
import sys
import tempfile
import time

BASELINE = '135341bf2f9eb1e470be7bd6e3fd8f3515c60e0e'
PRIOR = '0798e13b3ba94fac16229ccc057d5031ce698ac1'
MIGRATION = 'database/migrations/2026_10_07_000001_close_unidentified_deposit_p0.php'
MIGRATION_HASH = '8cb9cc668a4ad17ad4c7543564beba3b21f9d6d6ad583ce4acb3f18e1ea4c432'
ACTIVE = Path('/www/wwwroot/43.160.241.126')
RELEASES = Path('/www/wwwroot/releases')
EVIDENCE = Path('/run/eschool-p0-gate/evidence.json')
BUCKET = 'eschool-production-backup-1375024146'
QUEUE_COMMAND = ['/usr/bin/php83', str(ACTIVE / 'artisan'), 'queue:work', 'database',
                 '--sleep=2', '--tries=3', '--timeout=180']
WEBSOCKET_COMMAND = ['/usr/bin/php83', str(ACTIVE / 'artisan'), 'websocket:init']


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def run(args, **kwargs):
    result = subprocess.run(args, text=True, capture_output=True, timeout=120, **kwargs)
    require(result.returncode == 0, 'Operational check failed: '+Path(args[0]).name)
    return result.stdout.strip()


def digest(path):
    value = hashlib.sha256()
    with path.open('rb') as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b''):
            value.update(chunk)
    return value.hexdigest()


def trusted(path, directory=False):
    info = path.lstat()
    require(not path.is_symlink() and info.st_uid == 0 and not info.st_mode & 0o022,
            'Unsafe deployment-owned path: '+str(path))
    require(stat.S_ISDIR(info.st_mode) if directory else stat.S_ISREG(info.st_mode),
            'Wrong path type: '+str(path))
    return info


def fields(path):
    trusted(path)
    result = {}
    for line in path.read_text().splitlines():
        if not line or line.startswith('#'):
            continue
        key, sep, value = line.partition('=')
        require(sep and key not in result, 'Invalid or duplicate configuration key')
        if len(value) >= 2 and value[0] == value[-1] and value[0] in "\"'":
            value = value[1:-1]
        result[key] = value
    return result


def release_identity(release, expected):
    trusted(release, True)
    require(release.parent == RELEASES.resolve(), 'Release outside immutable root')
    for name in ('.release-commit', '.release-manifest.json'):
        trusted(release / name)
    manifest = json.loads((release / '.release-manifest.json').read_text())
    require((release / '.release-commit').read_text().strip() == expected
            == manifest['commit_sha'] == run(['git', '-C', str(release), 'rev-parse', 'HEAD']),
            'Release SHA disagreement')
    require(manifest['release_name'] == release.name, 'Release name disagreement')
    run(['git', '-C', str(release), 'diff', '--quiet', 'HEAD', '--', '.',
         ':(exclude).env', ':(exclude)storage/**', ':(exclude)public/storage',
         ':(exclude)bootstrap/cache/**'])
    # All tracked source must remain deployment-owned, including the gate itself.
    for name in run(['git', '-C', str(release), 'ls-files']).splitlines():
        if name == '.env' or name.startswith(('storage/', 'bootstrap/cache/', 'public/storage')):
            continue
        trusted(release / name)
    return manifest


def verify_backup(recovery):
    require(re.fullmatch(r'eschool-prod-\d{8}T\d{6}Z-135341bf2f9e', recovery),
            'Backup is not for the exact baseline')
    root = Path('/root/backups/recovery-sets') / recovery
    trusted(root, True)
    result_path = root / 'R2E_RESULT.env'
    result = fields(result_path)
    require(result.get('recovery_id') == recovery and result.get('release_commit') == BASELINE,
            'Backup completion identity disagreement')
    created = datetime.datetime.fromisoformat(result['created_at_utc'].replace('Z', '+00:00')).timestamp()
    require(0 <= time.time() - created <= 7200, 'Fresh backup required (maximum age two hours)')
    sums = root / 'ENCRYPTED_SHA256SUMS'
    trusted(sums)
    files = []
    for line in sums.read_text().splitlines():
        expected, name = line.split(None, 1)
        artifact = Path(name.lstrip('*'))
        require(re.fullmatch('[a-f0-9]{64}', expected) and artifact.is_relative_to(root / 'encrypted')
                and artifact == artifact.resolve(),
                'Invalid encrypted artifact inventory')
        trusted(artifact)
        require(artifact.suffix == '.age' and digest(artifact) == expected, 'Encrypted checksum mismatch')
        with artifact.open('rb') as stream:
            require(stream.read(22).startswith(b'age-encryption.org/v1'), 'Not an age encrypted artifact')
        files.append((artifact, expected))
    require(len({str(p) for p, _ in files}) == len(files), 'Duplicate backup artifact')
    actual = set((root / 'encrypted').rglob('*.age'))
    require(actual == {p for p, _ in files}, 'Incomplete backup checksum inventory')
    databases = sorted(p.name.removesuffix('.sql.gz.age') for p, _ in files if p.parent.name == 'databases')
    require(len(databases) == int(result['tenant_count']) + 1 and result['central_database'] in databases,
            'Central/tenant backup artifact count mismatch')
    required = {'encrypted/storage/shared-app-storage.tar.gz.age',
                'encrypted/config/recovery-config.tar.gz.age', 'encrypted/RECOVERY_MANIFEST.txt.age'}
    require(required.issubset({str(p.relative_to(root)) for p, _ in files}), 'Missing recovery artifact')
    conf = fields(Path('/root/.config/eschool-backup/backup.conf'))
    require(conf.get('COS_BUCKET') == BUCKET and conf.get('COS_REGION') == 'ap-singapore', 'COS scope mismatch')
    prefix = result['cos_prefix']
    require(prefix == conf.get('COS_PREFIX') and re.fullmatch(r'[A-Za-z0-9._/-]+', prefix)
            and '..' not in prefix, 'COS prefix mismatch')
    credential_path = Path('/root/.config/eschool-backup/cos.env')
    require(stat.S_IMODE(trusted(credential_path).st_mode) == 0o600, 'Credential mode mismatch')
    credentials = fields(credential_path)
    for key in ('TENCENT_SECRET_ID', 'TENCENT_SECRET_KEY'):
        require(credentials.get(key) and not re.search(r'\s', credentials[key]), 'Invalid credential format')
    client = Path('/usr/local/libexec/eschool-backup/coscli')
    trusted(client)
    remote = []
    with tempfile.TemporaryDirectory(prefix='eschool-p0-head-', dir='/run') as temp:
        profile = Path(temp) / 'coscli.yaml'
        # JSON is valid YAML. No inherited Tencent environment or global profile.
        profile.write_text(json.dumps({'cos': {'base': {
            'secretid': credentials['TENCENT_SECRET_ID'], 'secretkey': credentials['TENCENT_SECRET_KEY'],
            'disableautofetchbuckettype': 'true', 'protocol': 'https', 'sessiontoken': ''},
            'buckets': [{'name': BUCKET, 'alias': 'r2e', 'region': 'ap-singapore',
                         'endpoint': 'cos.ap-singapore.myqcloud.com', 'ofs': False, 'customized': False}]}}))
        profile.chmod(0o600)
        for artifact, expected in files:
            key = prefix+'/recovery-sets/'+recovery+'/'+str(artifact.relative_to(root))
            output = run([str(client), '--config-path', str(profile), '--disable-log',
                          '--log-path', temp, 'stat', 'cos://'+BUCKET+'/'+key],
                         env={'PATH': '/usr/bin:/bin', 'HOME': temp})
            sizes = re.findall(r'Content-Length:\s*(\d+)', output, re.I)
            require(len(sizes) == 1 and int(sizes[0]) == artifact.stat().st_size > 0,
                    'Authoritative COS HEAD size mismatch')
            remote.append({'key': key, 'size': int(sizes[0]), 'sha256': expected})
    completed = int(result_path.stat().st_mtime)
    require(created <= completed <= time.time(), 'Backup completion time invalid')
    return {'recovery_set': recovery, 'created_at': int(created), 'completed_at': completed,
            'sha256': digest(result_path), 'remote_verified_objects': len(remote), 'result_path': str(result_path),
            'result_sha256': digest(result_path), 'checksums_sha256': digest(sums),
            'database_names': databases, 'remote_objects': remote}


def process(pid, role):
    proc = Path('/proc') / str(pid)
    parts = (proc / 'stat').read_text().rsplit(')', 1)[1].split()
    return {'role': role, 'pid': pid, 'start_ticks': int(parts[19]),
            'exe': os.readlink(proc / 'exe'), 'cwd': os.readlink(proc / 'cwd')}


def validate_worker_command(arguments, role):
    expected = QUEUE_COMMAND if role == 'queue' else WEBSOCKET_COMMAND
    require(arguments == expected, 'Unaudited worker command or queue backend override')


def launcher_contract():
    """Pin operational launchers, not app permissions; never print commands."""
    launchers = []
    for name, command in [('eschool-queue', QUEUE_COMMAND), ('websocket', WEBSOCKET_COMMAND)]:
        path = Path('/etc/supervisor/conf.d') / (name+'.conf')
        trusted(path)
        parser = configparser.ConfigParser(interpolation=None)
        parser.read(path)
        section = parser['program:'+name]
        require(shlex.split(section['command']) == command
                and section.get('numprocs', '1') == '1' and section['user'] == 'www',
                'Supervisor launcher differs from the reviewed active-alias contract')
        require('directory' not in section or section['directory'] == str(ACTIVE),
                'Supervisor launcher pins another working directory')
        launchers.append({'path': str(path), 'sha256': digest(path)})
    # Root's existing scheduler uses this exact lock. The wrapper holds it with
    # O_NOFOLLOW + flock through evidence generation AND the Artisan action.
    schedule = '* * * * * cd '+str(ACTIVE)+' && flock -n /tmp/bowen_laravel_schedule.lock /usr/sbin/runuser -u www -- /usr/bin/php artisan schedule:run >> /dev/null 2>&1'
    cron_root = Path('/var/spool/cron/crontabs')
    for path in cron_root.iterdir():
        if not path.is_file():
            continue
        trusted(path)
        for line in path.read_text().splitlines():
            if line.lstrip().startswith('#'):
                continue
            if any(token in line for token in ('artisan', str(ACTIVE), str(RELEASES))):
                require(path.name == 'root' and line.strip() == schedule,
                        'Unaudited or old-release Laravel scheduler launcher')
        launchers.append({'path': str(path), 'sha256': digest(path)})
    return launchers


def reject_unreviewed_app_processes(approved_pids):
    """Inventory every PHP process without exposing argv or environment secrets.

    Other web applications are not stopped. Any additional eSchool/Artisan
    process, or an FPM pool with an eSchool chdir, blocks this rollout. Unknown
    non-FPM PHP with cwd inside releases also blocks, even without Artisan argv.
    """
    for proc in Path('/proc').iterdir():
        if not proc.name.isdigit() or int(proc.name) in approved_pids:
            continue
        try:
            comm = (proc / 'comm').read_text()
            if 'php' not in comm:
                continue
            arguments = (proc / 'cmdline').read_bytes().replace(b'\0', b' ').decode(errors='replace')
            cwd = os.readlink(proc / 'cwd')
            relevant = ('artisan' in arguments or str(ACTIVE) in arguments
                        or str(RELEASES) in arguments or cwd.startswith(str(RELEASES)))
            pool = re.search(r'master process \(([^)]+)\)', arguments)
            if pool:
                config = Path(pool.group(1))
                trusted(config)
                # Do not silently accept indirect pool includes: they need a
                # separate bounded audit before being excluded as unrelated.
                contents = config.read_text()
                relevant = relevant or any(value in contents for value in (str(ACTIVE), str(RELEASES)))
                require(not re.search(r'^\s*include\s*=', contents, re.M),
                        'Unaudited additional PHP-FPM include topology')
            require(not relevant, 'Additional old or unreviewed eSchool PHP process PID '+proc.name)
        except FileNotFoundError:
            continue


def record(kind, content):
    return struct.pack('!BBHHBB', 1, kind, 1, len(content), 0, 0)+content


def fcgi_request(socket_path, script_path):
    """Transport seam for disposable Unix-socket rehearsal; caller fixes target."""
    nonce = secrets.token_hex(32)
    params = {'SCRIPT_FILENAME': str(script_path),
              'REQUEST_METHOD': 'GET', 'SERVER_PROTOCOL': 'HTTP/1.1', 'REMOTE_ADDR': '127.0.0.1',
              'P0_PROBE_NONCE': nonce, 'SCRIPT_NAME': '/p0_fpm_probe.php', 'SERVER_NAME': 'localhost'}
    encoded = b''
    for key, value in params.items():
        key, value = key.encode(), value.encode()
        def length(size):
            return bytes([size]) if size < 128 else struct.pack('!I', size | 0x80000000)
        encoded += length(len(key))+length(len(value))+key+value
    output = b''
    with socket.socket(socket.AF_UNIX) as sock:
        sock.settimeout(10)
        sock.connect(str(socket_path))
        sock.sendall(record(1, b'\x00\x01\x00'+b'\x00'*5)+record(4, encoded)+record(4, b'')+record(5, b''))
        def exact(size):
            data = b''
            while len(data) < size:
                chunk = sock.recv(size-len(data))
                require(chunk, 'Truncated FastCGI response')
                data += chunk
            return data
        while True:
            version, kind, req, length, padding, _ = struct.unpack('!BBHHBB', exact(8))
            require(version == 1 and req == 1, 'Unexpected FastCGI response')
            content = exact(length)
            exact(padding)
            if kind == 6:
                output += content
            if kind == 3:
                require(content[:5] == b'\x00'*5, 'FastCGI application failure')
                break
    probe = json.loads(output.split(b'\r\n\r\n', 1)[1])
    require(probe['nonce'] == nonce, 'FastCGI freshness mismatch')
    return probe


def fcgi_probe():
    # Production endpoints are not environment-variable or command overrides.
    return fcgi_request('/tmp/php-cgi-83.sock', ACTIVE / 'scripts/production/p0_fpm_probe.php')


FPM_CONFIG = Path('/www/server/php/83/etc/php-fpm.conf')
FPM_PIDFILE = Path('/www/server/php/83/var/run/php-fpm.pid')
FPM_BINARY = Path('/www/server/php/83/sbin/php-fpm')


def validate_fpm_service_identity(service, file_pid, executable, command, configuration):
    """Exact native/SysV identity; no pgrep or arbitrary PID-file fallback."""
    require(service.get('ActiveState') == 'active', 'PHP 8.3 FPM service is not active')
    require(re.fullmatch(r'[1-9][0-9]*', file_pid) and int(file_pid) > 1, 'Invalid FPM PID file')
    main = service.get('MainPID', '')
    require(re.fullmatch(r'[0-9]+', main), 'Invalid systemd FPM PID')
    if main == '0':
        require(service.get('Type') == 'forking'
                and service.get('FragmentPath') == '/run/systemd/generator.late/php-fpm-83.service'
                and service.get('PIDFile', '') == '', 'Unknown FPM service PID topology')
    else:
        require(main == file_pid, 'FPM service and PID file disagree')
    require(executable == str(FPM_BINARY) and command == 'php-fpm: master process ('+str(FPM_CONFIG)+')',
            'FPM executable/config does not identify the approved master')
    for key, expected in [('pid', str(FPM_PIDFILE)), ('listen', '/tmp/php-cgi-83.sock')]:
        values = re.findall(r'^\s*'+key+r'\s*=\s*([^;\r\n]+?)\s*$', configuration, re.M)
        require(values == [expected], 'FPM '+key+' contract mismatch')
    return int(file_pid)


def fpm_master_pid():
    output = run(['systemctl', 'show', 'php-fpm-83', '--property=MainPID', '--property=ActiveState',
                  '--property=Type', '--property=FragmentPath', '--property=PIDFile'])
    service = dict(line.split('=', 1) for line in output.splitlines())
    for path in (FPM_PIDFILE, FPM_CONFIG, FPM_BINARY):
        trusted(path)
    file_pid = FPM_PIDFILE.read_text().strip()
    require(re.fullmatch(r'[1-9][0-9]*', file_pid), 'Invalid FPM PID file')
    proc = Path('/proc') / file_pid
    command = (proc / 'cmdline').read_bytes().rstrip(b'\0').decode()
    master = validate_fpm_service_identity(service, file_pid, os.readlink(proc / 'exe'), command, FPM_CONFIG.read_text())
    require(re.search(r'^Uid:\s+0\s+0\s+0\s+0\s*$', (proc / 'status').read_text(), re.M),
            'FPM master is not owned by root')
    require(FPM_PIDFILE.read_text().strip() == file_pid, 'FPM master changed during identity inspection')
    return master


def runtime_proof(release, sha):
    switched = ACTIVE.lstat().st_ctime
    boot = int(next(line.split()[1] for line in Path('/proc/stat').read_text().splitlines() if line.startswith('btime ')))
    hz = os.sysconf('SC_CLK_TCK')
    master = fpm_master_pid()
    processes = [process(master, 'fpm_master')]
    for proc in Path('/proc').iterdir():
        if not proc.name.isdigit():
            continue
        try:
            parts = (proc / 'stat').read_text().rsplit(')', 1)[1].split()
            if int(parts[1]) == master:
                worker = process(int(proc.name), 'fpm_worker')
                require(boot+worker['start_ticks']/hz > switched, 'Old FPM worker still active')
                processes.append(worker)
        except FileNotFoundError:
            continue
    require(len(processes) > 1, 'No FPM workers')
    fpm_version = run([processes[0]['exe'], '-v'])
    require(re.search(r'PHP 8\.3\.', fpm_version), 'FPM is not PHP 8.3')
    for entry in processes:
        require(entry['exe'] == processes[0]['exe'], 'Mixed FPM executable')
        entry['php_version'] = re.search(r'PHP (8\.3\.\S+)', fpm_version).group(1)
    statuses = run(['supervisorctl', 'status']).splitlines()
    require(len(statuses) == 2, 'Unexpected additional Supervisor process')
    for name, status_name in [('eschool-queue', 'eschool-queue:eschool-queue_00'), ('websocket', 'websocket')]:
        status = next((line for line in statuses if line.split()[0] == status_name), '')
        match = re.fullmatch(re.escape(status_name)+r'\s+RUNNING\s+pid (\d+), uptime .+', status)
        require(match, 'Supervisor process is not uniquely RUNNING: '+name)
        entry = process(int(match.group(1)), 'queue' if name == 'eschool-queue' else name)
        require(entry['cwd'] in ('/', str(release)) and Path(entry['exe']).resolve() == Path('/usr/bin/php83').resolve(),
                'Supervisor process release or PHP mismatch')
        command = (Path('/proc') / str(entry['pid']) / 'cmdline').read_bytes().rstrip(b'\0').decode().split('\0')
        validate_worker_command(command, entry['role'])
        entry['command_sha256'] = hashlib.sha256('\0'.join(command).encode()).hexdigest()
        entry['release_path'] = str(release)
        require(boot+entry['start_ticks']/hz > switched, 'Old Supervisor worker remains')
        entry['php_version'] = run([entry['exe'], '-r', 'echo PHP_VERSION;'])
        require(entry['php_version'].startswith('8.3.'), 'Supervisor PHP is not 8.3')
        processes.append(entry)
    reject_unreviewed_app_processes({p['pid'] for p in processes})
    probe = fcgi_probe()
    require(probe['release_path'] == str(release) and probe['release_sha'] == sha
            and probe['php_version'].startswith('8.3.')
            and probe['pid'] in {p['pid'] for p in processes if p['role'] == 'fpm_worker'}, 'Live FPM release mismatch')
    return {'processes': processes, 'fpm_probe': probe, 'switched_at': switched}


def current_fpm_pids():
    master = fpm_master_pid()
    master_exe = os.readlink(Path('/proc') / str(master) / 'exe')
    require(re.search(r'PHP 8\.3\.', run([master_exe, '-v'])), 'FPM master is not PHP 8.3')
    pids = {master}
    for proc in Path('/proc').iterdir():
        if not proc.name.isdigit():
            continue
        try:
            fields = (proc / 'stat').read_text().rsplit(')', 1)[1].split()
            if int(fields[1]) == master:
                require(os.readlink(proc / 'exe') == master_exe, 'FPM child executable mismatch')
                pids.add(int(proc.name))
        except FileNotFoundError:
            continue
    require(len(pids) > 1, 'FPM has no workers')
    return pids


def build_evidence(release, sha, phase, active, active_sha, backup, runtime):
    return {'version': 1, 'generated_at': int(time.time()), 'phase': phase, 'baseline_sha': BASELINE,
            'candidate_sha': sha, 'candidate_path': str(release), 'active_sha': active_sha,
            'active_path': str(active), 'migration_sha256': MIGRATION_HASH, 'backup': backup, 'runtime': runtime}


def main():
    require(os.geteuid() == 0 and len(sys.argv) == 6, 'Root deployment arguments required')
    execute = sys.argv[5] == '--run'
    require(execute, 'Evidence must remain under the live locked wrapper through Artisan')
    release, sha, phase, recovery = Path(sys.argv[1]), *sys.argv[2:5]
    require(re.fullmatch('[a-f0-9]{40}', sha) and phase in ('preflight', 'close', 'migrate', 'open'), 'Invalid gate arguments')
    require(not release.is_symlink() and release == release.resolve(), 'Candidate path must be canonical')
    manifest = release_identity(release, sha)
    for parent in (BASELINE, PRIOR):
        run(['git', '-C', str(release), 'merge-base', '--is-ancestor', parent, sha])
    ref = manifest['github_ref']
    require(re.fullmatch(r'codex/[A-Za-z0-9._/-]+', ref) and '..' not in ref, 'Invalid dedicated release branch')
    remote = run(['git', '-C', str(release), 'ls-remote', '--exit-code', 'origin', 'refs/heads/'+ref]).split()
    require(len(remote) == 2 and remote[0] == sha, 'Actual remote SHA differs')
    require(ACTIVE.is_symlink(), 'Active path is not a symlink')
    active = ACTIVE.resolve()
    active_sha = sha if phase == 'open' else BASELINE
    active_manifest = release_identity(active, active_sha)
    require(manifest['baseline_sha'] == active_manifest['baseline_sha'], 'Manifest baseline contract differs')
    require(digest(release / MIGRATION) == MIGRATION_HASH, 'Exact migration hash differs')
    for check in ('verify_runtime_links.sh', 'verify_runtime_ownership.sh'):
        run(['bash', str(release / 'scripts/production' / check), str(release)],
            env={**os.environ, 'PHP_BIN': '/usr/bin/php83', 'JSON_PHP_BIN': '/usr/bin/php83'})
    launchers = launcher_contract()
    # Before closing or migrating, old eSchool ad-hoc processes must also be
    # excluded. The only accepted application CLI processes are the two audited
    # Supervisor workers; any remaining Artisan process blocks before DDL.
    approved_pids = set()
    statuses = run(['supervisorctl', 'status']).splitlines()
    require(len(statuses) == 2, 'Unexpected additional Supervisor worker')
    for status in statuses:
        if status.split()[0] in ('eschool-queue:eschool-queue_00', 'websocket'):
            match = re.search(r'\bRUNNING\s+pid (\d+),', status)
            require(match, 'Supervised worker is not running')
            pid = int(match.group(1))
            command = (Path('/proc') / str(pid) / 'cmdline').read_bytes().rstrip(b'\0').decode().split('\0')
            validate_worker_command(command, 'queue' if status.startswith('eschool-queue:') else 'websocket')
            approved_pids.add(pid)
    require(len(approved_pids) == 2, 'Unexpected supervised worker topology')
    reject_unreviewed_app_processes(approved_pids | current_fpm_pids())
    backup = verify_backup(recovery)
    runtime = runtime_proof(release, sha) if phase == 'open' else {'processes': []}
    runtime['launchers'] = launchers
    proof = build_evidence(release, sha, phase, active, active_sha, backup, runtime)
    issuer = process(os.getpid(), 'evidence_issuer')
    proof['issuer_pid'] = issuer['pid']
    proof['issuer_start_ticks'] = issuer['start_ticks']
    proof['issuer'] = {'pid': issuer['pid'], 'start_ticks': issuer['start_ticks']}
    # Never follow a pre-existing symlink or overwrite an unsafe authority file.
    if EVIDENCE.parent.exists():
        trusted(EVIDENCE.parent, True)
    else:
        EVIDENCE.parent.mkdir(mode=0o750)
    os.chown(EVIDENCE.parent, 0, grp.getgrnam('www').gr_gid)
    os.chmod(EVIDENCE.parent, 0o750)
    if EVIDENCE.exists() or EVIDENCE.is_symlink():
        trusted(EVIDENCE)
    descriptor, name = tempfile.mkstemp(prefix='evidence-', dir=EVIDENCE.parent)
    with os.fdopen(descriptor, 'w') as stream:
        json.dump(proof, stream, sort_keys=True)
        stream.flush()
        os.fsync(stream.fileno())
    os.chown(name, 0, grp.getgrnam('www').gr_gid)
    os.chmod(name, 0o640)
    os.replace(name, EVIDENCE)
    print('P0_RELEASE_EVIDENCE_PASS:'+phase+':'+sha)
    if execute:
        arguments = {'preflight': ['finance:unidentified-deposit-p0-migrate', '--preflight'],
                     'close': ['finance:unidentified-deposit-p0-write-gate', 'close'],
                     'migrate': ['finance:unidentified-deposit-p0-migrate', '--execute'],
                     'open': ['finance:unidentified-deposit-p0-write-gate', 'open']}[phase]
        completed = subprocess.run(['bash', str(release / 'scripts/production/run_artisan_as_runtime_user.sh'),
                                    str(release), *arguments], env={**os.environ, 'RUNTIME_USER': 'www',
                                    'PHP_BIN': '/usr/bin/php83', 'P0_VERIFIED_RELEASE_SHA': sha})
        require(completed.returncode == 0, 'Artisan gate failed; inspect latch state independently, do not assume CLOSED')


if __name__ == '__main__':
    try:
        require(os.geteuid() == 0, 'Root deployment actor required')
        deployment_lock = Path('/run/lock/eschool-p0-deployment.lock')
        deployment_descriptor = os.open(deployment_lock, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
        deployment_info = os.fstat(deployment_descriptor)
        require(stat.S_ISREG(deployment_info.st_mode) and deployment_info.st_uid == 0
                and not deployment_info.st_mode & 0o022, 'Unsafe deployment lock')
        fcntl.flock(deployment_descriptor, fcntl.LOCK_EX | fcntl.LOCK_NB)
        lock = Path('/tmp/bowen_laravel_schedule.lock')
        trusted(lock)
        descriptor = os.open(lock, os.O_RDWR | os.O_NOFOLLOW)
        try:
            before = lock.lstat()
            after = os.fstat(descriptor)
            require(before.st_ino == after.st_ino and before.st_dev == after.st_dev
                    and after.st_uid == 0 and not after.st_mode & 0o022, 'Scheduler lock identity changed')
            fcntl.flock(descriptor, fcntl.LOCK_EX | fcntl.LOCK_NB)
            main()
        finally:
            os.close(descriptor)
            os.close(deployment_descriptor)
    except Exception as error:
        # Error messages intentionally exclude subprocess output and configuration.
        print('P0_RELEASE_EVIDENCE_FAIL:'+str(error), file=sys.stderr)
        sys.exit(1)
