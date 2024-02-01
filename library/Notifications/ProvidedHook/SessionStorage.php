<?php

namespace Icinga\Module\Notifications\ProvidedHook;

use Icinga\Application\Hook\AuthenticationHook;
use Icinga\Application\Logger;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Model\Daemon\BrowserSession;
use Icinga\Module\Notifications\Model\Daemon\Connection;
use Icinga\User;
use Icinga\Web\Session;
use ipl\Stdlib\Filter;
use PDOException;

class SessionStorage extends AuthenticationHook
{
    /**
     * @var Session\Session $session
     */
    private $session;

    /**
     * @var \ipl\Sql\Connection $database
     */
    private $database;

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
            // calculate browser identifier
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?: null;
            $browserId = Connection::calculateBrowserId($userAgent, $user->getUsername()) ?: 'default';

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
                        [
                            'php_session_id = ?' => $this->session->getId()
                        ]
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
                ->filter(Filter::equal('username', $user->getUsername()))
                ->filter(Filter::equal('browser_id', $browserId))
                ->execute();
            /** @var BrowserSession $session */
            foreach ($userSessions as $session) {
                $this->database->delete(
                    'browser_session',
                    [
                        'php_session_id = ?' => $session->php_session_id,
                        'username = ?' => trim($user->getUsername()),
                        'browser_id = ?' => $browserId
                    ]
                );
            }

            // add current session to the db
            $this->database->beginTransaction();
            try {
                $this->database->insert(
                    'browser_session',
                    [
                        'php_session_id' => $this->session->getId(),
                        'username' => trim($user->getUsername()),
                        'browser_id' => $browserId
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
                "onLogin triggered for user " . $user->getUsername() . " and browser session " . $this->session->getId()
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

            // calculate browser identifier
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?: null;
            $browserId = Connection::calculateBrowserId($userAgent, $user->getUsername()) ?: 'default';

            $this->database->beginTransaction();
            try {
                $this->database->delete(
                    'browser_session',
                    [
                        'php_session_id = ?' => $this->session->getId(),
                        'username = ?' => trim($user->getUsername()),
                        'browser_id = ?' => $browserId
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
                "onLogout triggered for user " . $user->getUsername() . " and browser session " .
                $this->session->getId()
            );
        }
    }
}
