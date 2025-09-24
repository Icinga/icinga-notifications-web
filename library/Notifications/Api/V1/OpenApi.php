<?php

namespace Icinga\Module\Notifications\Api\V1;

use FilesystemIterator;
use Icinga\Application\Icinga;
use Icinga\Exception\ProgrammingError;
use Icinga\Module\Notifications\Common\PsrLogger;
use OpenApi\Generator;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

#[OA\Schema(
    schema: 'Url',
    description: 'A URL used in the API',
    type: 'string',
    maxLength: 2048,
    example: 'example.com',
)]
#[OA\Schema(
    schema: 'Port',
    description: 'A port number',
    type: 'integer',
    format: 'int32',
    maximum: 65535,
    minimum: 1,
)]
#[OA\Schema(
    schema: 'Email',
    description: 'An email address',
    type: 'string',
    format: 'email',
    maxLength: 320,
)]
#[OA\Schema(
    schema: 'ErrorStatus',
    description: 'status',
    type: 'string',
    example: 'error',
)]
//#[OA\Schema(
//    schema: 'ErrorResponse',
//    description: 'Error response format',
//    properties: [
//        new OA\Property(
//            property: 'status',
//            description: 'Status of the response',
//            type: 'string',
//        ),
//        new OA\Property(
//            property: 'message',
//            description: 'Detailed error message',
//            type: 'string',
//        )
//    ],
//    type: 'object',
//)]
#[OA\Components(
    examples: [
        new OA\Examples(
            example: 'ContactCreated',
            summary: 'Contact created successfully',
            value: [
                'status'  => 'success',
                'message' => 'Contact created successfully',
            ]
        ),
//        new OA\Examples(
//            example: 'IDParameterInvalidUUID',
//            summary: 'Invalid UUID format',
//            value: [
//                'status'  => 'error',
//                'message' => 'Provided id-parameter is not a valid UUID',
//            ],
//        ),
//        new OA\Examples(
//            example: 'IdentifierNotFound',
//            summary: 'Identifier not found',
//            value: ['message' => 'Identifier not found']
//        ),
        new OA\Examples(
            example: 'InvalidIdentifier',
            summary: 'Identifier is not valid',
            value: ['message' => 'The given identifier is not a valid UUID']
        ),
        new OA\Examples(
            example: 'MissingRequiredRequestBodyField',
            summary: 'Missing required request body field',
            value: [
                'status'  => 'error',
                'message' => 'Missing required field in request body: X',
            ],
        ),
        new OA\Examples(
            example: 'InvalidRequestBodyField',
            summary: 'Invalid request body field',
            value: [
                'status'  => 'error',
                'message' => 'Invalid field in request body: X',
            ],
        ),
        new OA\Examples(
            example: 'ContentTypeNotSupported',
            summary: 'Content type not supported',
            value: [
                'status'  => 'error',
                'message' => 'Content type is missing or not supported, please use application/json',
            ],
        ),
        new OA\Examples(
            example: 'InvalidRequestBody',
            summary: 'Invalid request body',
            value: [
                'status'  => 'error',
                'message' => 'Request body is not valid JSON',
            ],
        ),
    ]
)]
#[OA\Schema(
    schema: 'UUID',
    description: 'An UUID representing',
    type: 'string',
    format: 'uuid',
    maxLength: 36,
    minLength: 36,
)]
class OpenApi extends ApiV1 implements RequestHandlerInterface
{
    public const OPENAPI_PATH = __DIR__ . '/docs/openapi.json';

    public function getEndpoint(): string
    {
        return 'openapi';
    }

    /**
     * Generate OpenAPI documentation for the Notifications API
     *
     * @return ResponseInterface
     *
     * @throws ProgrammingError
     */
    public function get(): ResponseInterface
    {
        // TODO: Create the documentation during CI and not on request
//        if (file_exists(self::OPENAPI_PATH)) {
//            $oad = file_get_contents(self::OPENAPI_PATH);
//        } else {
            $files = $this->getFilesIncludingDocs();

            try {
                $openapi = (new Generator(new PsrLogger()))
                    ->setVersion(\OpenApi\Annotations\OpenApi::VERSION_3_1_0)
                    ->generate($files);
            } catch (RuntimeException $e) {
                throw new RuntimeException('Unable to generate OpenApi: ' . $e->getMessage());
            }

            $oad = $openapi->toJson(
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE | JSON_PRETTY_PRINT
            );

            if (! is_dir(dirname(self::OPENAPI_PATH))) {
                mkdir(dirname(self::OPENAPI_PATH), 0755, true);
            }

            file_put_contents(self::OPENAPI_PATH, $oad);
//        }

        return $this->createResponse(body: $oad);
    }

    /**
     * Get the files including the ApiCore.php file and any other files matching the given filter.
     *
     * @param string $fileFilter
     *
     * @return array
     *
     * @throws ProgrammingError
     */
    protected function getFilesIncludingDocs(string $fileFilter = '*'): array
    {
//        $apiCoreDir = __DIR__ . '/ApiCore.php';
        // TODO: find a way to get the module name from the request or class context
//        $moduleName = $this->getRequest()->getModuleName() ?: 'default;';
        $moduleName = 'notifications';

        if ($moduleName === 'default' || $moduleName === '') {
            $baseDir = Icinga::app()->getLibraryDir('Icinga/Application/Api/');
        } else {
            $baseDir = Icinga::app()->getModuleManager()->getModuleDir($moduleName)
                . '/library/' . ucfirst($moduleName) . '/Api/';
        }
        $dirEndpoints = $baseDir . strtoupper(static::VERSION) . '/';
        $dirElements = $baseDir . 'OpenApiDescriptionElements/';
        $dirs = [$dirEndpoints, $dirElements];

        $files = [];
        foreach ($dirs as $dir) {
            $dir = rtrim($dir, '/') . '/';
            if (! is_dir($dir)) {
                throw new RuntimeException("Directory $dir does not exist");
            }
            if (! is_readable($dir)) {
                throw new RuntimeException("Directory $dir is not readable");
            }

//            $currentFiles = glob($dir . '*', GLOB_NOSORT | GLOB_BRACE | GLOB_MARK);
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
            );

            $currentFiles = [];
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $currentFiles[] = $file->getPathname();
                }
            }
//            array_unshift($currentFiles, $apiCoreDir);
            if ($currentFiles === []) {
                throw new RuntimeException("Failed to read files from directory: $dir");
            }
            $files = array_merge($files, $currentFiles);
        }

        return $files;
    }
}
