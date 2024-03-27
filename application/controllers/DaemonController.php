<?php

namespace Icinga\Module\Notifications\Controllers;

use Icinga\Application\Icinga;
use ipl\Web\Compat\CompatController;
use ipl\Web\Compat\ViewRenderer;
use Zend_Layout;

final class DaemonController extends CompatController
{
    protected $requiresAuthentication = false;

    public function init(): void
    {
        /**
         * override init function and disable Zend rendering as this controller provides no graphical output
         */
        /** @var ViewRenderer $viewRenderer */
        $viewRenderer = $this->getHelper('viewRenderer');
        $viewRenderer->setNoRender();
        /** @var Zend_Layout $layout */
        $layout = $this->getHelper('layout');
        $layout->disableLayout();
    }

    public function scriptAction(): void
    {
        $mime = '';
        switch ($this->_getParam('extension', 'undefined')) {
            case 'undefined':
                $this->httpNotFound("File extension is missing.");
            case '.js':
                $mime = 'application/javascript';
                break;
            case '.js.map':
                $mime = 'application/json';
                break;
        }
        $root = Icinga::app()
                ->getModuleManager()
                ->getModule('notifications')
                ->getBaseDir() . '/public/js';

        $filePath = realpath(
            $root . DIRECTORY_SEPARATOR . 'notifications-' . $this->_getParam(
                'file',
                'undefined'
            ) . $this->_getParam('extension', 'undefined')
        );
        if ($filePath === false) {
            if ($this->_getParam('file') === null) {
                $this->httpNotFound("No file name submitted");
            }
            $this->httpNotFound(
                "'notifications-"
                . $this->_getParam('file')
                . $this->_getParam('extension')
                . " does not exist"
            );
        } else {
            $fileStat = stat($filePath);
            $eTag = '';
            if ($fileStat) {
                $eTag = sprintf(
                    '%x-%x-%x',
                    $fileStat['ino'],
                    $fileStat['size'],
                    (float) str_pad((string) ($fileStat['mtime']), 16, '0')
                );

                $this->getResponse()->setHeader(
                    'Cache-Control',
                    'public, max-age=1814400, stale-while-revalidate=604800',
                    true
                );

                if ($this->getRequest()->getServer('HTTP_IF_NONE_MATCH') === $eTag) {
                    $this->getResponse()->setHttpResponseCode(304);
                } else {
                    $this->getResponse()
                        ->setHeader('ETag', $eTag)
                        ->setHeader('Content-Type', $mime, true)
                        ->setHeader('Last-Modified', gmdate('D, d M Y H:i:s', $fileStat['mtime']) . ' GMT');
                    $file = file_get_contents($filePath);
                    if ($file) {
                        $this->getResponse()->setBody($file);
                    }
                }
            } else {
                $this->httpNotFound(
                    "'notifications-"
                    . $this->_getParam('file')
                    . $this->_getParam('extension')
                    . " could not be read"
                );
            }
        }
    }
}
