<?php

declare(strict_types=1);

namespace WordPressR2Backup\Tests;

use RuntimeException;
use WordPressR2Backup\MysqlClient;

final class IsolatedMysql
{
    /** Privileges a dedicated importer may hold on its own schema; the default covers legacy dumps and v4 alike. */
    public const IMPORTER_PRIVILEGES = ['ALTER', 'ALTER ROUTINE', 'CREATE', 'CREATE ROUTINE', 'CREATE TEMPORARY TABLES', 'CREATE VIEW', 'DELETE', 'DROP', 'EVENT',
        'EXECUTE', 'INDEX', 'INSERT', 'LOCK TABLES', 'REFERENCES', 'SELECT', 'SHOW VIEW', 'TRIGGER', 'UPDATE'];
    private string $engine = 'mysql';
    private ?string $sharedImporter = null;
    private ?string $sharedSchema = null;
    private string $root;
    private $process = null;

    private function __construct()
    {
    }

    public static function start(?string $privateParent = null, string $eventScheduler = 'DISABLED', string $engine = 'mysql', array $serverArguments = []): self
    {
        if (!in_array($engine, ['mysql', 'mariadb', 'mariadb105'], true)) {
            throw new RuntimeException('Invalid isolated database engine.');
        }
        if (!in_array($eventScheduler, ['DISABLED', 'OFF', 'ON'], true)) {
            throw new RuntimeException('Invalid isolated MySQL event scheduler state.');
        }
        $self = new self();
        $self->engine = $engine;
        $base = realpath(sys_get_temp_dir());
        if ($base === false) {
            throw new RuntimeException('The temporary directory is unavailable.');
        }
        if ($privateParent !== null) {
            if (realpath($privateParent) !== $privateParent || !str_starts_with($privateParent, $base . '/wrb-')) {
                throw new RuntimeException('A canonical private fixture parent is required.');
            }
            \WordPressR2Backup\FileSafety::privateDirectory($privateParent);
            $base = $privateParent;
        }
        $root = $privateParent === null ? $base . '/wrbm-' . bin2hex(random_bytes(5)) : $base . '/db';
        if (!mkdir($root, 0700)) {
            throw new RuntimeException('Cannot create the isolated MySQL workspace.');
        }
        $self->root = $root;
        chmod($self->root, 0700);
        try {
            $self->writePrivate('.wrb-synthetic-mysql', "wordpress-r2-backup synthetic mysql v1\n");
            $self->writePrivate('client.cnf', "[client]\nuser=root\nsocket=\"" . $self->root . "/mysql.sock\"\n");
            if ($engine !== 'mysql') {
                $base = $self->mariadbBase();
                $self->run([$base . ($engine === 'mariadb105' ? 'scripts/' : 'bin/') . 'mariadb-install-db', '--no-defaults', '--basedir=' . $base, '--datadir=' . $self->root . '/data', '--auth-root-authentication-method=normal', '--skip-test-db'], '');
            } else {
                $self->run([self::mysqlBin() . 'mysqld', '--no-defaults', '--initialize-insecure', '--datadir=' . $self->root . '/data', '--log-error=' . $self->root . '/initialize.log'], '');
            }
            $self->process = proc_open(array_merge([
                $self->bin('mysqld'), '--no-defaults', '--datadir=' . $self->root . '/data',
                '--socket=' . $self->root . '/mysql.sock', '--skip-networking',
                '--event-scheduler=' . $eventScheduler, '--pid-file=' . $self->root . '/server.pid',
                '--log-error=' . $self->root . '/server.log', '--tmpdir=' . $self->root,
                '--secure-file-priv=' . $self->root, '--local-infile=0', '--skip-log-bin',
            ], $engine !== 'mysql' ? ['--basedir=' . $self->mariadbBase()] : ['--mysqlx=0'], $serverArguments), [0 => ['file', '/dev/null', 'r'], 1 => ['file', $self->root . '/stdout.log', 'a'], 2 => ['file', $self->root . '/stderr.log', 'a']], $pipes, $self->root, [], ['bypass_shell' => true]);
            if (!is_resource($self->process)) {
                throw new RuntimeException('Cannot start isolated MySQL.');
            }
            $deadline = microtime(true) + 30;
            do {
                if (!proc_get_status($self->process)['running']) {
                    throw new RuntimeException('Isolated MySQL stopped before readiness.');
                }
                clearstatcache(true, $self->root . '/mysql.sock');
                if (file_exists($self->root . '/mysql.sock')) {
                    try {
                        $self->query('', 'SELECT 1');
                        return $self;
                    } catch (RuntimeException $ignoredError) {
                    }
                }
                usleep(100000);
            } while (microtime(true) < $deadline);
            throw new RuntimeException('Isolated MySQL did not start within 30 seconds.');
        } catch (\Throwable $error) {
            $self->stop();
            throw $error;
        }
    }

    /** A configured runtime wins; do not silently choose a different database installation. */
    public static function mysqlBin(): string
    {
        $configured = getenv('WRB_TEST_MYSQL_BIN_DIR');
        $candidates = is_string($configured) && $configured !== '' ? [$configured] : explode(PATH_SEPARATOR, (string) getenv('PATH'));
        foreach ($candidates as $candidate) {
            $real = realpath($candidate);
            if ($real === false || !is_dir($real)) { continue; }
            foreach (['mysql', 'mysqldump', 'mysqld'] as $binary) { if (!is_executable($real . '/' . $binary)) { continue 2; } }
            return rtrim($real, '/') . '/';
        }
        throw new RuntimeException('Set WRB_TEST_MYSQL_BIN_DIR to a directory containing mysql, mysqldump and mysqld.');
    }

    private static function configuredMariaBase(string $engine): string
    {
        $name = $engine === 'mariadb105' ? 'WRB_TEST_MARIADB105_BASE' : 'WRB_TEST_MARIADB_BASE';
        $value = getenv($name);
        $real = is_string($value) && $value !== '' ? realpath($value) : false;
        if ($real === false || !is_dir($real)) { throw new RuntimeException('Set ' . $name . ' to the dedicated MariaDB installation directory.'); }
        return rtrim($real, '/') . '/';
    }

    public static function mariadbBin(): string { return self::configuredMariaBase('mariadb') . 'bin/'; }

    public static function mariadb105Bin(): string { return self::configuredMariaBase('mariadb105') . 'bin/'; }

    public static function mariadb105Available(): bool { return self::available('mariadb105'); }

    public function workspace(): string
    {
        return $this->root;
    }

    public function defaultsFile(): string
    {
        return $this->root . '/client.cnf';
    }

    public function client(): MysqlClient
    {
        return new MysqlClient($this->bin('mysql'), $this->bin('mysqldump'), $this->defaultsFile(), $this->root);
    }

    public function engine(): string
    {
        return $this->engine;
    }

    /** Creates an empty schema and, like an operator preparing a restore target, its own limited importer account. */
    public function newSchema(?array $importerPrivileges = null): string
    {
        $schema = 'wrb_test_' . bin2hex(random_bytes(8));
        $this->query('', 'CREATE DATABASE `' . $schema . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $this->createImporter($schema, $importerPrivileges ?? self::IMPORTER_PRIVILEGES);
        return $schema;
    }

    /**
     * Preparation-side checks that the importer cannot prove for itself: no anonymous account and no other host entry
     * can match the new account name. The server is trusted not to change grants or settings during the operation.
     */
    public function createImporter(string $schema, array $privileges): string
    {
        $this->assertSchema($schema);
        $user = $this->sharedImporter ?? $this->createAccount();
        if ($this->sharedImporter !== null && $this->sharedSchema !== null) {
            // One shared account may hold rights on only one schema at a time; the importer gate refuses anything wider.
            $this->query('', 'REVOKE ALL PRIVILEGES ON `' . str_replace('_', '\\_', $this->sharedSchema) . "`.* FROM '" . $user . "'@'localhost'");
        }
        if ($this->sharedImporter !== null) { $this->sharedSchema = $schema; }
        if ($privileges !== []) {
            // Underscore is a wildcard in a schema grant unless escaped; with partial revokes the name is taken literally instead.
            $literal = $this->engine === 'mysql' && in_array(trim($this->query('', 'SELECT @@GLOBAL.partial_revokes')), ['1', 'ON'], true) ? $schema : str_replace('_', '\\_', $schema);
            $this->query('', 'GRANT ' . implode(', ', $privileges) . ' ON `' . $literal . "`.* TO '" . $user . "'@'localhost'");
        }
        $this->writePrivate('importer-' . $schema . '.cnf', "[client]\nuser=" . $user . "\nsocket=\"" . $this->root . "/mysql.sock\"\n");
        return $user;
    }

    /**
     * For tests whose source objects carry a fixed DEFINER: every later target is imported by this one account, so the
     * definer clause in the dump names the importer itself and needs no extra privilege. Returns the quoted definer.
     */
    public function sharedImporter(): string
    {
        if ($this->sharedImporter === null) { $this->sharedImporter = $this->createAccount(); }
        return '`' . $this->sharedImporter . '`@`localhost`';
    }

    private function createAccount(): string
    {
        if (trim($this->query('', "SELECT COUNT(*) FROM mysql.user WHERE User=''")) !== '0') {
            throw new RuntimeException('An anonymous account makes a dedicated importer unprovable.');
        }
        $user = 'wrbi_' . bin2hex(random_bytes(6));
        $this->query('', "CREATE USER '" . $user . "'@'localhost' IDENTIFIED BY ''");
        if (trim($this->query('', "SELECT COUNT(*) FROM mysql.user WHERE User='" . $user . "'")) !== '1') {
            throw new RuntimeException('The importer account name is not unique.');
        }
        return $user;
    }

    public function importerUser(string $schema): string
    {
        $this->assertSchema($schema);
        if (preg_match('/^user=(wrbi_[0-9a-f]{12})$/m', (string) file_get_contents($this->root . '/importer-' . $schema . '.cnf'), $match) !== 1) {
            throw new RuntimeException('The schema has no generated importer account.');
        }
        return $match[1];
    }

    public function dropSchema(string $schema): void
    {
        $this->assertSchema($schema);
        $this->query('', 'DROP DATABASE `' . $schema . '`');
        $file = $this->root . '/importer-' . $schema . '.cnf';
        if (is_file($file) && preg_match('/^user=(wrbi_[0-9a-f]{12})$/m', (string) file_get_contents($file), $match) === 1) {
            if ($match[1] !== $this->sharedImporter) { $this->query('', "DROP USER '" . $match[1] . "'@'localhost'"); }
            unlink($file);
        }
    }

    public function execute(string $schema, string $sql): void
    {
        $this->assertSchema($schema);
        $this->query($schema, $sql);
    }

    public function rows(string $schema, string $sql): array
    {
        $this->assertSchema($schema);
        $output = rtrim($this->query($schema, $sql), "\n");
        if ($output === '') {
            return [];
        }
        return array_map(static fn (string $row): array => array_map(
            static fn (string $value): string => preg_replace_callback('/\\\\([0tnrbZ\\\\])/', static fn (array $m): string => [
                '0' => "\0", 't' => "\t", 'n' => "\n", 'r' => "\r", 'b' => "\x08", 'Z' => "\x1a", '\\' => '\\',
            ][$m[1]], $value),
            explode("\t", $row)
        ), explode("\n", $output));
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            if (proc_get_status($this->process)['running']) {
                proc_terminate($this->process, 15);
                $deadline = microtime(true) + 10;
                while (proc_get_status($this->process)['running'] && microtime(true) < $deadline) {
                    usleep(100000);
                }
                if (proc_get_status($this->process)['running']) {
                    throw new RuntimeException('Owned MySQL process did not stop after SIGTERM.');
                }
            }
            proc_close($this->process);
            $this->process = null;
        }
        if (isset($this->root) && is_dir($this->root)) {
            $this->removeOwned($this->root);
        }
    }

    public function __destruct()
    {
        $this->stop();
    }

    public function query(string $schema, string $sql): string
    {
        $command = [$this->bin('mysql'), '--no-defaults', '--protocol=SOCKET', '--socket=' . $this->root . '/mysql.sock', '--user=root', '--batch', '--skip-column-names', '--binary-mode=1', '--default-character-set=utf8mb4', '--connect-timeout=2'];
        if ($schema !== '') {
            $command[] = $schema;
        }
        return $this->run($command, $sql);
    }

    private function bin(string $name): string
    {
        if ($this->engine === 'mysql') {
            return self::mysqlBin() . $name;
        }
        return $this->mariadbBase() . 'bin/' . ['mysql' => 'mariadb', 'mysqldump' => 'mariadb-dump', 'mysqld' => 'mariadbd'][$name];
    }

    private function mariadbBase(): string
    {
        return self::configuredMariaBase($this->engine);
    }

    public static function available(string $engine): bool
    {
        if (!in_array($engine, ['mysql', 'mariadb', 'mariadb105'], true)) { return false; }
        try { return $engine === 'mysql' ? is_executable(self::mysqlBin() . 'mysqld') : is_executable(self::configuredMariaBase($engine) . 'bin/mariadbd'); }
        catch (RuntimeException $error) { return false; }
    }

    private function run(array $command, string $input): string
    {
        $inputPath = 'input-' . bin2hex(random_bytes(5));
        $outputPath = 'output-' . bin2hex(random_bytes(5));
        $errorPath = 'error-' . bin2hex(random_bytes(5));
        $this->writePrivate($inputPath, $input);
        $this->writePrivate($outputPath, '');
        $this->writePrivate($errorPath, '');
        $process = null;
        try {
            $process = proc_open($command, [0 => ['file', $this->root . '/' . $inputPath, 'r'], 1 => ['file', $this->root . '/' . $outputPath, 'w'], 2 => ['file', $this->root . '/' . $errorPath, 'w']], $pipes, $this->root, ['MYSQL_TEST_LOGIN_FILE' => $this->root . '/absent-login.cnf'], ['bypass_shell' => true]);
            if (!is_resource($process)) {
                throw new RuntimeException('Cannot execute an isolated MySQL command.');
            }
            $deadline = microtime(true) + 30;
            do {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    break;
                }
                if (microtime(true) > $deadline) {
                    proc_terminate($process, 15);
                    throw new RuntimeException('An isolated MySQL command timed out.');
                }
                usleep(10000);
            } while (true);
            $exit = proc_close($process);
            $process = null;
            if (($status['exitcode'] >= 0 ? $status['exitcode'] : $exit) !== 0) {
                throw new RuntimeException('An isolated MySQL command failed.');
            }
            return file_get_contents($this->root . '/' . $outputPath);
        } finally {
            if (is_resource($process)) {
                proc_terminate($process, 15);
                proc_close($process);
            }
            foreach ([$inputPath, $outputPath, $errorPath] as $path) {
                unlink($this->root . '/' . $path);
            }
        }
    }

    private function writePrivate(string $name, string $contents): void
    {
        $path = $this->root . '/' . $name;
        $stream = fopen($path, 'x+b');
        if ($stream === false) {
            throw new RuntimeException('Cannot create a private fixture file.');
        }
        try {
            if (!chmod($path, 0600) || fwrite($stream, $contents) !== strlen($contents)) {
                throw new RuntimeException('Cannot write a private fixture file.');
            }
        } finally {
            fclose($stream);
        }
    }

    private function assertSchema(string $schema): void
    {
        if (preg_match('/\Awrb_test_[a-z0-9_]{1,55}\z/D', $schema) !== 1) {
            throw new RuntimeException('Only generated synthetic schemas are allowed.');
        }
    }

    private function removeOwned(string $path): void
    {
        if (is_link($path) || !is_dir($path)) {
            unlink($path);
            return;
        }
        foreach (scandir($path) as $name) {
            if ($name !== '.' && $name !== '..') {
                $this->removeOwned($path . '/' . $name);
            }
        }
        rmdir($path);
    }
}
