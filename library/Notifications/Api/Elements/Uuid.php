<?php

namespace Icinga\Module\Notifications\Api\Elements;

use InvalidArgumentException;
use Ramsey\Uuid\Uuid as RamseyUuid;

class Uuid
{
    /**
     * The UUID identifier.
     *
     * @var string
     */
    protected string $identifier;

    public function __construct(string $identifier)
    {
        if (RamseyUuid::isValid($identifier)) {
            $this->identifier = $identifier;
        } else {
            throw new InvalidArgumentException("Invalid UUID: $identifier");
        }
    }

    /**
     * Validate if the given string is a valid UUID.
     *
     * @param string $identifier The UUID string to validate.
     * @return bool True if valid, false otherwise.
     */
    public static function isValid(string $identifier): bool
    {
        return RamseyUuid::isValid($identifier);
    }

    /**
     * Return the UUID as a string.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->identifier;
    }
}
