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

/**
 * The game's login session of a request: user id, account id, selected planet, universe and bookkeeping values,
 * held in the Symfony session (attribute 'obj') and mirrored in the `session` table, which decides validity.
 * Created and restored by SessionManager. save() throttles database writes (at most every 30 s while nothing
 * changed) and keeps users.onlinetime/user_lastip current; it also detects multi-account IP overlaps.
 */
class Session
{
    /**
     * Seconds during which an unchanged session (same id, player, account, IP, universe) is not written
     * to the database again: session row, users/accounts.onlinetime and the multi-IP check run at most
     * this often. All consumers of onlinetime work in minutes or more.
     */
    private const DB_WRITE_INTERVAL = 30;

    private $data = null;

    /** services, attached by SessionManager after create/restore (not part of the stored data) */
    private ?Database $db = null;
    private ?SessionManager $manager = null;
    /** set by delete(): a destroyed session is not written back */
    private bool $destroyed = false;

    /** @internal called by SessionManager */
    public function attach(SessionManager $manager, Database $db): static
    {
        $this->manager = $manager;
        $this->db = $db;

        return $this;
    }

    /**
     * The session data as stored in the Symfony session under 'obj' (a plain array, no serialized object).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data ?? [];
    }

    /**
     * Restores a session from the array written by toArray(); services are attached afterwards by SessionManager.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): static
    {
        $session = new static();
        $session->data = $data;

        return $session;
    }

    /**
     * Sets a session value (kept for the whole login).
     */
    public function __set($name, $value)
    {
        $this->data[$name]  = $value;
    }

    /**
     * Reads a session value, null when unset.
     */
    public function __get($name)
    {
        if (isset($this->data[$name])) {
            return $this->data[$name];
        } else {
            return null;
        }
    }

    /**
     * True if the session value is set.
     */
    public function __isset($name)
    {
        return isset($this->data[$name]);
    }

    /** @param bool $force write to the database even if it was written a moment ago (e.g. the session row is gone) */
    public function save(bool $force = false)
    {
        // do not save an empty or destroyed session
        if ($this->destroyed || $this->manager === null) {
            return;
        }
        $sessionId = $this->manager->id();
        if (empty($sessionId)) {
            return;
        }

        // sessions require an valid user.
        if ($this->manager->gameState()->runtime->mode != 'INSTALL' && $this->manager->gameState()->runtime->mode != 'UPGRADE') {
            if (empty($this->data['userId']) && empty($this->data['accountId'])) {
                $this->delete();
            }
        }

        $userIpAddress = $this->manager->getClientIp();

        if (!$this->isRaportView()) {
            if (empty($this->data['userId'])) {
                $this->data['userId'] = 0;
            }
            if (empty($this->data['accountId'])) {
                $this->data['accountId'] = 0;
            }

            $timestamp = $this->manager->gameState()->runtime->timestamp;
            $syncKey = implode('|', [$sessionId, $this->data['userId'], $this->data['accountId'], $userIpAddress, $this->data['universe'] ?? '']);
            $lastActivity = (int) ($this->data['lastActivity'] ?? 0);
            if (
                !$force
                && !$this->destroyed
                && ($this->data['syncKey'] ?? null) === $syncKey
                && $lastActivity <= $timestamp
                && $lastActivity > $timestamp - self::DB_WRITE_INTERVAL
            ) {
                // nothing changed and the database was written a moment ago: only keep the session data
                // (selected planet etc.) in the Symfony session
                $this->manager->symfonySession()->set('obj', $this->toArray());
                return;
            }

            $created = $this->writeSessionRow($userIpAddress);
            $this->touchOwner($created, $userIpAddress);
            $this->checkMultiIP();

            $this->data['syncKey']       = $syncKey;
            $this->data['lastActivity']  = $this->manager->gameState()->runtime->timestamp;
            $this->data['sessionId']     = $this->manager->id();
            $this->data['userIpAddress'] = $userIpAddress;


            $this->manager->symfonySession()->set('obj', $this->toArray());
        }
    }

    /**
     * The public combat report view (route `game_raport_show`, `/report/<id>`, while in game): it works without a
     * session and must not create or refresh one.
     */
    private function isRaportView(): bool
    {
        // the public report view and the chat poll (a background request) are not player activity: nothing is written
        return in_array($this->manager->gameState()->request?->attributes->get('_route'), ['game_raport_show', 'game_chat_poll'], true)
            && $this->manager->gameState()->runtime->mode === 'INGAME';
    }

    /**
     * Writes the session row (REPLACE: session id, user, account, last activity, client ip), keeping the creation time of
     * an existing row.
     *
     * @return int|string creation time of the session
     */
    private function writeSessionRow(string $userIpAddress): int|string
    {
        $sql    = 'REPLACE INTO %%SESSION%% SET
            sessionID	= :sessionId,
            userID		= :userId,
            accountID	= :accountId,
            lastonline	= :lastActivity,
            created		= :created,
            userIP		= :userAddress;';

        $db     = $this->db;

        $sql_created = 'SELECT created FROM %%SESSION%% 
            WHERE sessionID = :sessionId 
            AND userID = :userId 
            AND accountID = :accountId;';

        $created = $db->selectSingle($sql_created, [
            ':sessionId'    => $this->manager->id(),
            ':userId'       => $this->data['userId'],
            ':accountId'    => $this->data['accountId'],
        ], 'created');

        if (empty($created)) {
            $created = time();
        }

        $db->replace($sql, [
            ':sessionId'    => $this->manager->id(),
            ':userId'       => $this->data['userId'],
            ':accountId'    => $this->data['accountId'],
            ':lastActivity' => $this->manager->gameState()->runtime->timestamp,
            ':created'  => $created,
            ':userAddress'  => $userIpAddress,
        ]);

        return $created;
    }

    /**
     * Marks the owner of the session online (user: online time and ip, account lobby: online time), removes the other
     * sessions of the owner when multiple sessions are prevented. The session lifetime is sliding (idle time, checked in
     * GameBootstrap::syncGameSession() and by the cleaner cronjob), never time since login. A logged-in user session also records the request path.
     *
     * @param int|string $created creation time of the session row
     */
    private function touchOwner(int|string $created, string $userIpAddress): void
    {
        $db = $this->db;

        if ($this->data['userId'] != 0) {
            $this->noteReturn((int) $this->data['userId']);
            $sql = 'UPDATE %%USERS%% SET
            onlinetime	= :lastActivity,
            user_lastip = :userAddress
            WHERE id = :userId;';

            $db->update($sql, [
                ':userAddress'   => $userIpAddress,
                ':lastActivity'  => $this->manager->gameState()->runtime->timestamp,
                ':userId'        => $this->data['userId'],
            ]);

            // Remove multisessions
            if (PREVENT_MULTISESSIONS == true) {
                $sql    = 'DELETE FROM %%SESSION%% WHERE (userID = :userId AND sessionID != :sessionId);';
                $db->delete($sql, [
                    ':userId'   => $this->data['userId'],
                    ':sessionId'    => $this->manager->id(),
                ]);
            }

            $this->data['requestPath']   = $this->getRequestPath();
        } else {
            $sql = 'UPDATE %%ACCOUNTS%% SET
            onlinetime	= :lastActivity
            WHERE id = :accountId;';

            $db->update($sql, [
                ':lastActivity'     => $this->manager->gameState()->runtime->timestamp,
                ':accountId'        => $this->data['accountId'],
            ]);

            // Remove multisessions
            if (PREVENT_MULTISESSIONS == true) {
                $sql    = 'DELETE FROM %%SESSION%% WHERE (accountID = :accountId AND sessionID != :sessionId);';
                $db->delete($sql, [
                    ':accountId'    => $this->data['accountId'],
                    ':sessionId'    => $this->manager->id(),
                ]);
            }
        }
    }

    /**
     * Once per session and player, before onlinetime is refreshed: lets the returning-player welcome see how long the player
     * was away (a failure of the optional feature never breaks the login).
     */
    private function noteReturn(int $userId): void
    {
        if (($this->data['returnChecked'] ?? 0) === $userId) {
            return;
        }
        $this->data['returnChecked'] = $userId;
        try {
            $state = $this->manager->gameState();
            (new \App\Game\Engagement\Returning\ReturningPlayers($this->db, $this->manager->configs(), $state))->onLogin((int) ($this->data['universe'] ?? 0), $userId, $state->runtime->timestamp);
        } catch (\Throwable $e) {
            \error_log('returning-player hook: ' . $e->getMessage());
        }
    }

    /**
     * Logs out: deletes the session row, clears the cookie, invalidates the Symfony session; a deleted session is not written back.
     */
    public function delete()
    {
        $sql    = 'DELETE FROM %%SESSION%% WHERE sessionID = :sessionId;';
        $db     = $this->db;

        $db->delete($sql, [':sessionId' => $this->manager->id()]);
        (new HTTP($this->manager->gameState()))->sendCookie("2Moons", "", time() - 3600);
        $this->manager->symfonySession()->invalidate();
        $this->destroyed = true;
    }

    /**
     * Drops the session row and keeps this object from being written back, without touching cookies or the Symfony
     * session (an idle session that a "stay logged in" cookie replaces on the next request).
     */
    public function discard(): void
    {
        $this->db->delete('DELETE FROM %%SESSION%% WHERE sessionID = :sessionId;', [':sessionId' => $this->manager->id()]);
        $this->destroyed = true;
    }

    /**
     * True if the session may be used: data present, last activity within the idle limit (setting session_lifetime) and its row still
     * exists in `session` (rows are removed by logout, password changes and the cleaner cronjob).
     */
    public function isValidSession()
    {
        if (
            $this->manager->gameState()->request?->attributes->get('_route') === 'game_raport_show'
            && $this->manager->gameState()->runtime->mode === 'INGAME'
        ) {
            $this->data['lastActivity'] = time();
        } else {
            if (!$this->manager->symfonySession()->has('obj')) {
                return false;
            }
        }

        if ($this->data['lastActivity'] < $this->manager->gameState()->runtime->timestamp - $this->manager->idleLifetime()) {
            return false;
        }

        $sql = 'SELECT COUNT(*) as record FROM %%SESSION%% WHERE sessionID = :sessionId;';
        $db     = $this->db;

        $sessionCount = $db->selectSingle($sql, [':sessionId' => $this->manager->id()], 'record');

        if ($sessionCount == 0) {
            return false;
        }

        return true;
    }

    /**
     * Switches the active planet when the request carries `cp=<planet id>` and the planet belongs to the player.
     */
    public function selectActivePlanet()
    {
        $httpData   = (new RequestInput($this->manager->gameState()))->int('cp');

        if (!empty($httpData)) {
            $sql    = 'SELECT id FROM %%PLANETS%% WHERE id = :planetId AND id_owner = :userId;';

            $db = $this->db;
            $planetId   = $db->selectSingle($sql, [
                ':userId'   => $this->data['userId'],
                ':planetId' => $httpData,
            ], 'id');

            if (!empty($planetId)) {
                $this->data['planetId'] = $planetId;
            }
        }
    }

    private function getRequestPath()
    {
        return $this->manager->gameState()->runtime->httpRoot . (!empty($this->manager->gameState()->requestServer['QUERY_STRING']) ? '?' . $this->manager->gameState()->requestServer['QUERY_STRING'] : '');
    }

    /**
     * Multi-account detection: when other players of the universe used the same IP in the last 24 h, records the
     * group in `multi` / `multi_to_users` (creating or extending an existing entry for this IP or for the same
     * set of players) with allowed = 0, so admins can review it.
     */
    private function checkMultiIP()
    {

        $db     = $this->db;
        $userIpAddress = $this->manager->getClientIp();
        if (isset($this->data['userId']) && $this->data['userId'] != 0 && isset($this->data['universe'])) {
            // Check fpr MultiIP
            $sql = "SELECT `id` FROM %%USERS%%
                WHERE `onlinetime` > :lastActivity
                AND `user_lastip` = :userAddress
                AND `id` != :userId
                AND `universe` = :universe;";
            $multis = $db->select($sql, [
                ':userAddress'  => $userIpAddress,
                ':lastActivity' => $this->manager->gameState()->runtime->timestamp - TIME_24_HOURS,
                ':userId'       => $this->data['userId'],
                ':universe'     => $this->data['universe'],
            ]);

            if (!empty($multis)) {
                $multis[] = ['id' => $this->data['userId']];

                $sql = "SELECT * FROM %%MULTI%%
                    WHERE `multi_ip` = :userAddress
                    AND `universe` = :universe;";
                $multiEntry = $db->selectSingle($sql, [
                    ':userAddress'  => $userIpAddress,
                    ':universe'     => $this->data['universe'],
                ]);

                if (empty($multiEntry)) {
                    $multiEntry = $this->findGroupOfUsers($multis);
                }

                if (empty($multiEntry)) {
                    $this->createMultiGroup($multis, $userIpAddress);
                } else {
                    $this->refreshMultiGroup($multiEntry, $multis, $userIpAddress);
                }
            }
        }
    }

    /**
     * The multi group that contains all of the given users (the one with the fewest members first), or false.
     *
     * @param list<array{id: int|string}> $multis the users that share the ip, including this session's user
     * @return array<string, mixed>|false the `multi` row
     */
    private function findGroupOfUsers(array $multis): array|false
    {
        $db = $this->db;
        // the multi group that contains all of these users (fewest members first)
        $multiID = $db->fetchValue(
            $db->qb()
                ->select('mu.`multiID`')
                ->addSelect('count(DISTINCT muc.`userID`) AS anz')
                ->from($db->table('MULTI_TO_USERS'), 'mu')
                ->join('mu', $db->table('MULTI_TO_USERS'), 'muc', 'muc.`multiID` = mu.`multiID`')
                ->where('mu.`userID` IN (:userIds)')
                ->groupBy('mu.`multiID`')
                ->having('count(DISTINCT mu.`userID`) >= :usersSize')
                ->orderBy('anz', 'ASC')
                ->setParameter('userIds', array_map('intval', array_column($multis, 'id')), \Doctrine\DBAL\ArrayParameterType::INTEGER)
                ->setParameter('usersSize', count($multis)),
        );

        $sql = "SELECT * FROM %%MULTI%%
            WHERE `multiID` = :multiID;";
        return $db->selectSingle($sql, [
            ':multiID'  => $multiID,
        ]);
    }

    /**
     * Creates a new multi group for the ip (not allowed) with all the given users.
     *
     * @param list<array{id: int|string}> $multis the users that share the ip, including this session's user
     */
    private function createMultiGroup(array $multis, string $userIpAddress): void
    {
        $db = $this->db;
        $sql = "INSERT INTO %%MULTI%% SET
            `multi_ip` = :userAddress,
            `lastActivity` = :lastActivity,
            `universe` = :universe,
            `allowed` = 0;";
        $db->insert($sql, [
            ':userAddress' => $userIpAddress,
            ':lastActivity' => $this->manager->gameState()->runtime->timestamp,
            ':universe' => $this->data['universe'],
        ]);
        $multiID = $db->lastInsertId();

        $sql = "INSERT INTO %%MULTI_TO_USERS%% SET
            `multiID` = :multiID,
            `userID` = :userID,
            `lastActivity` = :lastActivity,
            `allowed` = 0;";

        foreach ($multis as $multi) {
            $db->insert($sql, [
                ':multiID' => $multiID,
                ':userID' => $multi['id'],
                ':lastActivity' => $this->manager->gameState()->runtime->timestamp,
            ]);
        }
    }

    /**
     * An existing multi group is seen again: its ip and activity time are refreshed, missing users are added, and the
     * activity time of this session's own entry is refreshed.
     *
     * @param array<string, mixed> $multiEntry the `multi` row
     * @param list<array{id: int|string}> $multis the users that share the ip, including this session's user
     */
    private function refreshMultiGroup(array $multiEntry, array $multis, string $userIpAddress): void
    {
        $db = $this->db;
        $multiID = $multiEntry['multiID'];
        $sql = "UPDATE %%MULTI%% SET
            `multi_ip` = :userAddress,
            `lastActivity` = :lastActivity
            WHERE `multiID` = :multiID;";
        $db->update($sql, [
            ':userAddress'  => $userIpAddress,
            ':lastActivity' => $this->manager->gameState()->runtime->timestamp,
            ':multiID' => $multiID,
        ]);

        $sqlSelect = "SELECT * FROM %%MULTI_TO_USERS%%
            WHERE `multiID` = :multiID AND `userID` = :userID;";
        $sqlInsert = "INSERT INTO %%MULTI_TO_USERS%% SET
            `multiID` = :multiID,
            `userID` = :userID,
            `lastActivity` = :lastActivity,
            `allowed` = 0;";
        $sqlUpdate = "UPDATE %%MULTI_TO_USERS%% SET
            `lastActivity` = :lastActivity
            WHERE `multiID` = :multiID AND `userID` = :userID;";

        foreach ($multis as $multi) {
            $result = $db->selectSingle($sqlSelect, [
                ':multiID' => $multiID,
                ':userID' => $multi['id'],
            ]);

            if (empty($result)) {
                $db->insert($sqlInsert, [
                    ':multiID' => $multiID,
                    ':userID' => $multi['id'],
                    ':lastActivity' => $this->manager->gameState()->runtime->timestamp,
                ]);
            } elseif ($multi['id'] == $this->data['userId']) {
                $db->update($sqlUpdate, [
                    ':lastActivity' => $this->manager->gameState()->runtime->timestamp,
                    ':multiID' => $multiID,
                    ':userID' => $multi['id'],
                ]);
            }
        }
    }
}
