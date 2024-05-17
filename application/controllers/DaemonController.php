<?php

/* Icinga Notifications Web | (c) 2024 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Controllers;

use Icinga\Application\Icinga;
use ipl\Web\Compat\CompatController;
use ipl\Web\Compat\ViewRenderer;
use Zend_Layout;

class DaemonController extends CompatController
{
    protected $requiresAuthentication = false;

    public function init(): void
    {
        /*
         * Initialize the controller and disable the view renderer and layout as this controller provides no
         * graphical output
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
        /**
         * we have to use `getRequest()->getParam` here instead of the usual `$this->param` as the required parameters
         * are not submitted by an HTTP request but injected manually {@see icinga-notifications-web/run.php}
         */
        $fileName = $this->getRequest()->getParam('file', 'undefined');
        $extension = $this->getRequest()->getParam('extension', 'undefined');
        $mime = '';

        switch ($extension) {
            case 'undefined':
                $this->httpNotFound(t("File extension is missing."));

            // no return
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

        $filePath = realpath($root . DIRECTORY_SEPARATOR . 'notifications-' . $fileName . $extension);
        if ($filePath === false) {
            if ($fileName === 'undefined') {
                $this->httpNotFound(t("No file name submitted"));
            }

            $this->httpNotFound(sprintf(t("notifications-%s%s does not exist"), $fileName, $extension));
        } else {
            $fileStat = stat($filePath);

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
                        ->setHeader(
                            'Last-Modified',
                            gmdate('D, d M Y H:i:s', $fileStat['mtime']) . ' GMT'
                        );
                    $file = file_get_contents($filePath);
                    if ($file) {
                        $this->getResponse()->setBody($file);
                    }
                }
            } else {
                $this->httpNotFound(sprintf(t("notifications-%s%s could not be read"), $fileName, $extension));
            }
        }
    }
}
