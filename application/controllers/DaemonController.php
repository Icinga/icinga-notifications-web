<?php

namespace Icinga\Module\Notifications\Controllers;

use Icinga\Application\Icinga;
use ipl\Web\Compat\CompatController;

final class DaemonController extends CompatController {
    public function init(): void {
        /**
         * override init function and disable Zend rendering as this controller provides no graphical output
         */
        $this->_helper->viewRenderer->setNoRender(true);
        $this->_helper->layout()->disableLayout();
    }

    public function scriptAction(): void {
        $root = Icinga::app()
                ->getModuleManager()
                ->getModule('notifications')
                ->getBaseDir() . '/public/js';

        $filePath = realpath($root . DIRECTORY_SEPARATOR . 'icinga-notifications-worker.js');
        if($filePath === false) {
            $this->httpNotFound("'icinga-notifications-worker.js' does not exist");
        }

        $fileStat = stat($filePath);
        $eTag = sprintf(
            '%x-%x-%x',
            $fileStat['ino'],
            $fileStat['size'],
            (float)str_pad($fileStat['mtime'], 16, '0')
        );

        $this->getResponse()->setHeader(
            'Cache-Control',
            'public, max-age=1814400, stale-while-revalidate=604800',
            true
        );

        if($this->getRequest()->getServer('HTTP_IF_NONE_MATCH') === $eTag) {
            $this->getResponse()->setHttpResponseCode(304);
        } else {
            $this->getResponse()
                ->setHeader('ETag', $eTag)
                ->setHeader('Content-Type', 'text/javascript', true)
                ->setHeader('Last-Modified', gmdate('D, d M Y H:i:s', $fileStat['mtime']) . ' GMT')
                ->setBody(file_get_contents($filePath));
        }
    }
}