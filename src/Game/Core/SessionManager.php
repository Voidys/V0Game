<?php

namespace App\Game\Core;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Starts, wakes and creates the game session (formerly the static side of Session). The current
 * Session lives in the game state ($state->session), so every SessionManager of a request sees
 * the same one; Session objects get the services attached after create/restore.
 */
class SessionManager
{
    public function __construct(
        protected Database $db,
        protected ConfigRegistry $configs,
        protected Singleton $state,
    ) {}

    /** The universe settings of the request (for the engagement login hook of the session). */
    public function configs(): ConfigRegistry
    {
        return $this->configs;
    }

    /** idle time in seconds after which a game session expires (admin setting session_lifetime) */
    public function idleLifetime(): int
    {
        return \App\Security\SessionPolicy::idleSeconds($this->configs->get(ROOT_UNI)->session_lifetime ?? null);
    }

    /** @internal for Session: request data and game state */
    public function gameState(): Singleton
    {
        return $this->state;
    }

    /**
     * The Symfony session of the current request (storage, cookie and lifetime: framework.session,
     * handler: SESSION_HANDLER_DSN). The game's Session object lives in its "obj" attribute.
     */
    public function symfonySession(): SessionInterface
    {
        $request = $this->state->request ?? null;
        if (!$request instanceof Request || !$request->hasSession()) {
            throw new \LogicException('No session available (console run?).');
        }

        return $request->getSession();
    }

    /**
     * Id of the Symfony session.
     */
    public function id(): string
    {
        return $this->symfonySession()->getId();
    }

    /**
     * Cookie name of the Symfony session.
     */
    public function name(): string
    {
        return $this->symfonySession()->getName();
    }

    /** an attribute of the Symfony session, null without a request session */
    public function attribute(string $name): mixed
    {
        $request = $this->state->request ?? null;

        return $request instanceof Request && $request->hasSession() ? $request->getSession()->get($name) : null;
    }

    /**
     * The client's IP address as stored in sessions, registrations and multi-account checks:
     * Request::getClientIp(), i.e. the TCP peer, or the forwarded address only when the peer is in
     * framework.trusted_proxies (env SYMFONY_TRUSTED_PROXIES). Client-IP/X-Forwarded-For headers of
     * untrusted peers are never read. Always a valid IPv4/IPv6 string, "UNKNOWN" without a request.
     */
    public function getClientIp(): string
    {
        $request = $this->state->request ?? null;
        $ip = $request instanceof Request ? $request->getClientIp() : null;

        return is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : 'UNKNOWN';
    }

    /**
     * Gives the current session a new id and keeps its data (session fixation defence when privileges
     * change, e.g. admin elevation). The game's session row ({prefix}session) of the old id is removed;
     * Session::save() writes the row of the new id at the end of the request.
     */
    public function regenerateId(): void
    {
        $this->db->delete('DELETE FROM %%SESSION%% WHERE sessionID = :sessionId;', [':sessionId' => $this->id()]);
        $this->symfonySession()->migrate(true);
    }

    /**
     * Ends all game sessions of an account (password change/reset): the game accepts a session only
     * while its row in {prefix}session exists (Session::isValidSession()).
     *
     * @param string|null $exceptSessionId session id to keep (the current one), null to end all
     */
    public function revokeAccountSessions(int $accountId, ?string $exceptSessionId = null): void
    {
        $this->db->delete(
            'DELETE FROM %%SESSION%% WHERE accountID = :accountId AND sessionID != :keep;',
            [':accountId' => $accountId, ':keep' => $exceptSessionId ?? ''],
        );
    }

    /**
     * Create an empty session
     *
     * @return Session
     */
    public function create()
    {
        if (!$this->existsActiveSession()) {
            $this->symfonySession()->start();
            $session = (new Session())->attach($this, $this->db);
            $this->state->session = $session;
        }

        return $this->state->session;
    }

    /**
     * Wake an active session
     *
     * @return Session
     */
    public function load()
    {
        if (!$this->existsActiveSession()) {
            $symfonySession = $this->symfonySession();
            $symfonySession->start();
            if ($symfonySession->has('obj')) {
                $stored = $symfonySession->get('obj');
                $session = is_array($stored) ? Session::fromArray($stored) : null;
                if ($session === null) {
                    $this->create();

                    return $this->state->session;
                }
                $session->attach($this, $this->db);
                $this->state->session = $session;
            } else {
                $this->create();
            }
        }

        return $this->state->session;
    }

    /**
     * Check if an active session exists
     *
     * @return bool
     */
    public function existsActiveSession()
    {
        return isset($this->state->session);
    }
}
