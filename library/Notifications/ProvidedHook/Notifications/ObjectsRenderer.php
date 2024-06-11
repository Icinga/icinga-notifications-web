<?php

/* Icinga Notifications Web | (c) 2024 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\ProvidedHook\Notifications;

use Generator;
use Icinga\Module\Icingadb\Common\Database;
use Icinga\Module\Icingadb\Model\Host;
use Icinga\Module\Icingadb\Model\Service;
use Icinga\Module\Icingadb\Redis\VolatileStateResults;
use Icinga\Module\Icingadb\Widget\ItemList\HostList;
use Icinga\Module\Icingadb\Widget\ItemList\ServiceList;
use Icinga\Module\Notifications\Hook\ObjectsRendererHook;
use ipl\Html\Attributes;
use ipl\Html\Html;
use ipl\Html\HtmlElement;
use ipl\Html\Text;
use ipl\Html\ValidHtml;
use ipl\Orm\Query;
use ipl\Stdlib\Filter;
use ipl\Web\Widget\StateBall;

class ObjectsRenderer extends ObjectsRendererHook
{
    use Database;

    public function getHtmlForObjectNames(array $objectIdTags): Generator
    {
        [$hostsQuery, $servicesQuery] = $this->buildQueries($objectIdTags);

        if ($hostsQuery) {
            foreach ($hostsQuery as $host) {
                $element = new HtmlElement(
                    'span',
                    Attributes::create(['class' => 'subject']),
                    Text::create($host->display_name)
                );

                yield ['host' => $host->name] => $element;
            }
        }

        if ($servicesQuery) {
            $servicesQuery
                ->with(['state', 'host.state'])
                ->withColumns(['service.state.soft_state', 'host.state.soft_state'])
                ->setResultSetClass(VolatileStateResults::class);

            foreach ($servicesQuery as $service) {
                $hostElm = [
                    new StateBall($service->host->state->getStateText(), StateBall::SIZE_MEDIUM),
                    Text::create(' '),
                    Text::create($service->host->display_name)
                ];

                $serviceElm = new HtmlElement(
                    'span',
                    Attributes::create(['class' => 'subject']),
                    Text::create($service->display_name)
                );

                $element = Html::sprintf(
                    t('%s on %s', '<service> on <host>'),
                    $serviceElm,
                    new HtmlElement('span', Attributes::create(['class' => 'subject']), ...$hostElm)
                );

                yield ['host' => $service->host->name, 'service' => $service->name] => $element;
            }
        }
    }

    public function getSourceType(): string
    {
        if (class_exists('Icinga\Module\Icingadb\ProvidedHook\Notifications\ObjectsRenderer')) {
            return 'use-icingadb-hook-implementation';
        }

        return 'icinga2';
    }

    public function createObjectLink(array $objectIdTag): ?ValidHtml
    {
        [$hostsQuery, $servicesQuery] = $this->buildQueries([$objectIdTag]);
        if ($servicesQuery) {
            $serviceStates = $servicesQuery
                ->columns([])
                ->with(['state', 'host.state'])
                ->setResultSetClass(VolatileStateResults::class)
                ->first();

            if ($serviceStates === null) {
                return null;
            }

            return (new ServiceList([$serviceStates]))
                ->setViewMode('minimal');
        }

        $hostStates = $hostsQuery
            ->columns([])
            ->with('state')
            ->setResultSetClass(VolatileStateResults::class)
            ->first();

        if ($hostStates === null) {
            return null;
        }

        return (new HostList([$hostStates]))
            ->setViewMode('minimal');
    }

    public function getObjectNames(array $objectIdTags): Generator
    {
        [$hostsQuery, $servicesQuery] = $this->buildQueries($objectIdTags);

        if ($hostsQuery) {
            foreach ($hostsQuery as $host) {
                yield ['host' => $host->name] => $host->display_name;
            }
        }

        if ($servicesQuery) {
            foreach ($servicesQuery as $service) {
                yield ['host' => $service->host->name, 'service' => $service->name] => sprintf(
                    t('%s on %s', '<service> on <host>'),
                    $service->display_name,
                    $service->host->display_name
                );
            }
        }
    }

    /**
     * Build queries for hosts and services with columns `name` and `display_name`
     *
     * @param array $objectIdTags
     *
     * @return Query[]
     */
    private function buildQueries(array $objectIdTags): array
    {
        $filterServices = Filter::any();
        $filterHosts = Filter::any();

        foreach ($objectIdTags as $tags) {
            if (isset($tags['service'])) {
                $filterServices->add(
                    Filter::all(
                        Filter::equal('service.name', $tags['service']),
                        Filter::equal('host.name', $tags['host'])
                    )
                );
            } else {
                $filterHosts->add(Filter::equal('host.name', $tags['host']));
            }
        }

        $hostsQuery = null;
        if (! $filterHosts->isEmpty()) {
            $hostsQuery = Host::on($this->getDb())
                ->columns(['name', 'display_name'])
                ->filter($filterHosts);
        }

        $servicesQuery = null;
        if (! $filterServices->isEmpty()) {
            $servicesQuery = Service::on($this->getDb())
                ->with('host')
                ->columns([
                    'service.name',
                    'service.display_name',
                    'host.name',
                    'host.display_name',
                ])
                ->filter($filterServices);
        }

        return [$hostsQuery, $servicesQuery];
    }
}
