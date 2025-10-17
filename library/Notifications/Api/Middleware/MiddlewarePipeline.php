<?php

/* Icinga Notifications Web | (c) 2025 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Api\Middleware;

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SplQueue;

/**
 * This class implements a middleware pipeline that processes a series of middleware components
 * in sequence. It adheres to the PSR-15 standard for HTTP server request handlers.
 * Each middleware component can modify the request and response objects as needed.
 */
final class MiddlewarePipeline implements RequestHandlerInterface
{
    /**
     * The execution queue for the middleware pipeline.
     *
     * @var SplQueue<MiddlewareInterface>
     */
    private SplQueue $pipeline;

    /**
     * @param MiddlewareInterface[] $middlewares
     */
    public function __construct(
        array $middlewares,
    ) {
        $this->pipeline = new SplQueue();
        foreach ($middlewares as $middleware) {
            try {
                $this->pipe($middleware);
            } catch (\Exception $e) {
                throw new \InvalidArgumentException('All middlewares must implement MiddlewareInterface');
            }
        }
    }

    /**
     * Add middleware to the pipeline.
     *
     * @param MiddlewareInterface $middleware
     *
     * @return $this
     */
    public function pipe(MiddlewareInterface $middleware): self
    {
        $this->pipeline->enqueue($middleware);

        return $this;
    }

    /**
     * Handle the request and process the middleware pipeline.
     * This method is used to process the entire pipeline with a real request.
     * The request is passed to the first middleware in the pipeline.
     * The response is returned from the last middleware in the pipeline.
     * If no middleware is left in the pipeline, a 404 Not Found response is returned.
     *
     * @param ServerRequestInterface $request
     *
     * @return ResponseInterface
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $middleware = $this->pipeline->dequeue();

        if ($middleware === null) {
            return new Response(404, ['Content-Type' => 'application/json'], 'Not Found');
        }

        return $middleware->process($request, $this);
    }

    /**
     * Execute the middleware pipeline.
     * This method is used to process the entire pipeline with a fake request.
     *
     * @param ServerRequestInterface|null $request
     *
     * @return ResponseInterface
     */
    public function execute(ServerRequestInterface $request = null): ResponseInterface
    {
        if ($request === null) {
            $request = new ServerRequest('GET', '/'); // initial dummy request
        }

        return $this->handle($request);
    }
}
