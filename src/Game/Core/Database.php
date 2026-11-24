<?php

/**
 *  2Moons
 *   by Jan-Otto Kröpke 2009-2016
 *
 * For the full copyright and license information, please view the LICENSE
 *
 * @package 2Moons
 * @author Jan-Otto Kröpke <slaver7@gmail.com>
 * @copyright 2009 Lucky
 * @copyright 2016 Jan-Otto Kröpke <slaver7@gmail.com>
 * @licence MIT
 * @version 1.8.0
 * @link https://github.com/jkroepke/2Moons
 */

namespace App\Game\Core;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Exception;
use PDO;
use PDOException;
use PDOStatement;

/**
 * PDO wrapper of the game: table placeholders %%TABLE%% (names from Support/tables.php with the configured prefix)
 * are replaced in every query, queries use named parameters, and the verbs are checked (select/insert/update/delete/replace
 * accept only their own statement type). Shares Symfony's DBAL connection (UTF-8, STRICT_ALL_TABLES), keeps the last
 * insert id and row count, counts queries and records them in debug mode. Errors throw Exception with the query text.
 */
class Database
{
    /** Connection setup of the game (charset + sql_mode without ONLY_FULL_GROUP_BY etc.). */
    public const CHARSET_COMMAND = "SET CHARACTER SET utf8mb4, NAMES utf8mb4, sql_mode = 'STRICT_ALL_TABLES'";

    /**
     * Isolation level of the game's connection, set right after connecting: READ COMMITTED takes no gap/next-key locks on
     * range scans (the cause of most deadlocks between page requests and fleet events). Own statement because it has no
     * system variable that both MySQL 8 and MariaDB 10.11 know. Needs binlog_format=ROW when binary logging is on.
     */
    public const ISOLATION_COMMAND = 'SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED';

    /**
     * Both statements in one round trip (PDO init command, multi statements are on by default). The Doctrine
     * connection (config/packages/doctrine.yaml, option 1002) carries the same string.
     */
    public const INIT_COMMAND = self::CHARSET_COMMAND . '; ' . self::ISOLATION_COMMAND;

    /** MySQL error numbers worth repeating a transaction for: deadlock victim and lock wait timeout. */
    public const ERROR_DUPLICATE_KEY = 1062;
    public const ERROR_DEADLOCK = 1213;
    public const ERROR_LOCK_WAIT_TIMEOUT = 1205;

    private PDO $dbHandle;
    /** @var array<mixed> */
    private array $dbTableNames = [];
    /**
     * @var array<mixed> connection settings this instance was created with (host, port, user, userpw, databasename, ...)
     */
    private array $connectionConfig = [];
    private int $lastInsertId = 0;
    private int $rowCount = 0;
    private int $queryCounter = 0;
    /** DBAL connection behind the QueryBuilder access (null without Symfony context) */
    private ?Connection $dbal = null;

    /**
     * @return array<mixed>
     */
    public function getConnectionConfig(): array
    {
        return $this->connectionConfig;
    }

    /** table prefix of the game tables (formerly DB_PREFIX) */
    public function getTablePrefix(): string
    {
        return (string) $this->connectionConfig['tableprefix'];
    }

    /** schema name (formerly DB_NAME) */
    public function getDatabaseName(): string
    {
        return (string) $this->connectionConfig['databasename'];
    }

    /**
     * Shares Symfony's DBAL connection (same init command: config/packages/doctrine.yaml); without one
     * (no Symfony context) it connects itself with the settings from DatabaseConfig.
     */
    public function __construct(
        DatabaseConfig $config,
        private Singleton $state,
        ?Connection $connection = null,
        /** debug mode: every query is recorded in $state->queryLog for the profiler (GameDatabaseCollector) */
        private bool $debug = false,
    ) {
        $database = $config->get();
        $this->connectionConfig = $database;
        $db = $connection !== null ? $connection->getNativeConnection() : new PDO(
            "mysql:host=" . $database['host'] . ";port=" . $database['port'] . ";dbname=" . $database['databasename'],
            $database['user'],
            $database['userpw'],
            [PDO::MYSQL_ATTR_INIT_COMMAND => self::INIT_COMMAND],
        );
        //error behaviour
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        // $db->query("set character set utf8");
        // $db->query("set names utf8");
        // $db->query("SET sql_mode = 'STRICT_ALL_TABLES'");
        $this->dbHandle = $db;
        $this->dbal = $connection;
        $dbTableNames = [];
        include __DIR__ . '/../Support/tables.php';
        foreach ($dbTableNames as $key => $name) {
            $this->dbTableNames['keys'][]   = '%%' . $key . '%%';
            $this->dbTableNames['names'][]  = $name;
        }
    }

    /**
     * Prefixed table name of a game table for QueryBuilder queries, e.g. table('USERS') gives "uni1_users"
     * (the same table the placeholder %%USERS%% stands for in plain SQL). Unknown names throw.
     *
     * @param string $key placeholder name without the %% (key of Support/tables.php)
     * @throws Exception when no such game table exists
     */
    public function table(string $key): string
    {
        $index = array_search('%%' . $key . '%%', $this->dbTableNames['keys'], true);
        if ($index === false) {
            throw new Exception("Unknown table $key");
        }

        return $this->dbTableNames['names'][$index];
    }

    /**
     * New DBAL QueryBuilder on the game's connection, for queries whose SQL is assembled at runtime
     * (optional filters, dynamic column lists, IN lists). Use table('USERS') for table names, bind values with
     * createNamedParameter()/setParameter() (arrays with ArrayParameterType), quote `system` and `rank` with
     * backticks yourself, and run the result through fetchAll()/fetchRow()/fetchValue()/execute() so the query
     * counter, DB time, row count and the debug log keep working.
     *
     * @throws Exception without a DBAL connection (no Symfony context)
     */
    public function qb(): QueryBuilder
    {
        if ($this->dbal === null) {
            throw new Exception('QueryBuilder needs the DBAL connection');
        }

        return $this->dbal->createQueryBuilder();
    }

    /**
     * Runs a SELECT QueryBuilder and returns all rows as associative arrays (like select()).
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchAll(QueryBuilder $qb): array
    {
        return $this->runQb($qb, 'select')->fetchAllAssociative();
    }

    /**
     * First row of a SELECT QueryBuilder, false when there is none (like selectSingle() without field).
     *
     * @return array<string, mixed>|false
     */
    public function fetchRow(QueryBuilder $qb): array|false
    {
        return $this->runQb($qb, 'select')->fetchAssociative();
    }

    /** First column of the first row of a SELECT QueryBuilder, false when there is no row. */
    public function fetchValue(QueryBuilder $qb): mixed
    {
        return $this->runQb($qb, 'select')->fetchOne();
    }

    /**
     * Runs an INSERT/UPDATE/DELETE QueryBuilder; returns the affected rows (also available through rowCount()).
     * An INSERT also sets lastInsertId().
     */
    public function execute(QueryBuilder $qb): int
    {
        $type = $this->getQueryType($qb->getSQL());
        if (!in_array($type, ['insert', 'update', 'delete'], true)) {
            throw new Exception('execute() takes an insert, update or delete QueryBuilder');
        }

        $this->runQb($qb, $type);
        if ($type === 'insert') {
            $this->lastInsertId = (int) $this->dbal->lastInsertId();
        }

        return $this->rowCount;
    }

    /**
     * UPDATE for callers that collect the columns to change at runtime (editor saves, settings, resets): builds
     * `UPDATE table SET col = :v, ... WHERE col = :w AND ...` with the QueryBuilder and runs it through execute().
     * Column names must be plain identifiers (letters, digits, underscore) - they come from the game's field lists,
     * never from request input - and every value is bound.
     *
     * @param string               $tableKey    game table key, see table()
     * @param array<string, mixed> $values      column => new value (bound)
     * @param array<string, mixed> $where       column => required value, ANDed (an empty list updates every row)
     * @param array<string, string> $expressions column => SQL expression assigned without binding (fixed code such as
     *                                          "''" or "`metal` + 5", never request input); assigned after $values
     * @param array<string, int|float|string> $increments column => signed amount (numeric strings keep their digits):
     *                                          `col = col + :amount`, or `col - :amount` for a negative amount; assigned last, bound
     * @return int affected rows; 0 and no query when there is nothing to set
     * @throws Exception on an invalid column name or SQL errors
     */
    public function updateRows(string $tableKey, array $values, array $where, array $expressions = [], array $increments = []): int
    {
        if ($values === [] && $expressions === [] && $increments === []) {
            return 0;
        }

        $qb = $this->qb()->update($this->table($tableKey));
        $n = 0;
        foreach ($values as $column => $value) {
            $qb->set($this->identifier((string) $column), ':v' . $n)->setParameter('v' . $n, $value);
            ++$n;
        }
        foreach ($expressions as $column => $expression) {
            $qb->set($this->identifier((string) $column), $expression);
        }
        foreach ($increments as $column => $amount) {
            $quoted = $this->identifier((string) $column);
            // numeric strings keep their digits (big counts must not pass through a float)
            $negative = is_string($amount) ? str_starts_with($amount, '-') : $amount < 0;
            $magnitude = is_string($amount) ? ltrim($amount, '-') : abs($amount);
            $qb->set($quoted, $quoted . ($negative ? ' - ' : ' + ') . ':v' . $n)->setParameter('v' . $n, $magnitude);
            ++$n;
        }
        foreach ($where as $column => $value) {
            $qb->andWhere($this->identifier((string) $column) . ' = :v' . $n)->setParameter('v' . $n, $value);
            ++$n;
        }

        return $this->execute($qb);
    }

    /** Backtick-quoted column name; anything but letters, digits and underscore is rejected. */
    private function identifier(string $column): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $column)) {
            throw new Exception('Invalid column name');
        }

        return '`' . $column . '`';
    }

    /**
     * Executes a QueryBuilder with the bookkeeping of _query(): counter, DB time, row count, debug log; errors
     * throw Exception with the query text like the wrapper does.
     *
     * @return \Doctrine\DBAL\Result|null the result of a select, null for statements
     */
    private function runQb(QueryBuilder $qb, string $type): ?\Doctrine\DBAL\Result
    {
        $start = microtime(true);
        $this->lastInsertId = 0;
        $this->rowCount = 0;
        try {
            if ($type === 'select') {
                $result = $qb->executeQuery();
                $count = (int) $result->rowCount();
            } else {
                $result = null;
                $count = (int) $qb->executeStatement();
            }
        } catch (\Doctrine\DBAL\Exception $e) {
            throw new Exception($e->getMessage() . "<br><br>Query-Code: " . $qb->getSQL(), (int) $e->getCode(), $e);
        }

        $this->state->time['db'] = ($this->state->time['db'] ?? 0.0) + microtime(true) - $start; // console runs start without the request timer
        $this->queryCounter++;
        $this->logQuery($qb->getSQL(), $qb->getParameters(), $type, $start);
        $this->rowCount = $count;

        return $result;
    }

    /**
     * Auto-increment id of the last insert() of this object (0 after any other query).
     */
    public function lastInsertId(): int
    {
        return $this->lastInsertId;
    }

    /**
     * Rows affected (or returned) by the last query of this object.
     */
    public function rowCount(): int
    {
        return $this->rowCount;
    }

    /**
     * Runs a prepared statement: resolves %%TABLE%% placeholders, quotes the reserved words `system` and `rank`, binds :limit/:offset as integers and everything else as strings (null stays null), measures the DB time, remembers insert id and row count.
     * @param string $type one of insert, select, update, delete, replace.
     * @param array<string, mixed> $params
     * @return bool|PDOStatement the statement for selects, true otherwise.
     * @throws Exception on SQL errors (message includes the query with parameters filled in).
     */
    private function _query(string $qry, array $params, string $type): bool|PDOStatement
    {
        $start = microtime(true);
        if (in_array($type, ["insert", "select", "update", "delete", "replace"]) === false) {
            throw new Exception("Unsupported Query Type");
        }

        $this->lastInsertId = 0;
        $this->rowCount = 0;
        $qry = preg_replace('/(?<!:)\bsystem\b(?!`)/', '`system`', $qry);
        $qry = preg_replace('/(?<!:)\brank\b(?!`)/', '`rank`', $qry);
        $qry    = str_replace($this->dbTableNames['keys'], $this->dbTableNames['names'], $qry);
        /** @var PDOStatement $stmt */
        $stmt   = $this->dbHandle->prepare($qry);
        if (isset($params[':limit']) || isset($params[':offset'])) {
            foreach ($params as $param => $value) {
                if ($param == ':limit' || $param == ':offset') {
                    $stmt->bindValue($param, (int) $value, PDO::PARAM_INT);
                } elseif (is_null($value)) {
                    $stmt->bindValue($param, null, PDO::PARAM_NULL);
                } else {
                    $stmt->bindValue($param, $value, PDO::PARAM_STR);
                }
            }
        }

        try {
            $success = (
                count($params) !== 0 && !isset($params[':limit']) && !isset($params[':offset'])
            ) ? $stmt->execute($params) : $stmt->execute();
            $this->state->time['db'] = ($this->state->time['db'] ?? 0.0) + microtime(true) - $start; // console runs start without the request timer
        } catch (PDOException $e) {
            $errorCode = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : 0;
            throw new Exception(
                $e->getMessage() . "<br><br>Query-Code: " . str_replace(
                    array_keys($params),
                    array_values($params),
                    $qry,
                ),
                $errorCode,
                $e,
            );
        }

        $this->queryCounter++;
        $this->logQuery($qry, $params, $type, $start);
        if (!$success) {
            return false;
        }

        if ($type === "insert") {
            $this->lastInsertId = $this->dbHandle->lastInsertId();
        }
        $this->rowCount = $stmt->rowCount();
        return ($type === "select") ? $stmt : true;
    }

    private function getQueryType(string $qry): string
    {
        if (!preg_match('!^(\S+)!', $qry, $match)) {
            throw new Exception("Invalid query $qry!");
        }

        if (!isset($match[1])) {
            throw new Exception("Invalid query $qry!");
        }

        return strtolower($match[1]);
    }

    /**
     * Runs a DELETE; other statement types are rejected. @throws Exception
     *
     * @param array<string, mixed> $params
     */
    public function delete(string $qry, array $params = []): bool
    {
        if (($type = $this->getQueryType($qry)) !== "delete") {
            throw new Exception("Incorrect Delete Query");
        }

        return $this->_query($qry, $params, $type);
    }

    /**
     * Runs a REPLACE INTO; other statement types are rejected. @throws Exception
     *
     * @param array<string, mixed> $params
     */
    public function replace(string $qry, array $params = []): bool
    {
        if (($type = $this->getQueryType($qry)) !== "replace") {
            throw new Exception("Incorrect Replace Query");
        }

        return $this->_query($qry, $params, $type);
    }

    /**
     * Runs an UPDATE; other statement types are rejected. @throws Exception
     *
     * @param array<string, mixed> $params
     */
    public function update(string $qry, array $params = []): bool
    {
        if (($type = $this->getQueryType($qry)) !== "update") {
            throw new Exception("Incorrect Update Query");
        }

        return $this->_query($qry, $params, $type);
    }

    /**
     * Runs an INSERT (the id is then available in lastInsertId()); other statement types are rejected. @throws Exception
     *
     * @param array<string, mixed> $params
     */
    public function insert(string $qry, array $params = []): bool
    {
        if (($type = $this->getQueryType($qry)) !== "insert") {
            throw new Exception("Incorrect Insert Query");
        }

        return $this->_query($qry, $params, $type);
    }

    /**
     * Runs a SELECT and returns all rows as associative arrays.
     *
     * @param array<string, mixed> $params
     * @return array<mixed>
     */
    public function select(string $qry, array $params = []): array
    {
        if (($type = $this->getQueryType($qry)) !== "select") {
            throw new Exception("Incorrect Select Query");
        }

        $stmt = $this->_query($qry, $params, $type);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * First row of a SELECT as associative array (false when none), or with $field only that column's value (the whole result, i.e. false, when no row).
     *
     * @param array<string, mixed> $params
     */
    public function selectSingle(string $qry, array $params = [], ?string $field = null): mixed
    {
        if (($type = $this->getQueryType($qry)) !== "select") {
            throw new Exception("Incorrect Select Query");
        }

        $stmt = $this->_query($qry, $params, $type);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        return (empty($field) || (empty($res))) ? $res : $res[$field];
    }

    /** whether startTransaction() opened a transaction that is not committed or rolled back yet */
    private bool $inTransaction = false;

    /**
     * Starts a transaction (nested transactions are not supported).
     */
    public function startTransaction(): void
    {
        $this->query("START TRANSACTION;");
        $this->inTransaction = true;
    }

    /** True between startTransaction() and commit()/rollback() (transactions started with raw SQL are not tracked). */
    public function inTransaction(): bool
    {
        return $this->inTransaction;
    }

    /**
     * Commits the open transaction.
     */
    public function commit(): void
    {
        $this->query("COMMIT;");
        $this->inTransaction = false;
    }

    /**
     * Rolls the open transaction back.
     */
    public function rollback(): void
    {
        $this->query("ROLLBACK");
        $this->inTransaction = false;
    }

    /**
     * Runs $fn in a transaction (commit afterwards) and repeats the whole closure when MySQL picked the transaction as a
     * deadlock victim (1213) or the lock wait timed out (1205): rollback, short jittered back-off, next attempt. Any other
     * exception rolls back and is rethrown at once; so is the retryable one after the last attempt.
     *
     * The closure runs again from the start, so it may only touch the database (no mails, output, files, session writes) and
     * must reset in-memory state it changes itself. Nested transactions are not supported (like startTransaction()).
     *
     * @template T
     * @param callable(int): T $fn receives the attempt number (1 = first run)
     * @param int $attempts maximum number of runs, at least 1
     * @param int $backoffMs base of the pause before a retry: attempt number times this, randomised by +-50% (0 = no pause)
     * @return T the return value of $fn
     * @throws \Throwable what $fn threw
     */
    public function transactional(callable $fn, int $attempts = 3, int $backoffMs = 25): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            $this->startTransaction();
            try {
                $result = $fn($attempt);
                $this->commit();

                return $result;
            } catch (\Throwable $e) {
                try {
                    $this->rollback();
                } catch (\Throwable) {
                    // the connection may be gone; the original error is the one that matters
                }
                if ($attempt >= $attempts || !self::isRetryable($e)) {
                    throw $e;
                }
                if ($backoffMs > 0) {
                    usleep(random_int($backoffMs * 500, $backoffMs * 1500) * $attempt);
                }
            }
        }
    }

    /**
     * MySQL error number behind an exception (own Exception with the PDO error as code/previous, Doctrine exceptions
     * and PDOException itself), 0 when there is none.
     */
    public static function errorNumber(\Throwable $e): int
    {
        for ($t = $e; $t !== null; $t = $t->getPrevious()) {
            if ($t instanceof PDOException && isset($t->errorInfo[1])) {
                return (int) $t->errorInfo[1];
            }
            if ($t instanceof \Doctrine\DBAL\Exception\DeadlockException) {
                return self::ERROR_DEADLOCK;
            }
            if ($t instanceof \Doctrine\DBAL\Exception\LockWaitTimeoutException) {
                return self::ERROR_LOCK_WAIT_TIMEOUT;
            }
        }

        return 0;
    }

    /** Is it a deadlock (1213) or lock wait timeout (1205), i.e. will the same transaction probably succeed when repeated? */
    public static function isRetryable(\Throwable $e): bool
    {
        $number = self::errorNumber($e);

        return $number === self::ERROR_DEADLOCK || $number === self::ERROR_LOCK_WAIT_TIMEOUT;
    }

    /**
     * Lists column values of a table
     * with desired key from the
     * database as an array.
     *
     * @param  string       $table
     * @param  string       $column
     * @param  string       $key
     * @return array<mixed>
     */
    public function lists(string $table, string $column, ?string $key = null): array
    {
        $selects = implode(', ', is_null($key) ? [$column] : [$column, $key]);
        $qry = "SELECT {$selects} FROM %%{$table}%%;";
        $stmt = $this->_query($qry, [], 'select');
        $results = [];
        if (is_null($key)) {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $results[] = $row[$column];
            }
        } else {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $results[$row[$key]] = $row[$column];
            }
        }

        return $results;
    }

    /**
     * Executes a raw statement without result (no placeholders, no parameters).
     */
    public function query(string $qry): void
    {
        $this->lastInsertId = 0;
        $this->rowCount = 0;
        $start = microtime(true);
        $this->rowCount = $this->dbHandle->exec($qry);
        $this->queryCounter++;
        $this->logQuery($qry, [], 'query', $start);
    }

    /**
     * Runs a raw statement with %%TABLE%% replacement and no parameters; returns the rows for SELECT/SHOW, true otherwise.
     *
     * @return array<mixed>
     */
    public function nativeQuery(string $qry): bool|array
    {
        $this->lastInsertId = 0;
        $this->rowCount = 0;
        $qry    = str_replace($this->dbTableNames['keys'], $this->dbTableNames['names'], $qry);
        $start = microtime(true);
        /** @var PDOStatement $stmt */
        $stmt   = $this->dbHandle->query($qry);
        $this->rowCount = $stmt->rowCount();
        $this->queryCounter++;
        $this->logQuery($qry, [], 'native', $start);
        return in_array($this->getQueryType($qry), ['select', 'show']) ? $stmt->fetchAll(PDO::FETCH_ASSOC) : true;
    }

    /**
     * debug mode only: query, parameters and duration for the profiler panel (GameDatabaseCollector)
     *
     * @param array<string, mixed> $params
     */
    private function logQuery(string $qry, array $params, string $type, float $start): void
    {
        if ($this->debug) {
            $this->state->queryLog ??= [];
            $this->state->queryLog[] = ['sql' => $qry, 'params' => $params, 'type' => $type, 'ms' => (microtime(true) - $start) * 1000];
        }
    }

    public function getQueryCounter(): int
    {
        return $this->queryCounter;
    }

    /**
     * Unix timestamp as MySQL datetime string (server time zone).
     */
    public function formatDate(int $time): string
    {
        return date('Y-m-d H:i:s', floor($time));
    }

    /**
     * Quotes a string literal for use in a raw query.
     */
    public function quote(string $str): string
    {
        return $this->dbHandle->quote($str);
    }

    /**
     * Debug helper: substitutes the parameters into the query text, writes it to the error log and returns it.
     *
     * @param array<string, mixed> $params
     */
    public function log(string $qry, array $params = []): string
    {
        $qry    = str_replace($this->dbTableNames['keys'], $this->dbTableNames['names'], $qry);
        $parameters = [];
        foreach ($params as $key => $value) {
            $parameters['keys'][]       = $key;
            $parameters['values'][]     = str_starts_with($value, "%") ? "'$value'" : "$value";
        }
        $qry = str_replace($parameters['keys'], $parameters['values'], $qry);
        error_log($qry);
        return $qry;
    }

    public function getClientVersion(): mixed
    {
        return $this->dbHandle->getAttribute(PDO::ATTR_CLIENT_VERSION);
    }
}
