<?php

/* Icinga Notifications Web | (c) 2025 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Api\V1;

use Icinga\Exception\Http\HttpBadRequestException;
use Icinga\Exception\Http\HttpNotFoundException;
use Icinga\Exception\Json\JsonEncodeException;
use Icinga\Module\Notifications\Api\OpenApiDescriptionElements\OadV1GetPlural;
use Icinga\Module\Notifications\Api\OpenApiDescriptionElements\Parameters\PathParameter;
use Icinga\Module\Notifications\Api\OpenApiDescriptionElements\Parameters\QueryParameter;
use Icinga\Module\Notifications\Api\OpenApiDescriptionElements\OadV1Get;
use Icinga\Module\Notifications\Api\OpenApiDescriptionElements\Schemas\SchemaUUID;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Util\Json;
use ipl\Sql\Select;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use stdClass;

#[OA\Schema(
    schema: 'Channel',
    description: 'A notification channel',
    required: ['id', 'name', 'type', 'config'],
    type: 'object'
)]
#[OA\Schema(
    schema: 'ChannelTypes',
    description: 'Available channel types',
    type: 'string',
    enum: ['email', 'webhook', 'rocketchat'],
    example: 'webhook',
)]
#[SchemaUUID(
    entityName: 'Channel',
    example: '3fa85f64-5717-4562-b3fc-2c963f66afa6'
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
        'smtp_host',
        'smtp_port',
        'sender_name',
        'sender_address',
        'smtp_user',
        'smtp_password',
    ],
    properties: [
        new OA\Property(
            property: 'smtp_host',
            description: 'SMTP host for sending emails',
            type: 'string'
        ),
        new OA\Property(
            property: 'smtp_port',
            ref: '#/components/schemas/Port',
            description: 'SMTP port for sending emails',
        ),
        new OA\Property(
            property: 'sender_name',
            description: 'Name of the sender for the email channel',
            type: 'string',
        ),
        new OA\Property(
            property: 'sender_address',
            ref: '#/components/schemas/Email',
            description: 'Email address of the sender',
        ),
        new OA\Property(
            property: 'smtp_user',
            description: 'Username for SMTP authentication',
            type: 'string'
        ),
        new OA\Property(
            property: 'smtp_password',
            description: 'Password for SMTP authentication',
            type: 'string'
        ),
        new OA\Property(
            property: 'smtp_encryption',
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
class Channels extends ApiV1 implements RequestHandlerInterface
{
    #[OA\Property(
        ref: '#/components/schemas/ChannelUUID',
    )]
    protected string $id;
    #[OA\Property(
        description: 'The name of the channel',
        type: 'string',
        example: 'My Webhook Channel',
    )]
    protected string $name;
    #[OA\Property(
        ref: '#/components/schemas/ChannelTypes',
    )]
    protected string $type;
    #[OA\Property(
        description: 'The configuration for the channel, varies depending on the channel type',
        example: [
            'url_template' => 'https://example.com/webhook?token=abc123',
        ],
        oneOf: [
            new OA\Schema(ref: '#/components/schemas/EmailChannelConfig'),
            new OA\Schema(ref: '#/components/schemas/WebhookChannelConfig'),
            new OA\Schema(ref: '#/components/schemas/RocketChatChannelConfig'),
        ]
    )]
    protected array $config;

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
    #[OadV1Get(
        entityName: 'Channel',
        path: 'channels',
        description: 'Get a specific channel by its UUID',
        summary: 'Get a specific channel by its UUID',
        tags: ['Channels'],
        parameters: [
            new PathParameter(
                name: 'identifier',
                description: 'The UUID of the channel to retrieve',
                identifierSchema: 'ChannelUUID'
            ),
        ],
        responses: []
    )]
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
    #[OadV1GetPlural(
        entityName: 'Channel',
        path: 'channels/{identifier}',
        description: 'List all notification channels or filter by parameters',
        summary: 'List all notification channels or filter by parameters',
        tags: ['Channels'],
        filter: ['id', 'name', 'type'],
        parameters: [
            new QueryParameter(
                name: 'id',
                description: 'Filter by channel UUID',
                schema: new SchemaUUID(entityName: 'Channel'),
            ),
            new QueryParameter(
                name: 'name',
                description: 'Filter by channel name (supports partial matches)',
            ),
            new QueryParameter(
                name: 'type',
                description: 'Filter by channel type',
                identifierSchema: 'ChannelTypes',
            ),
        ],
        responses: []
    )]
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
