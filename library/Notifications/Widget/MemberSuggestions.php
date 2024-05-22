<?php

/* Icinga Notifications Web | (c) 2024 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget;

use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Model\Contact;
use ipl\Html\Attributes;
use ipl\Html\BaseHtmlElement;
use ipl\Html\HtmlElement;
use ipl\Html\Text;
use ipl\Stdlib\Filter;
use Psr\Http\Message\ServerRequestInterface;

class MemberSuggestions extends BaseHtmlElement
{
    protected $tag = 'ul';

    /** @var string */
    protected $searchTerm;

    /** @var string */
    protected $originalValue;

    /** @var string[] */
    protected $excludeTerms = [];

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

    public function excludeTerms(array $terms): self
    {
        $this->excludeTerms = $terms;

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
        $this->excludeTerms($requestData['exclude'] ?? []);

        return $this;
    }

    protected function assemble(): void
    {
        $identifyExcludes = function (string $for): array {
            return array_filter(array_map(function ($term) use ($for) {
                if (strpos($term, ':') === false) {
                    return '';
                }

                [$type, $id] = explode(':', $term, 2);

                return $type === $for ? $id : '';
            }, $this->excludeTerms));
        };

        $contactsToExclude = $identifyExcludes('contact');

        $contactFilter = Filter::like('full_name', $this->searchTerm);
        if (! empty($contactsToExclude)) {
            $contactFilter = Filter::all(
                $contactFilter,
                Filter::any(
                    Filter::equal('full_name', $this->originalValue),
                    Filter::unequal('id', $contactsToExclude)
                )
            );
        }

        foreach (Contact::on(Database::get())->filter($contactFilter) as $contact) {
            $this->addHtml(
                new HtmlElement(
                    'li',
                    null,
                    new HtmlElement(
                        'input',
                        Attributes::create([
                            'type'        => 'button',
                            'value'       => $contact->full_name,
                            'data-label'  => $contact->full_name,
                            'data-search' => 'contact:' . $contact->id,
                            'data-color'  => $contact->color,
                            'data-class'  => 'contact'
                        ])
                    )
                )
            );
        }

        if ($this->isEmpty()) {
            $this->addHtml(
                new HtmlElement(
                    'li',
                    Attributes::create(['class' => 'nothing-to-suggest']),
                    new HtmlElement('em', null, Text::create(t('Nothing to suggest')))
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
