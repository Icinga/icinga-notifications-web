<?php

namespace Icinga\Module\Notifications\Api\Middlewares;

use Icinga\Exception\Http\HttpException;
use Icinga\Exception\Http\HttpExceptionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * This class implements a middleware pipeline that processes a series of middleware components
 * in sequence. It adheres to the PSR-15 standard for HTTP server request handlers.
 * Each middleware component can modify the request and response objects as needed.
 * If an exception occurs during the processing of a middleware, it can be handled by an optional
 * exception handler.
 */
final class MiddlewarePipeline implements RequestHandlerInterface
{
    /**
     * @param MiddlewareInterface[] $middlewares
     * @param RequestHandlerInterface $finalHandler
     * @param null|RequestHandlerInterface $exceptionHandler
     */
    public function __construct(
        private array $middlewares,
        private readonly RequestHandlerInterface $finalHandler,
        private readonly ?RequestHandlerInterface $exceptionHandler = null
    ) {
        array_map(function ($m) {
            if (! $m instanceof MiddlewareInterface) {
                throw new \InvalidArgumentException('All middlewares must implement MiddlewareInterface');
            }
        }, $this->middlewares);
    }

    /**
     * @throws HttpException
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $middleware = array_shift($this->middlewares);
        if ($middleware === null) {
            return $this->finalHandler->handle($request);
        }

        try {
            $response = $middleware->process($request, $this);
        } catch (HttpExceptionInterface $e) {
            if ($this->exceptionHandler !== null) {
                $requestWithException = $request->withAttribute('exception', $e);
                return $this->exceptionHandler->handle($requestWithException);
            }

            $code = $e->getStatusCode();
            if ($code < 400 || $code >= 600) {
                $code = 500;
            }
            throw new HttpException(
                statusCode: $code,
                message: $e->getMessage()
            );
        } catch (\Throwable $e) {
            if ($this->exceptionHandler !== null) {
                $requestWithException = $request->withAttribute('exception', $e);
                return $this->exceptionHandler->handle($requestWithException);
            }
            throw new HttpException(500, 'Internal Server Error: ' . $e->getMessage());
        }

        return $response;
    }
}
