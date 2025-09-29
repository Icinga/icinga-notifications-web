<?php

namespace Icinga\Module\Notifications\Api\V1;

use Icinga\Exception\Http\HttpBadRequestException;
use Icinga\Exception\Http\HttpNotFoundException;
use Icinga\Exception\Json\JsonEncodeException;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Util\Json;
use ipl\Sql\Select;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use stdClass;

#[OA\Schema(
    schema: 'Channel',
    description: 'A notification channel',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'type', type: 'string', maxLength: 255),
        new OA\Property(
            property: 'config',
            description: 'Configuration for the channel',
            oneOf: [
                new OA\Schema(ref: '#/components/schemas/EmailChannelConfig'),
                new OA\Schema(ref: '#/components/schemas/WebhookChannelConfig'),
                new OA\Schema(ref: '#/components/schemas/RocketChatChannelConfig'),
            ]
        )
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'WebhookChannelConfig',
    title: 'Webhook Channel Config',
    required: ['url_template'],
    properties: [
        new OA\Property(
            property: 'url_template',
            ref: '#/components/schemas/Url',
            description: 'URL template for the webhook'
        )
    ],
)]
#[OA\Schema(
    schema: 'EmailChannelConfig',
    title: 'Email Channel Config',
    required: [
        'host',
        'port',
        'sender_email',
    ],
    properties: [
        new OA\Property(
            property: 'SMTP Host',
            description: 'SMTP host for sending emails',
            type: 'string'
        ),
        new OA\Property(
            property: 'SMTP port',
            ref: '#/components/schemas/Port',
            description: 'SMTP port for sending emails',
        ),
        new OA\Property(
            property: 'Sender Name',
            description: 'Name of the sender for the email channel',
            type: 'string',
        ),
        new OA\Property(
            property: 'Sender Email',
            ref: '#/components/schemas/Email',
            description: 'Email address of the sender',
        ),
        new OA\Property(
            property: 'SMTP User',
            description: 'Username for SMTP authentication',
            type: 'string'
        ),
        new OA\Property(
            property: 'SMTP Password',
            description: 'Password for SMTP authentication',
            type: 'string'
        ),
        new OA\Property(
            property: 'SMTP Encryption',
            description: 'Encryption method for SMTP',
            type: 'string',
            enum: ['none', 'ssl', 'tls']
        ),
    ]
)]
#[OA\Schema(
    schema: 'RocketChatChannelConfig',
    title: 'RocketChat Channel Config',
    description: 'The configuration for a Rocket.Chat notification channel',
    required: [
        'url',
        'user_id',
        'token'
    ],
    properties: [
        new OA\Property(
            property: 'url',
            ref: '#/components/schemas/Url',
            description: 'URL of the Rocket.Chat server'
        ),
        new OA\Property(
            property: 'user_id',
            description: 'User ID for Rocket.Chat',
            type: 'string',
        ),
        new OA\Property(
            property: 'token',
            description: 'Authentication token for Rocket.Chat',
            type: 'string',
        )
    ],
)]
#[OA\Schema(
    schema: 'ChannelTypes',
    description: 'Available channel types',
    type: 'string',
    enum: ['email', 'webhook', 'rocketchat'],
)]
#[OA\Schema(
    schema: 'ChannelUUID',
    title: 'ChannelUUID',
    description: 'An UUID representing a notification channel',
    type: 'string',
    format: 'uuid',
    maxLength: 36,
    minLength: 36,
    example: 'f0d02dba-b7f9-40a4-bb21-74ce2bd8db70',
)]
class Channels extends ApiV1
{
    public function getEndpoint(): string
    {
        return 'channels';
    }

    /**
     * Get a channel by UUID.
     *
     * @param string|null $identifier
     * @param string $queryFilter
     * @return ResponseInterface
     * @throws HttpBadRequestException
     * @throws HttpNotFoundException
     * @throws JsonEncodeException
     */
    public function get(?string $identifier, string $queryFilter): ResponseInterface
    {
        $stmt = (new Select())
            ->distinct()
            ->from('channel ch')
            ->columns([
                'channel_id' => 'ch.id',
                'id'         => 'ch.external_uuid',
                'name',
                'type',
                'config'
            ]);

        if ($identifier === null) {
            return $this->getPlural($queryFilter, $stmt);
        }

        $stmt->where(['external_uuid = ?' => $identifier]);

        /** @var stdClass|false $result */
        $result = Database::get()->fetchOne($stmt);

        if ($result === false) {
            throw new HttpNotFoundException('Channel not found');
        }

        $this->prepareRow($result);

        return $this->createResponse(body: Json::sanitize(['data' => [$result]]));
    }

    /**
     * List channels or get specific channels by filter parameters.
     *
     * @param string $queryFilter
     * @param Select $stmt
     *
     * @return ResponseInterface
     *
     * @throws HttpBadRequestException
     * @throws JsonEncodeException
     */
    private function getPlural(string $queryFilter, Select $stmt): ResponseInterface
    {
        $filter = $this->assembleFilter(
            $queryFilter,
            ['id', 'name', 'type'],
            'external_uuid'
        );

        if ($filter !== false) {
            $stmt->where($filter);
        }

        return $this->createResponse(body: $this->createContentGenerator($stmt));
    }

    /**
     * Get the channel id with the given identifier
     *
     * @param string $channelIdentifier
     *
     * @return int|false
     */
    public static function getChannelId(string $channelIdentifier): int|false
    {
        /** @var stdClass|false $channel */
        $channel = Database::get()->fetchOne(
            (new Select())
                ->from('channel')
                ->columns('id')
                ->where(['external_uuid = ?' => $channelIdentifier])
        );

        return $channel->id ?? false;
    }

    public function prepareRow(stdClass $row): void
    {
            unset($row->channel_id);
    }
}
