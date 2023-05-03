<?php

/* Icinga NoMa Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Noma\Controllers;

use Icinga\Module\Noma\Common\Auth;
use Icinga\Module\Noma\Common\Database;
use Icinga\Module\Noma\Model\ObjectExtraTag;
use Icinga\Module\Noma\Model\Rule;
use Icinga\Module\Noma\Web\Control\SearchBar\ExtraTagSuggestions;
use Icinga\Module\Noma\Widget\EventRuleConfig;
use Icinga\Web\Session;
use ipl\Html\Html;
use ipl\Stdlib\Filter;
use ipl\Web\Compat\CompatController;
use ipl\Web\Control\SearchEditor;
use ipl\Web\Filter\QueryString;
use ipl\Web\Url;

class EventRuleController extends CompatController
{
    use Auth;

    /** @var Session\SessionNamespace */
    private $sessionNamespace;

    public function init()
    {
        $this->sessionNamespace = Session::getSession()->getNamespace('noma');
    }

    public function indexAction(): void
    {
        $this->assertPermission('noma/config/event-rules');

        $this->addTitleTab(t('Event Rule'));

        $ruleId = $this->params->getRequired('id');

        $cache = $this->sessionNamespace->get($ruleId);

        if ($cache) {
            //TODO: just for tests, render correctly
            $this->addContent(Html::tag('span', 'This is cached config'));
            $eventRuleConfig = new EventRuleConfig($cache);
        } else {
            $eventRuleConfig = new EventRuleConfig($this->fromDb($ruleId));
        }

        $eventRuleConfig->on(EventRuleConfig::ON_CHANGE, function ($eventRuleConfig) use ($ruleId) {
            $this->sessionNamespace->set($ruleId, $eventRuleConfig->getConfig());
        });

        $eventRuleConfig->on(EventRuleConfig::ON_SUBMIT, function ($eventRuleConfig) use ($ruleId) {
            if (! $eventRuleConfig->isValid()) {
                $eventRuleConfig->addAttributes(['class' => 'invalid']);
                return;
            }

            // DB insert here
        });

        foreach ($eventRuleConfig->getForms() as $form) {
            $form->handleRequest($this->getServerRequest());
        }

        $this->addContent($eventRuleConfig);
    }

    public function fromDb($ruleId)
    {
        $query = Rule::on(Database::get())
            ->withoutColumns('timeperiod_id')
            ->filter(Filter::equal('id', $ruleId));

        $rule = $query->first();
        $config = iterator_to_array($rule);

        foreach ($rule->rule_escalation as $re) {
            foreach ($re as $k => $v) {
                $config[$re->getTableName()][$re->position][$k] = $v;
            }

            foreach ($re->rule_escalation_recipient as $recipient) {
                $config[$re->getTableName()][$re->position]['recipient'][] = iterator_to_array($recipient);
            }
        }

        if (! isset($config['rule_escalation'])) {
            $config['rule_escalation'] = [1 => []];
        }

        $config['showSearchbar'] = ! empty($config['object_filter']);

        return $config;
    }

    /**
     * completeAction for Object Extra Tags
     *
     * @return void
     */
    public function completeAction(): void
    {
        $suggestions = new ExtraTagSuggestions();
        $suggestions->forRequest($this->getServerRequest());
        $this->getDocument()->add($suggestions);
    }


    /**
     * searchEditorAction for Object Extra Tags
     *
     * @return void
     *
     * @throws \Icinga\Exception\MissingParameterException
     */
    public function searchEditorAction(): void
    {
        $ruleId = $this->params->shiftRequired('id');

        $queryString = $this->params->toString();

        $editor = EventRuleConfig::createSearchEditor(ObjectExtraTag::on(Database::get()))
            ->setQueryString($queryString);

        $editor->on(SearchEditor::ON_SUCCESS, function (SearchEditor $form) use ($ruleId) {
            $cache = $this->sessionNamespace->get($ruleId);
            $cache['object_filter'] = QueryString::render($form->getFilter());

            $this->sessionNamespace->set($ruleId, $cache);
            $this->getResponse()
                ->setHeader('X-Icinga-Container', '_self')
                ->redirectAndExit(
                    Url::fromPath(
                        'noma/event-rule',
                        ['id' => $ruleId]
                    )
                );
        });

        $editor->handleRequest($this->getServerRequest());

        $this->getDocument()->add($editor);
        $this->setTitle($this->translate('Adjust Filter'));
    }
}
