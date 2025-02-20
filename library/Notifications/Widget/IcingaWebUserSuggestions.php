<?php

/* Icinga Notifications Web | (c) 2024 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget;

use Exception;
use Icinga\Application\Config;
use Icinga\Authentication\User\DomainAwareInterface;
use Icinga\Authentication\User\UserBackend;
use Icinga\Data\Selectable;
use Icinga\Repository\Repository;
use ipl\Html\Attributes;
use ipl\Html\BaseHtmlElement;
use ipl\Html\HtmlElement;
use ipl\Html\Text;
use ipl\I18n\Translation;
use ipl\Web\Control\SearchBar\Suggestions;
use Psr\Http\Message\ServerRequestInterface;

class IcingaWebUserSuggestions extends BaseHtmlElement
{
    use Translation;

    protected $tag = 'ul';

    /** @var string */
    protected $searchTerm;

    /** @var string */
    protected $originalValue;

    public function setSearchTerm(string $term): self
    {
        $this->searchTerm = $term;

        return $this;
    }

    public function setOriginalValue(string $term): self
    {
        $this->originalValue = $term;

        return $this;
    }

    /**
     * Load suggestions as requested by the client
     *
     * @param ServerRequestInterface $request
     *
     * @return $this
     */
    public function forRequest(ServerRequestInterface $request): self
    {
        if ($request->getMethod() !== 'POST') {
            return $this;
        }

        $requestData = json_decode($request->getBody()->read(8192), true);
        if (empty($requestData)) {
            return $this;
        }

        $this->setSearchTerm($requestData['term']['label']);
        $this->setOriginalValue($requestData['term']['search']);

        return $this;
    }

    protected function assemble(): void
    {
        $userBackends = [];
        foreach (Config::app('authentication') as $backendName => $backendConfig) {
            $candidate = UserBackend::create($backendName, $backendConfig);
            if ($candidate instanceof Selectable) {
                $userBackends[] = $candidate;
            }
        }

        $limit = 10;
        while ($limit > 0 && ! empty($userBackends)) {
            /** @var Repository $backend */
            $backend = array_shift($userBackends);
            $query = $backend->select()
                ->from('user', ['user_name'])
                ->where('user_name', $this->searchTerm)
                ->limit($limit);

            try {
                $names = $query->fetchColumn();
            } catch (Exception $e) {
                continue;
            }

            if (empty($names)) {
                continue;
            }

            if ($backend instanceof DomainAwareInterface) {
                $names = array_map(function ($name) use ($backend) {
                    return $name . '@' . $backend->getDomain();
                }, $names);
            }

            $this->addHtml(
                new HtmlElement(
                    'li',
                    new Attributes(['class' => Suggestions::SUGGESTION_TITLE_CLASS]),
                    new Text($this->translate('Backend')),
                    new HtmlElement('span', new Attributes(['class' => 'badge']), new Text($backend->getName()))
                )
            );

            foreach ($names as $name) {
                $this->addHtml(
                    new HtmlElement(
                        'li',
                        null,
                        new HtmlElement(
                            'input',
                            Attributes::create([
                                'type'        => 'button',
                                'value'       => $name,
                                'data-label'  => $name,
                                'data-search' => $name,
                                'data-class'  => 'icinga-web-user',
                            ])
                        )
                    )
                );
            }

            $limit -= count($names);
        }

        if ($this->isEmpty()) {
            $this->addHtml(
                new HtmlElement(
                    'li',
                    Attributes::create(['class' => 'nothing-to-suggest']),
                    new HtmlElement('em', null, Text::create($this->translate('Nothing to suggest')))
                )
            );
        }
    }

    public function renderUnwrapped(): string
    {
        $this->ensureAssembled();

        if ($this->isEmpty()) {
            return '';
        }

        return parent::renderUnwrapped();
    }
}
