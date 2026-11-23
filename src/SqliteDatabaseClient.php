<?php
declare(strict_types=1);

namespace WordPressR2Backup;

use RuntimeException;

/** Imports the bounded v4 SQL dialect into a new, private SQLite destination. */
final class SqliteDatabaseClient implements RecoveryDatabase
{
    private const MAX_STATEMENT_BYTES = 33554432;
    private const MAX_DEFINITIONS_BYTES = 16777216;
    private const INSERT_BATCH_ROWS = 100;
    private const INSERT_BATCH_BYTES = 524288;
    private const DIRECT_INSERT_BYTES = 1048576;
    private string $path;
    private string $directory;
    private array $identity;
    private array $parentIdentity;
    private $pin;
    private ?\WP_MySQL_On_SQLite $database = null;
    private ?\PDO $sqlite = null;
    private ?\WeakReference $closingDriver = null;
    private ?\WeakReference $closingPdo = null;
    private array $internalTables = [];
    private bool $attempted = false;
    private bool $imported = false;
    private array $definitions = [];
    private array $physicalDefinitions = [];
    private array $physicalTypes = [];
    private string $directPrefix = '';
    private string $directSql = '';
    private array $directTypes = [];
    private ?\PDOStatement $directInsert = null;
    private int $definitionBytes = 0;
    private $progress;
    private bool $backslashEscapes = true;
    private bool $ansiQuotes = false;

    public function __construct(string $newPrivateDatabaseFile, ?callable $progress = null)
    {
        $this->progress = $progress;
        if (!class_exists('WP_MySQL_On_SQLite') || !extension_loaded('pdo_sqlite')) { throw new RuntimeException('The verified WordPress SQLite driver and PDO SQLite are required.'); }
        FileSafety::assertNoLinks($newPrivateDatabaseFile);
        $this->directory = FileSafety::privateDirectory(dirname($newPrivateDatabaseFile));
        $this->path = $newPrivateDatabaseFile;
        if (@lstat($this->path) !== false) { throw new RuntimeException('SQLite recovery requires a new database file.'); }
        $this->pin = @fopen($this->path, 'x+b');
        if ($this->pin === false || !chmod($this->path, 0600)) { throw new RuntimeException('Cannot create a private SQLite recovery file.'); }
        $this->identity = fstat($this->pin); $this->parentIdentity = lstat($this->directory);
        $oldMask = umask(0077);
        try {
            $dsn = 'mysql-on-sqlite:path=' . str_replace(';', ';;', $this->path) . ';dbname=sqlite';
            $this->sqlite = new \PDO('sqlite:' . $this->path, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $this->database = new \WP_MySQL_On_SQLite($dsn, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                'sqlite_pdo' => $this->sqlite, 'sqlite_journal_mode' => 'DELETE', 'sqlite_synchronous' => 'FULL', 'sqlite_busy_timeout' => 5000]);
            $this->internalTables = $this->physicalTables();
            if (strtolower((string) $this->sqlite->query('PRAGMA journal_mode')->fetchColumn()) !== 'delete'
                || (int) $this->sqlite->query('PRAGMA synchronous')->fetchColumn() !== 2) { throw new RuntimeException('SQLite recovery requires DELETE journaling and FULL synchronization.'); }
            $this->guard(true);
        } catch (\Throwable $error) {
            $this->close();
            throw new RuntimeException('Cannot initialize the SQLite recovery database.');
        } finally { umask($oldMask); }
    }

    public function __destruct() { try { $this->close(); } catch (\Throwable $ignored) { } }

    public function path(): string { return $this->path; }

    public function close(): void
    {
        if ($this->directInsert !== null) { $this->directInsert->closeCursor(); $this->directInsert = null; }
        $this->directPrefix = ''; $this->directSql = ''; $this->directTypes = [];
        if ($this->database !== null) { $this->closingDriver = \WeakReference::create($this->database); }
        if ($this->sqlite !== null) { $this->closingPdo = \WeakReference::create($this->sqlite); }
        $this->database = null;
        $this->sqlite = null;
        // PDO user-defined functions retain the driver through a cycle; null alone leaves its connection open.
        gc_collect_cycles();
        if (($this->closingDriver !== null && $this->closingDriver->get() !== null)
            || ($this->closingPdo !== null && $this->closingPdo->get() !== null)) {
            throw new RuntimeException('The SQLite recovery connection remains referenced; retain its pinned file and retry close after releasing the reference.');
        }
        $this->closingDriver = null; $this->closingPdo = null;
        if (is_resource($this->pin)) { fclose($this->pin); }
        $this->pin = null;
    }

    public function importIntoEmpty(string $schema, string $sqlFile, ?array $expectedEvents = null): void
    {
        $this->schema($schema); $this->guard(true);
        if ($expectedEvents !== null && $expectedEvents !== []) { throw new RuntimeException('SQLite recovery does not support database events.'); }
        if ($this->attempted || $this->tables() !== []) { throw new RuntimeException('A SQLite import requires a new empty database; use a fresh target after failure.'); }
        FileSafety::assertRegularFile($sqlFile); FileSafety::privateDirectory(dirname($sqlFile));
        $this->attempted = true;
        $source = fopen($sqlFile, 'rb');
        if ($source === false) { throw new RuntimeException('Cannot open the private SQLite import input.'); }
        $oldMask = umask(0077);
        try {
            $this->sqlite->exec('PRAGMA foreign_keys=OFF');
            if ((int) $this->sqlite->query('PRAGMA foreign_keys')->fetchColumn() !== 0) { throw new RuntimeException('Cannot defer SQLite foreign-key validation before import.'); }
            $this->database->beginTransaction();
            $processed = 0;
            $batch = ''; $batchPrefix = ''; $batchRows = 0;
            $this->progress(['phase' => 'sqlite-import', 'statements' => 0, 'bytes' => 0]);
            foreach ($this->statements($source) as $statement) {
                $this->guard();
                $created = null;
                if (preg_match('/\ACREATE TABLE `((?:[^`]|``)+)`\s*\(/D', $statement, $match)) {
                    $this->flushBatch($batch, $batchPrefix, $batchRows);
                    $name = str_replace('``', '`', $match[1]);
                    if (isset($this->definitions[$name]) || count($this->definitions) >= 10000 || strlen($statement) > 1048576) { throw new RuntimeException('Invalid or repeated SQLite import definition.'); }
                    $this->definitionBytes += strlen($statement);
                    if ($this->definitionBytes > self::MAX_DEFINITIONS_BYTES) { throw new RuntimeException('SQLite import definitions exceed their bound.'); }
                    $this->supportedDefinition($statement);
                    $this->definitions[$name] = $statement;
                    $created = $name;
                } elseif (preg_match('/\AINSERT INTO `((?:[^`]|``)+)` \((?:`(?:[^`]|``)+`)(?:,`(?:[^`]|``)+`)*\) VALUES \(/D', $statement, $match)) {
                    $table = str_replace('``', '`', $match[1]);
                    if (!isset($this->definitions[$table])) { throw new RuntimeException('SQLite data has no preceding table definition.'); }
                    $values = $this->validateValues($statement, strlen($match[0]));
                    if ($values !== null && $this->canInsertDirectly($match[0], $table, $values)) {
                        $this->flushBatch($batch, $batchPrefix, $batchRows);
                        $this->insertDirectly($values);
                        if (++$processed % 1000 === 0) { $this->progress(['phase' => 'sqlite-import', 'statements' => $processed, 'bytes' => ftell($source)]); }
                        continue;
                    }
                    $prefix = $match[0]; $append = ',(' . substr($statement, strlen($prefix));
                    if ($batchRows !== 0 && ($batchPrefix !== $prefix || $batchRows >= self::INSERT_BATCH_ROWS || strlen($batch) + strlen($append) > self::INSERT_BATCH_BYTES)) { $this->flushBatch($batch, $batchPrefix, $batchRows); }
                    if ($batchRows === 0) { $batch = $statement; $batchPrefix = $prefix; }
                    else { $batch .= $append; }
                    ++$batchRows;
                    if ($batchRows >= self::INSERT_BATCH_ROWS || strlen($batch) >= self::INSERT_BATCH_BYTES) { $this->flushBatch($batch, $batchPrefix, $batchRows); }
                    if (++$processed % 1000 === 0) { $this->flushBatch($batch, $batchPrefix, $batchRows); $this->progress(['phase' => 'sqlite-import', 'statements' => $processed, 'bytes' => ftell($source)]); }
                    continue;
                } elseif (!$this->allowedSetting($statement)) {
                    throw new RuntimeException('SQLite recovery accepts only the v4 database export dialect.');
                }
                $this->flushBatch($batch, $batchPrefix, $batchRows);
                $this->database->exec($statement);
                if ($created !== null) {
                    $physical = $this->physicalDefinition($created);
                    $this->verifyConvertedConstraints($created, $physical);
                    $this->physicalDefinitions[$created] = hash('sha256', serialize($physical));
                    $this->physicalTypes[$created] = array_column($physical['columns'], 'type', 'name');
                    if (!$this->database->inTransaction()) { $this->database->beginTransaction(); }
                }
                if (++$processed % 1000 === 0) { $this->progress(['phase' => 'sqlite-import', 'statements' => $processed, 'bytes' => ftell($source)]); }
            }
            $this->flushBatch($batch, $batchPrefix, $batchRows);
            if ($this->definitions === []) { throw new RuntimeException('The SQLite import contains no tables.'); }
            if ($this->database->inTransaction()) { $this->database->commit(); }
            $this->guard(true); $this->imported = true;
            $this->progress(['phase' => 'sqlite-imported', 'statements' => $processed, 'bytes' => ftell($source)]);
        } catch (\Throwable $error) {
            try { if ($this->database !== null && $this->database->inTransaction()) { $this->database->rollBack(); } } catch (\Throwable $ignored) { }
            throw new RuntimeException('SQLite database import failed; use a fresh destination.', 0, $error);
        } finally { fclose($source); umask($oldMask); }
    }

    public function verifyV4Database(string $schema, iterable $tableRecords, array $environment): void
    {
        $this->schema($schema); $this->guard(true);
        if (!$this->imported) { throw new RuntimeException('SQLite verification requires a completed import in this job.'); }
        $fields = array_keys($environment); sort($fields);
        if ($fields !== ['characterSet', 'collation', 'serverVersion', 'sqlMode', 'timeZone']) { throw new RuntimeException('Invalid SQLite verification environment.'); }
        foreach ($environment as $value) { if (!is_string($value) || strlen($value) > 16384 || strpos($value, "\0") !== false) { throw new RuntimeException('Invalid SQLite verification environment.'); } }
        $names = [];
        try {
            foreach ($tableRecords as $table) {
                V4Format::validateRecord('database', $table);
                if (($table['type'] ?? null) !== 'table' || isset($names[$table['name']]) || !isset($this->definitions[$table['name']])) { throw new RuntimeException('Invalid SQLite verification table.'); }
                $name = $table['name']; $names[$name] = true;
                if (!hash_equals($table['definitionSha256'], WordPressDatabase::definitionSha256($this->definitions[$name], $environment['sqlMode']))) { throw new RuntimeException('SQLite import source definition differs.'); }
                $definition = $this->physicalDefinition($name);
                if (!hash_equals($this->physicalDefinitions[$name], hash('sha256', serialize($definition)))) { throw new RuntimeException('SQLite converted physical definition changed.'); }
                $this->verifyManifestForeignKeys($table['foreignKeys'], $definition['foreignKeys']);
                $columns = $this->database->query('SHOW COLUMNS FROM ' . self::identifier($name))->fetchAll(\PDO::FETCH_ASSOC);
                if (array_column($columns, 'Field') !== $table['columns']) { throw new RuntimeException('SQLite restored columns differ.'); }
                $physical = $this->sqlite->query('PRAGMA table_xinfo("' . str_replace('"', '""', $name) . '")')->fetchAll(\PDO::FETCH_ASSOC);
                if (array_column($physical, 'name') !== $table['columns']) { throw new RuntimeException('SQLite physical columns differ.'); }
                $statement = $this->database->query($this->valueQuery($name, $table['columns'], $table['order']));
                $count = 0; $digest = hash_init('sha256');
                while (($row = $statement->fetch(\PDO::FETCH_ASSOC)) !== false) {
                    $rowHash = hash_init('sha256');
                    foreach ($table['columns'] as $column) {
                        $value = $row[$column];
                        hash_update($rowHash, $value === null ? 'N;' : 'V' . strlen((string) $value) . ':' . (string) $value . ';');
                    }
                    hash_update($digest, hash_final($rowHash) . "\n"); ++$count;
                }
                $statement->closeCursor();
                if ($count !== $table['rows'] || !hash_equals($table['rowSha256'], hash_final($digest))) { throw new RuntimeException('SQLite restored values differ from the selected generation.'); }
                foreach ($table['foreignKeys'] as $foreignKey) {
                    $equal = []; $present = [];
                    foreach ($foreignKey['columns'] as $position => $column) {
                        $equal[] = 'c.' . self::identifier($column) . '=p.' . self::identifier($foreignKey['referencedColumns'][$position]);
                        $present[] = 'c.' . self::identifier($column) . ' IS NOT NULL';
                    }
                    $query = 'SELECT COUNT(*) FROM ' . self::identifier($name) . ' c LEFT JOIN ' . self::identifier($foreignKey['referencedTable']) . ' p ON ' . implode(' AND ', $equal)
                        . ' WHERE ' . implode(' AND ', $present) . ' AND p.' . self::identifier($foreignKey['referencedColumns'][0]) . ' IS NULL';
                    if ((string) $this->database->query($query)->fetchColumn() !== '0') { throw new RuntimeException('SQLite restored references differ.'); }
                }
                $this->progress(['phase' => 'sqlite-verification', 'tables' => count($names), 'totalTables' => count($this->definitions), 'rows' => $count]);
            }
            $expected = array_keys($names); sort($expected); $actual = $this->tables(); sort($actual);
            if ($expected === [] || $expected !== $actual) { throw new RuntimeException('SQLite restored table inventory differs.'); }
            $physical = array_values(array_diff($this->physicalTables(), $this->internalTables, ['sqlite_sequence'])); sort($physical);
            if ($physical !== $expected) { throw new RuntimeException('SQLite physical table inventory differs.'); }
            $this->sqlite->exec('PRAGMA foreign_keys=ON');
            if ((int) $this->sqlite->query('PRAGMA foreign_keys')->fetchColumn() !== 1) { throw new RuntimeException('Cannot enable SQLite foreign-key enforcement after verification.'); }
            $this->guard(true);
        } catch (\Throwable $error) { throw new RuntimeException('SQLite database verification failed.', 0, $error); }
    }

    private function valueQuery(string $name, array $columns, array $order): string
    {
        $fields = array_map(static function(string $column): string { return 'CAST(' . self::identifier($column) . ' AS BINARY) AS ' . self::identifier($column); }, $columns);
        $ordering = [];
        foreach (array_unique(array_merge($order, $columns)) as $column) { $id = self::identifier($column); $ordering[] = '(' . $id . ' IS NOT NULL)'; $ordering[] = 'CAST(' . $id . ' AS BINARY)'; }
        return 'SELECT ' . implode(',', $fields) . ' FROM ' . self::identifier($name) . ' ORDER BY ' . implode(',', $ordering);
    }

    private function flushBatch(string &$sql, string &$prefix, int &$rows): void
    {
        if ($rows === 0) { return; }
        $this->guard();
        $this->database->exec($sql);
        $sql = ''; $prefix = ''; $rows = 0;
    }

    private function supportedDefinition(string $sql): void
    {
        $lexer = new \WP_MySQL_Lexer($sql);
        while ($lexer->next_token()) {
            $token = $lexer->get_token();
            if ($token !== null && in_array($token->id, [\WP_MySQL_Lexer::CHECK_SYMBOL, \WP_MySQL_Lexer::FULLTEXT_SYMBOL, \WP_MySQL_Lexer::SPATIAL_SYMBOL], true)) {
                throw new RuntimeException('SQLite recovery does not support this source constraint or index.');
            }
        }
    }

    private function boundedRows($statement): array
    {
        $rows = []; $bytes = 0;
        while (($row = $statement->fetch(\PDO::FETCH_ASSOC)) !== false) {
            $bytes += strlen(serialize($row));
            if (count($rows) >= 8192 || $bytes > 4194304) { throw new RuntimeException('SQLite schema metadata exceeds its bound.'); }
            $rows[] = $row;
        }
        $statement->closeCursor();
        return $rows;
    }

    private function pragmaRows(string $operation, string $name): array
    {
        return $this->boundedRows($this->sqlite->query('PRAGMA ' . $operation . '("' . str_replace('"', '""', $name) . '")'));
    }

    private function physicalDefinition(string $name): array
    {
        $query = $this->sqlite->prepare('SELECT type,name,tbl_name,sql FROM sqlite_master WHERE tbl_name=? ORDER BY type,name');
        $query->execute([$name]);
        $definition = ['master' => $this->boundedRows($query), 'columns' => $this->pragmaRows('table_xinfo', $name), 'foreignKeys' => $this->pragmaRows('foreign_key_list', $name), 'indexes' => []];
        foreach ($this->pragmaRows('index_list', $name) as $index) {
            $definition['indexes'][] = ['index' => $index, 'columns' => $this->pragmaRows('index_info', $index['name']), 'extended' => $this->pragmaRows('index_xinfo', $index['name'])];
        }
        if (strlen(serialize($definition)) > 4194304) { throw new RuntimeException('SQLite physical definition exceeds its bound.'); }
        return $definition;
    }

    /** Source metadata is supplied by the parser; compare it with the actual converted constraints. */
    private function verifyConvertedConstraints(string $name, array $physical): void
    {
        $columns = $this->boundedRows($this->database->query('SHOW COLUMNS FROM ' . self::identifier($name)));
        if (array_column($columns, 'Field') !== array_column($physical['columns'], 'name')) { throw new RuntimeException('SQLite converted column inventory differs.'); }
        foreach ($columns as $offset => $column) {
            $actual = $physical['columns'][$offset];
            if (($column['Null'] === 'NO') !== ((int) $actual['notnull'] === 1)) { throw new RuntimeException('SQLite converted nullability differs.'); }
            $generated = preg_match('/\bVIRTUAL\s+GENERATED\b/i', $column['Extra']) ? 2 : (preg_match('/\bSTORED\s+GENERATED\b/i', $column['Extra']) ? 3 : 0);
            if ($generated !== (int) $actual['hidden']) { throw new RuntimeException('SQLite converted generated column differs.'); }
        }
        $primary = []; $unique = [];
        foreach ($this->boundedRows($this->database->query('SHOW INDEX FROM ' . self::identifier($name))) as $index) {
            if ((int) $index['Non_unique'] !== 0) { continue; }
            if ($index['Column_name'] === null || $index['Sub_part'] !== null || ($index['Expression'] ?? null) !== null || $index['Index_type'] !== 'BTREE') { throw new RuntimeException('SQLite recovery cannot preserve this unique index.'); }
            if ($index['Key_name'] === 'PRIMARY') { $primary[(int) $index['Seq_in_index']] = $index['Column_name']; }
            else { $unique[$index['Key_name']][(int) $index['Seq_in_index']] = $index['Column_name']; }
        }
        ksort($primary); $actualPrimary = [];
        foreach ($physical['columns'] as $column) { if ((int) $column['pk'] > 0) { $actualPrimary[(int) $column['pk']] = $column['name']; } }
        ksort($actualPrimary);
        if (array_values($primary) !== array_values($actualPrimary)) { throw new RuntimeException('SQLite converted primary key differs.'); }
        $expectedUnique = [];
        foreach ($unique as $parts) { ksort($parts); $expectedUnique[] = serialize(array_values($parts)); }
        $actualUnique = [];
        foreach ($physical['indexes'] as $index) {
            if ((int) $index['index']['unique'] !== 1 || $index['index']['origin'] === 'pk') { continue; }
            if ((int) $index['index']['partial'] !== 0 || in_array(null, array_column($index['columns'], 'name'), true)) { throw new RuntimeException('SQLite converted unique index is unsupported.'); }
            $actualUnique[] = serialize(array_column($index['columns'], 'name'));
        }
        sort($expectedUnique); sort($actualUnique);
        if ($expectedUnique !== $actualUnique) { throw new RuntimeException('SQLite converted unique keys differ.'); }
        $literal = "'" . str_replace("'", "''", $name) . "'";
        $foreignKeys = [];
        foreach ($this->boundedRows($this->database->query('SELECT CONSTRAINT_NAME,COLUMN_NAME,ORDINAL_POSITION,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=' . $literal . ' AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY CONSTRAINT_NAME,ORDINAL_POSITION')) as $row) {
            $foreignKeys[$row['CONSTRAINT_NAME']][] = [$row['COLUMN_NAME'], $row['REFERENCED_TABLE_NAME'], $row['REFERENCED_COLUMN_NAME']];
        }
        $rules = [];
        foreach ($this->boundedRows($this->database->query('SELECT CONSTRAINT_NAME,UPDATE_RULE,DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=' . $literal)) as $row) {
            $rules[$row['CONSTRAINT_NAME']] = [$this->foreignRule($row['UPDATE_RULE']), $this->foreignRule($row['DELETE_RULE'])];
        }
        $expectedForeign = [];
        foreach ($foreignKeys as $key => $parts) {
            if (!isset($rules[$key])) { throw new RuntimeException('SQLite source foreign-key rules are incomplete.'); }
            $expectedForeign[] = serialize([$parts, $rules[$key]]);
        }
        $actualForeign = [];
        foreach ($physical['foreignKeys'] as $row) {
            if ($row['match'] !== 'NONE') { throw new RuntimeException('SQLite foreign-key match is unsupported.'); }
            $key = (int) $row['id']; $actualForeign[$key]['columns'][(int) $row['seq']] = [$row['from'], $row['table'], $row['to']];
            $actualForeign[$key]['rules'] = [$this->foreignRule($row['on_update']), $this->foreignRule($row['on_delete'])];
        }
        $actualForeignSignatures = [];
        foreach ($actualForeign as $foreign) { ksort($foreign['columns']); $actualForeignSignatures[] = serialize([array_values($foreign['columns']), $foreign['rules']]); }
        sort($expectedForeign); sort($actualForeignSignatures);
        if ($expectedForeign !== $actualForeignSignatures) { throw new RuntimeException('SQLite converted foreign keys differ.'); }
    }

    private function foreignRule(string $rule): string { return $rule === 'NO ACTION' ? 'RESTRICT' : $rule; }

    private function verifyManifestForeignKeys(array $expected, array $physical): void
    {
        $expectedSignatures = [];
        foreach ($expected as $key) { $expectedSignatures[] = serialize([$key['columns'], $key['referencedTable'], $key['referencedColumns']]); }
        $keys = [];
        foreach ($physical as $row) {
            $id = (int) $row['id']; $position = (int) $row['seq'];
            $keys[$id]['columns'][$position] = $row['from']; $keys[$id]['table'] = $row['table']; $keys[$id]['references'][$position] = $row['to'];
        }
        $actualSignatures = [];
        foreach ($keys as $key) {
            ksort($key['columns']); ksort($key['references']);
            $actualSignatures[] = serialize([array_values($key['columns']), $key['table'], array_values($key['references'])]);
        }
        sort($expectedSignatures); sort($actualSignatures);
        if ($expectedSignatures !== $actualSignatures) { throw new RuntimeException('SQLite physical foreign keys differ from the selected generation.'); }
    }

    private function tables(): array { return $this->database->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN); }

    private function progress(array $state): void { if ($this->progress !== null) { ($this->progress)($state); } }

    private function physicalTables(): array { return $this->sqlite->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN); }

    private function schema(string $schema): void
    {
        if ($schema !== 'sqlite' || $this->database === null) { throw new RuntimeException('SQLite recovery requires its explicit sqlite destination.'); }
    }

    private static function identifier(string $name): string { return '`' . str_replace('`', '``', $name) . '`'; }

    private function allowedSetting(string $sql): bool
    {
        if (preg_match('/\ASET SESSION SQL_MODE=\x27([A-Z0-9_,]*)\x27\z/D', $sql, $mode)) {
            $list = ',' . $mode[1] . ',';
            $this->backslashEscapes = strpos($list, ',NO_BACKSLASH_ESCAPES,') === false;
            $this->ansiQuotes = strpos($list, ',ANSI_QUOTES,') !== false;
        }
        return preg_match('/\ASET (?:@WRB4_SQL_MODE=@@SESSION\.SQL_MODE,@WRB4_TIME_ZONE=@@SESSION\.time_zone,@WRB4_FOREIGN_KEY_CHECKS=@@SESSION\.FOREIGN_KEY_CHECKS|SESSION SQL_MODE=\x27[A-Z0-9_,]*\x27|SESSION time_zone=\x27\+00:00\x27|NAMES [a-zA-Z0-9_]{1,64}|FOREIGN_KEY_CHECKS=(?:0|@WRB4_FOREIGN_KEY_CHECKS)|SQL_MODE=@WRB4_SQL_MODE|SESSION time_zone=@WRB4_TIME_ZONE)\z/D', $sql) === 1;
    }

    private function validateValues(string $sql, int $offset): ?array
    {
        $length = strlen($sql);
        $values = $length <= self::DIRECT_INSERT_BYTES ? [] : null;
        while ($offset < $length) {
            if (preg_match('/\G(?:NULL|[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?|X\x27[0-9a-fA-F]*\x27|CONVERT\(X\x27[0-9a-fA-F]*\x27 USING [A-Za-z0-9_]{1,64}\))([,)])/D', $sql, $match, 0, $offset) !== 1) {
                throw new RuntimeException('SQLite import values are outside the v4 export dialect.');
            }
            if ($values !== null) {
                $decoded = $this->decodeLiteral(substr($match[0], 0, -1));
                if ($decoded === null) { $values = null; }
                else { $values[] = $decoded; }
            }
            $offset += strlen($match[0]);
            if ($match[1] === ')') {
                if ($offset !== $length) { throw new RuntimeException('SQLite import contains trailing SQL.'); }
                return $values;
            }
        }
        throw new RuntimeException('SQLite import values are incomplete.');
    }

    /** Decode only the literals emitted by v4; other character sets keep the official SQL path. */
    private function decodeLiteral(string $literal): ?array
    {
        if ($literal === 'NULL') { return ['kind' => 'null', 'value' => null, 'type' => \PDO::PARAM_NULL]; }
        if (substr($literal, 0, 2) === "X'") {
            $hex = substr($literal, 2, -1);
            if (strlen($hex) % 2 !== 0) { return null; }
            $value = hex2bin($hex);
            return $value === false ? null : ['kind' => 'blob', 'value' => $value, 'type' => \PDO::PARAM_LOB];
        }
        if (preg_match('/\ACONVERT\(X\x27([a-fA-F0-9]*)\x27 USING ([A-Za-z0-9_]{1,64})\)\z/D', $literal, $match)) {
            $charset = strtolower($match[2]);
            if (!in_array($charset, ['ascii', 'utf8', 'utf8mb3', 'utf8mb4'], true)) { return null; }
            if (strlen($match[1]) % 2 !== 0) { return null; }
            $value = hex2bin($match[1]);
            if ($value === false || !mb_check_encoding($value, 'UTF-8') || ($charset === 'ascii' && preg_match('/[^\x00-\x7f]/', $value))
                || (in_array($charset, ['utf8', 'utf8mb3'], true) && preg_match('/[\xf0-\xf4]/', $value))) { return null; }
            return ['kind' => 'text', 'value' => $value, 'type' => \PDO::PARAM_STR];
        }
        return ['kind' => 'number', 'value' => $literal, 'type' => \PDO::PARAM_STR];
    }

    private function canInsertDirectly(string $prefix, string $table, array $values): bool
    {
        if ($this->directPrefix !== $prefix) {
            if ($this->directInsert !== null) { $this->directInsert->closeCursor(); $this->directInsert = null; }
            $this->directPrefix = $prefix; $this->directTypes = []; $this->directSql = '';
            preg_match_all('/`((?:[^`]|``)+)`/', $prefix, $quoted);
            $identifiers = array_map(static function(string $name): string { return str_replace('``', '`', $name); }, $quoted[1]);
            if (array_shift($identifiers) !== $table || count(array_unique($identifiers)) !== count($identifiers)) { return false; }
            foreach ($identifiers as $column) {
                if (!isset($this->physicalTypes[$table][$column])) { $this->directTypes = []; return false; }
                $this->directTypes[] = strtoupper($this->physicalTypes[$table][$column]);
            }
            $quote = static function(string $name): string { return '"' . str_replace('"', '""', $name) . '"'; };
            $this->directSql = 'INSERT INTO ' . $quote($table) . ' (' . implode(',', array_map($quote, $identifiers)) . ') VALUES (' . implode(',', array_fill(0, count($identifiers), '?')) . ')';
        }
        if (count($this->directTypes) !== count($values)) { return false; }
        foreach ($values as $offset => $value) {
            $type = $this->directTypes[$offset];
            if (($value['kind'] === 'blob' && $type !== 'BLOB') || ($value['kind'] === 'text' && $type !== 'TEXT')
                || ($value['kind'] === 'number' && !in_array($type, ['INTEGER', 'INT', 'REAL', 'TEXT', 'NUMERIC'], true))) { return false; }
        }
        if ($this->directInsert === null) { $this->directInsert = $this->sqlite->prepare($this->directSql); }
        return true;
    }

    private function insertDirectly(array $values): void
    {
        foreach ($values as $offset => $value) { $this->directInsert->bindValue($offset + 1, $value['value'], $value['type']); }
        try { $this->directInsert->execute(); }
        finally { $this->directInsert->closeCursor(); }
    }

    /** Source v4 SQL uses backtick identifiers and hex text; delimiters inside quotes remain data. */
    private function statements($source): \Generator
    {
        $buffer = ''; $quote = null; $escaped = false;
        while (!feof($source)) {
            $chunk = fread($source, 65536);
            if ($chunk === false || ($chunk === '' && !feof($source))) { throw new RuntimeException('Cannot read the SQLite import stream.'); }
            $length = strlen($chunk);
            for ($offset = 0; $offset < $length;) {
                if ($escaped) {
                    if (strlen($buffer) === self::MAX_STATEMENT_BYTES) { throw new RuntimeException('SQLite import statement exceeds its bound.'); }
                    $buffer .= $chunk[$offset++]; $escaped = false;
                    continue;
                }
                $escapeQuote = $quote !== null && $this->backslashEscapes && $quote !== '`' && !($quote === '"' && $this->ansiQuotes);
                $special = $quote === null ? ";'\"`" : $quote . ($escapeQuote ? '\\' : '');
                $span = strcspn($chunk, $special, $offset);
                if ($span !== 0) {
                    if (strlen($buffer) + $span > self::MAX_STATEMENT_BYTES) { throw new RuntimeException('SQLite import statement exceeds its bound.'); }
                    $buffer .= substr($chunk, $offset, $span); $offset += $span;
                    if ($offset === $length) { break; }
                }
                $character = $chunk[$offset++];
                if ($quote === null && $character === ';') { $statement = trim($buffer); $buffer = ''; if ($statement !== '') { yield $statement; } continue; }
                $buffer .= $character;
                if (strlen($buffer) > self::MAX_STATEMENT_BYTES) { throw new RuntimeException('SQLite import statement exceeds its bound.'); }
                if ($quote !== null) {
                    if ($character === '\\' && $escapeQuote) { $escaped = true; }
                    elseif ($character === $quote) { $quote = null; }
                } elseif ($character === "'" || $character === '"' || $character === '`') { $quote = $character; }
            }
        }
        if ($quote !== null || trim($buffer) !== '') { throw new RuntimeException('SQLite import ends in an incomplete statement.'); }
    }

    private function guard(bool $allAncestors = false): void
    {
        if ($allAncestors) { FileSafety::assertNoLinks($this->path); }
        clearstatcache(true, $this->path); clearstatcache(true, $this->directory);
        $file = @lstat($this->path); $parent = @lstat($this->directory); $pin = is_resource($this->pin) ? fstat($this->pin) : false;
        if ($file === false || $parent === false || $pin === false || ($file['mode'] & 0177777) !== 0100600 || $file['nlink'] !== 1
            || $file['dev'] !== $this->identity['dev'] || $file['ino'] !== $this->identity['ino'] || $file['uid'] !== $this->identity['uid']
            || $pin['ino'] !== $file['ino'] || $pin['dev'] !== $file['dev'] || $parent['dev'] !== $this->parentIdentity['dev']
            || $parent['ino'] !== $this->parentIdentity['ino'] || $parent['uid'] !== $this->parentIdentity['uid'] || ($parent['mode'] & 0177777) !== 040700) {
            throw new RuntimeException('The SQLite recovery destination changed.');
        }
    }
}
