<?php

/* Icinga Notifications Web | (c) 2024 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\ProvidedHook;

use DateTime;
use Icinga\Application\Hook\AuthenticationHook;
use Icinga\Application\Logger;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Model\BrowserSession;
use Icinga\User;
use Icinga\Web\Session;
use Icinga\Web\UserAgent;
use ipl\Sql\Connection;
use ipl\Stdlib\Filter;
use PDOException;

class SessionStorage extends AuthenticationHook
{
    /** @var Session\Session Session object */
    protected $session;

    /** @var Connection Database object */
    protected $database;

    public function __construct()
    {
        Logger::info('SessionStorage initialized');
        $this->session = Session::getSession();
        $this->database = Database::get();
    }

    public function onLogin(User $user): void
    {
        Logger::info('running onLogin hook');

        if ($this->session->exists()) {
            // user successfully authenticated
            $rawUserAgent = (new UserAgent())->getAgent();
            if ($rawUserAgent) {
                $userAgent = trim($rawUserAgent);
            } else {
                $userAgent = 'default';
            }

            // check if session with this identifier already exists (zombie session)
            $zombieSession = BrowserSession::on(Database::get())
                ->filter(Filter::equal('php_session_id', $this->session->getId()))
                ->first();

            if ($zombieSession !== null) {
                // session with same id exists
                // cleaning up the old session from the database as this one just got authenticated
                $this->database->beginTransaction();
                try {
                    $this->database->delete(
                        'browser_session',
                        ['php_session_id = ?' => $this->session->getId()]
                    );
                    $this->database->commitTransaction();
                } catch (PDOException $e) {
                    Logger::error(
                        "Failed deleting browser session from table 'browser_session': \n\t" . $e->getMessage()
                    );
                    $this->database->rollBackTransaction();
                }
            }

            // cleanup existing sessions from this user (only for the current browser)
            $userSessions = BrowserSession::on(Database::get())
                ->filter(Filter::equal('username', trim($user->getUsername())))
                ->filter(Filter::equal('user_agent', $userAgent))
                ->execute();
            /** @var BrowserSession $session */
            foreach ($userSessions as $session) {
                $this->database->delete(
                    'browser_session',
                    [
                        'php_session_id = ?' => $session->php_session_id,
                        'username = ?'       => trim($user->getUsername()),
                        'user_agent = ?'     => $userAgent
                    ]
                );
            }

            // add current session to the db
            $this->database->beginTransaction();
            try {
                $this->database->insert(
                    'browser_session',
                    [
                        'php_session_id'   => $this->session->getId(),
                        'username'         => trim($user->getUsername()),
                        'user_agent'       => $userAgent,
                        'authenticated_at' => (new DateTime())->format('Uv')
                    ]
                );
                $this->database->commitTransaction();
            } catch (PDOException $e) {
                Logger::error(
                    "Failed adding browser session to table 'browser_session': \n\t" . $e->getMessage()
                );
                $this->database->rollBackTransaction();
            }

            Logger::debug(
                "onLogin triggered for user "
                . trim($user->getUsername())
                . " and browser session "
                . $this->session->getId()
            );
        }
    }

    public function onLogout(User $user): void
    {
        if ($this->session->exists()) {
            // user disconnected, removing the session from the database (invalidating it)
            if ($this->database->ping() === false) {
                $this->database->connect();
            }

            $rawUserAgent = (new UserAgent())->getAgent();
            if ($rawUserAgent) {
                $userAgent = trim($rawUserAgent);
            } else {
                $userAgent = 'default';
            }

            $this->database->beginTransaction();
            try {
                $this->database->delete(
                    'browser_session',
                    [
                        'php_session_id = ?' => $this->session->getId(),
                        'username = ?'       => trim($user->getUsername()),
                        'user_agent = ?'     => $userAgent
                    ]
                );
                $this->database->commitTransaction();
            } catch (PDOException $e) {
                Logger::error(
                    "Failed deleting browser session from table 'browser_session': \n\t" . $e->getMessage()
                );
                $this->database->rollBackTransaction();
            }

            Logger::debug(
                "onLogout triggered for user "
                . trim($user->getUsername())
                . " and browser session "
                . $this->session->getId()
            );
        }
    }
}
