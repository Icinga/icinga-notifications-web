<?php

/* Icinga Notifications Web | (c) 2025 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Api\V1;

use Icinga\Exception\Http\HttpBadRequestException;
use Icinga\Exception\Http\HttpNotFoundException;
use Icinga\Exception\Json\JsonEncodeException;
use Icinga\Module\Notifications\Api\EndpointInterface;
use Icinga\Module\Notifications\Api\OpenApiDescriptionElement\OadV1GetPlural;
use Icinga\Module\Notifications\Api\OpenApiDescriptionElement\Parameter\PathParameter;
use Icinga\Module\Notifications\Api\OpenApiDescriptionElement\Parameter\QueryParameter;
use Icinga\Module\Notifications\Api\OpenApiDescriptionElement\OadV1Get;
use Icinga\Module\Notifications\Api\OpenApiDescriptionElement\Schema\SchemaUUID;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Util\Json;
use ipl\Sql\Select;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use stdClass;

#[OA\Schema(
    schema: 'Channel',
    description: 'A notification channel represents a destination for notifications in Icinga. \
    Channels can be of different types, such as email, webhook, or Rocket.Chat, 
    each with its own configuration requirements. \
    Channels are used to route notifications to users or external systems based on their type and configuration.',
    required: ['id', 'name', 'type', 'config'],
    type: 'object'
)]
#[SchemaUUID(
    entityName: 'Channel',
    example: 'bb4af7bd-f0da-489c-ae31-23f714bde714'
)]
#[OA\Schema(
    schema: 'ChannelTypes',
    description: 'Available notification channel types',
    type: 'string',
    example: 'webhook'
)]
class Channels extends ApiV1 implements RequestHandlerInterface, EndpointInterface
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
        type: 'string',
        format: 'application/json',
        example: '{"url":"https://example.com/webhook"}'
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
        path: '/channels/{identifier}',
        description: 'Retrieve detailed information about a specific notification Channel using its UUID',
        summary: 'Get a specific Channel by its UUID',
        tags: ['Channels'],
        parameters: [
            new PathParameter(
                name: 'identifier',
                description: 'The UUID of the Channel to retrieve',
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

        return $this->createResponse(body: Json::sanitize(['data' => $result]));
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
        path: '/channels',
        description: 'List all notification channels or filter by parameters',
        summary: 'List all notification channels or filter by parameters',
        tags: ['Channels'],
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

    /**
     * Get the type of the channel
     *
     * @param string $channelId
     *
     * @return string
     */
    public static function getChannelType(string $channelId): string
    {
        /** @var stdClass|false $channel */
        $channel = Database::get()->fetchOne(
            (new Select())
                ->from('channel')
                ->columns('type')
                ->where(['id = ?' => $channelId])
        );

        return $channel->type;
    }

    public function prepareRow(stdClass $row): void
    {
        $row->config = Json::decode($row->config, true);
        unset($row->channel_id);
    }
}
