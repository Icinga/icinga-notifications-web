<?php

namespace Icinga\Module\Notifications\Api\Elements;

use Icinga\Exception\Http\HttpBadRequestException;
use Icinga\Exception\Http\HttpException;
use Icinga\Exception\Http\HttpNotFoundException;

class HttpError
{
    /**
     * Immediately respond w/ HTTP 400
     *
     * @param string $message Exception message or exception format string
     * @param mixed ...$arg Format string argument
     *
     * @return never
     *
     * @throws  HttpBadRequestException
     */
    public static function badRequest(string $message, mixed ...$arg): never
    {
        throw HttpBadRequestException::create(func_get_args());
    }


    /**
     * Immediately respond w/ HTTP 404
     *
     * @param string $message Exception message or exception format string
     * @param mixed ...$arg Format string argument
     *
     * @return never
     *
     * @throws  HttpNotFoundException
     */
    public static function notFound(string $message, mixed ...$arg): never
    {
        throw HttpNotFoundException::create(func_get_args());
    }

    /**
     * Immediately respond w/ HTTP 405
     * This method throws an HttpException with a 405 status code (Method Not Allowed).
     *
     * @param string $message
     * @param mixed ...$arg
     * @return never
     * @throws HttpException
     */
    public static function methodNotAllowed(string $message, mixed ...$arg): never
    {
        throw new HttpException(405, ...func_get_args());
    }

    /**
     *  Immediately respond w/ HTTP 409
     * This method throws an HttpException with a 409 status code (Conflict).
     *
     * @param string $message
     * @param mixed ...$arg
     * @return never
     * @throws HttpException
     */
    public static function conflict(string $message, mixed ...$arg): never
    {
        throw new HttpException(409, ...func_get_args());
    }

    /**
     * Immediately respond w/ HTTP 415
     * This method throws an HttpException with a 415 status code (Unsupported Media Type).
     *
     * @param string $message
     * @param mixed ...$arg
     * @return never
     * @throws HttpException
     */
    public static function unsupportedMediaType(string $message, mixed ...$arg): never
    {
        throw new HttpException(415, ...func_get_args());
    }
    /**
     * Immediately respond w/ HTTP 422
     * This method throws an HttpException with a 422 status code (Unprocessable Entity).
     *
     * @param string $message
     * @param mixed ...$arg
     * @return never
     * @throws HttpException
     */
    public static function unprocessableEntity(string $message, mixed ...$arg): never
    {
        throw new HttpException(422, ...func_get_args());
    }
}
