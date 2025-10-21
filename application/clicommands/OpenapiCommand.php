<?php

namespace Icinga\Module\Notifications\Clicommands;

use FilesystemIterator;
use Icinga\Application\Icinga;
use Icinga\Cli\Command;
use Icinga\Module\Notifications\Api\OpenApiPreprocessor\AddGlobal401Response;
use Icinga\Module\Notifications\Common\PsrLogger;
use OpenApi\Generator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;

class OpenapiCommand extends Command
{
    public function runAction(): void
    {
        echo "\n\n\ntest\n\n\n";
    }

    public function generateAction(): void
    {

        /**
         * CLI tool to generate an OpenAPI JSON file from PHP attributes using swagger-php.
         *
         * Usage:
         *   icingacli openapi generate \
         *       --dir ./src \
         *       --exclude vendor,tests \
         *       --include ApiController.php,UserController.php \
         *       --output ./docs/openapi.json \
         *       --api-version v1
         *       --oad-version 3.1.0
         */

        $directoryInNotifications = $this->params->get('dir', '/library/Notifications/Api/');
        $exclude = $this->params->get('exclude');
        $include = $this->params->get('include');
        $outputPath = $this->params->get('output');
        $apiVersion = $this->params->get('api-version', 'v1');
        $oadVersion = $this->params->get('oad-version', '3.1.0');

        $notificationsPath = Icinga::app()->getModuleManager()->getModule('notifications')->getBaseDir();
        $directory = $notificationsPath . $directoryInNotifications;

        $baseDirectory = realpath($directory);
        if ($baseDirectory === false || !is_dir($baseDirectory)) {
            throw new RuntimeException("Invalid directory: {$directory}");
        }

        $exclude = isset($exclude) ? array_map('trim', explode(',', $exclude)) : [];
        $include = isset($include) ? array_map('trim', explode(',', $include)) : [];
        $outputPath = $notificationsPath . ($outputPath ?? '/doc/api/api-' . $apiVersion . '-public.json');

        $files = $this->collectPhpFiles($baseDirectory, $exclude, $include);

        echo "→ Scanning directory: $baseDirectory\n";
        echo "→ Found " . count($files) . " PHP files\n";
//        die;

        $generator = new Generator(new PsrLogger());
        $generator->setVersion($oadVersion);
        $generator->getProcessorPipeline()->add(new AddGlobal401Response());

        try {
            $openapi = $generator->generate($files);

            $json = $openapi->toJson(
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE | JSON_PRETTY_PRINT
            );

            $dir = dirname($outputPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            file_put_contents($outputPath, $json);

            echo "✅ OpenAPI documentation written to: $outputPath\n";
        } catch (Throwable $e) {
            fwrite(STDERR, "❌ Error generating OpenAPI: " . $e->getMessage() . "\n");
            exit(1);
        }
    }

    /**
     * Recursively scan a directory for PHP files.
     */
    function collectPhpFiles(string $baseDirectory, array $exclude, array $include): array
    {
        $baseDirectory = rtrim($baseDirectory, '/') . '/';
        if (! is_dir($baseDirectory)) {
            throw new RuntimeException("Directory $baseDirectory does not exist");
        }
        if (! is_readable($baseDirectory)) {
            throw new RuntimeException("Directory $baseDirectory is not readable");
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($baseDirectory, FilesystemIterator::SKIP_DOTS)
        );

//        echo PHP_EOL;
//        var_dump($iterator);
//        echo PHP_EOL . PHP_EOL;
//        die();

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();

            // Exclude
            if ($exclude !== [] && $this->matchesAnyPattern($path, $exclude)) {
                continue;
            }

            // Include filter (if defined)
            if ($include !== [] && ! $this->matchesAnyPattern($path, $include)) {
                continue;
            }

            $files[] = $path;
        }

        if (empty($files)) {
            throw new RuntimeException("No PHP files found in $baseDirectory");
        }

        return $files;
    }
//
    function matchesAnyPattern(string $string, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            // Escape regex special chars except for '*'
            $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/';
//            echo PHP_EOL . $regex . PHP_EOL; die;
            if (preg_match($regex, $string)) {
                return true;
            }
        }

        return false;
    }
}
