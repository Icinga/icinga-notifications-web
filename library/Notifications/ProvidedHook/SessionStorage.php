<?php

namespace Icinga\Module\Notifications\ProvidedHook;

use Icinga\Application\Hook\AuthenticationHook;
use Icinga\Application\Logger;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Model\Daemon\Connection;
use Icinga\Module\Notifications\Model\Daemon\Session;
use Icinga\User;
use ipl\Stdlib\Filter;
use PDOException;

class SessionStorage extends AuthenticationHook
{
    /**
     * @var \Icinga\Web\Session\Session $session
     */
    private $session;

    /**
     * @var \ipl\Sql\Connection $database
     */
    private $database;

    public function __construct()
    {
        Logger::info('SessionStorage initialized');
        $this->session = \Icinga\Web\Session::getSession();
        $this->database = Database::get();
    }

    public function onLogin(User $user): void
    {
        Logger::info('running onLogin hook');

        if ($this->session->exists()) {
            // user successfully authenticated
            // calculate device identifier
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?: null;
            $deviceId = Connection::calculateDeviceId($userAgent, $user->getUsername()) ?: 'default';

            // check if session with this identifier already exists (zombie session)
            $zombieSession = Session::on(Database::get())
                ->filter(Filter::equal('id', $this->session->getId()))
                ->first();

            if ($zombieSession !== null) {
                // session with same id exists
                // cleaning up the old session from the database as this one just got authenticated
                $this->database->beginTransaction();
                try {
                    $this->database->delete(
                        'session',
                        [
                            'id = ?' => $this->session->getId()
                        ]
                    );
                    $this->database->commitTransaction();
                } catch (PDOException $e) {
                    Logger::error("Failed deleting session from table 'session': \n\t" . $e->getMessage());
                    $this->database->rollBackTransaction();
                }
            }

            // cleanup existing sessions from this user (only for the current device)
            $userSessions = Session::on(Database::get())
                ->filter(Filter::equal('username', $user->getUsername()))
                ->filter(Filter::equal('device_id', $deviceId))
                ->execute();
            /** @var Session $session */
            foreach ($userSessions as $session) {
                $this->database->delete(
                    'session',
                    [
                        'id = ?' => $session->id,
                        'username = ?' => trim($user->getUsername()),
                        'device_id = ?' => $deviceId
                    ]
                );
            }

            // add current session to the db
            $this->database->beginTransaction();
            try {
                $this->database->insert(
                    'session',
                    [
                        'id' => $this->session->getId(),
                        'username' => trim($user->getUsername()),
                        'device_id' => $deviceId
                    ]
                );
                $this->database->commitTransaction();
            } catch (PDOException $e) {
                Logger::error("Failed adding session to table 'session': \n\t" . $e->getMessage());
                $this->database->rollBackTransaction();
            }
            Logger::debug(
                "onLogin triggered for user " . $user->getUsername() . " and session " . $this->session->getId()
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

            // calculate device identifier
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?: null;
            $deviceId = Connection::calculateDeviceId($userAgent, $user->getUsername()) ?: 'default';

            $this->database->beginTransaction();
            try {
                $this->database->delete(
                    'session',
                    [
                        'id = ?' => $this->session->getId(),
                        'username = ?' => trim($user->getUsername()),
                        'device_id = ?' => $deviceId
                    ]
                );
                $this->database->commitTransaction();
            } catch (PDOException $e) {
                Logger::error("Failed deleting session from table 'session': \n\t" . $e->getMessage());
                $this->database->rollBackTransaction();
            }
            Logger::debug(
                "onLogout triggered for user " . $user->getUsername() . " and session " . $this->session->getId()
            );
        }
    }
}
