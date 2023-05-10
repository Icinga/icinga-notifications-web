<?php

/* Icinga NoMa Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Noma\Controllers;

use Icinga\Module\Noma\Common\Auth;
use Icinga\Module\Noma\Common\Database;
use Icinga\Module\Noma\Common\Links;
use Icinga\Module\Noma\Forms\EventRuleForm;
use Icinga\Module\Noma\Forms\SaveEventRuleForm;
use Icinga\Module\Noma\Model\Incident;
use Icinga\Module\Noma\Model\ObjectExtraTag;
use Icinga\Module\Noma\Model\Rule;
use Icinga\Module\Noma\Web\Control\SearchBar\ExtraTagSuggestions;
use Icinga\Module\Noma\Widget\EventRuleConfig;
use Icinga\Web\Notification;
use Icinga\Web\Session;
use ipl\Html\Form;
use ipl\Html\Html;
use ipl\Stdlib\Filter;
use ipl\Web\Compat\CompatController;
use ipl\Web\Control\SearchEditor;
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
            $this->addContent(Html::tag('div', ['class' => 'cache-notice'], t('There are unsaved changes.')));
            $eventRuleConfig = new EventRuleConfig(
                Url::fromPath('noma/event-rule/search-editor', ['id' => $ruleId]),
                $cache
            );
        } else {
            $eventRuleConfig = new EventRuleConfig(
                Url::fromPath('noma/event-rule/search-editor', ['id' => $ruleId]),
                $this->fromDb($ruleId)
            );
        }

        $disableRemoveButton = false;
        if (ctype_digit($ruleId)) {
            $incidents = Incident::on(Database::get())
                ->with('rule')
                ->filter(Filter::equal('rule.id', $ruleId));

            if ($incidents->count() > 0) {
                $disableRemoveButton = true;
            }
        }

        $saveForm = (new SaveEventRuleForm())
            ->setShowRemoveButton()
            ->setShowDismissChangesButton($cache !== null)
            ->setRemoveButtonDisabled($disableRemoveButton)
            ->setSubmitButtonDisabled($cache === null)
            ->setSubmitLabel($this->translate('Save Changes'))
            ->on(SaveEventRuleForm::ON_SUCCESS, function ($form) use ($ruleId, $eventRuleConfig) {
                if ($form->getPressedSubmitElement()->getName() === 'discard_changes') {
                    $this->sessionNamespace->delete($ruleId);
                    Notification::success($this->translate('Successfully discarded the pending changes.'));
                    $this->redirectNow(Links::eventRule($ruleId));
                }

                if (! $eventRuleConfig->isValid()) {
                    $eventRuleConfig->addAttributes(['class' => 'invalid']);
                    return;
                }

                $form->editRule($ruleId, $this->sessionNamespace->get($ruleId));
                $this->sessionNamespace->delete($ruleId);

                Notification::success($this->translate('Successfully updated rule.'));
                $this->sendExtraUpdates(['#col1']);
                $this->redirectNow(Links::eventRule($ruleId));
            })->on(SaveEventRuleForm::ON_REMOVE, function ($form) use ($ruleId) {
                $form->removeRule($ruleId);
                $this->sessionNamespace->delete($ruleId);

                Notification::success($this->translate('Successfully removed rule.'));
                $this->redirectNow('__CLOSE__');
            })->handleRequest($this->getServerRequest());

        $eventRuleForm = (new EventRuleForm())
            ->populate($cache ?? $this->fromDb($ruleId))
            ->on(Form::ON_SENT, function ($form) use ($ruleId, $eventRuleConfig, $saveForm) {
                $config = $eventRuleConfig->getConfig();
                $config['name'] = $form->getValue('name');
                $config['is_active'] = $form->getValue('is_active');

                $eventRuleConfig->setConfig($config);

                $this->sessionNamespace->set($ruleId, $eventRuleConfig->getConfig());
                $saveForm->setSubmitButtonDisabled(false);
            })->handleRequest($this->getServerRequest());

        $eventRuleFormAndSave = Html::tag('div', ['class' => 'event-rule-and-save-forms']);
        $eventRuleFormAndSave->add([
            $eventRuleForm,
            $saveForm
        ]);

        $eventRuleConfig
            ->on(EventRuleConfig::ON_CHANGE, function ($eventRuleConfig) use ($ruleId, $saveForm) {
                $this->sessionNamespace->set($ruleId, $eventRuleConfig->getConfig());
                $saveForm->setSubmitButtonDisabled(false);
                $this->redirectNow(Links::eventRule($ruleId));
            });

        foreach ($eventRuleConfig->getForms() as $form) {
            $form->handleRequest($this->getServerRequest());

            if (! $form->hasBeenSent()) {
                // Force validation of populated values in case we display an unsaved rule
                $form->validatePartial();
            }
        }

        $this->addControl($eventRuleFormAndSave);
        $this->addContent($eventRuleConfig);
    }

    /**
     * Create config from db
     *
     * @param int $ruleId
     * @return array
     */
    public function fromDb(int $ruleId): array
    {
        $query = Rule::on(Database::get())
            ->withoutColumns('timeperiod_id')
            ->filter(Filter::equal('id', $ruleId));

        $rule = $query->first();
        if ($rule === null) {
            $this->httpNotFound(t('Rule not found'));
        }

        $config = iterator_to_array($rule);

        foreach ($rule->rule_escalation as $re) {
            foreach ($re as $k => $v) {
                $config[$re->getTableName()][$re->position][$k] = $v;
            }

            foreach ($re->rule_escalation_recipient as $recipient) {
                $config[$re->getTableName()][$re->position]['recipient'][] = iterator_to_array($recipient);
            }
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
        $cache = $this->sessionNamespace->get($ruleId);

        $editor = EventRuleConfig::createSearchEditor(ObjectExtraTag::on(Database::get()))
            ->setQueryString($cache['object_filter'] ?? '');

        $editor->on(SearchEditor::ON_SUCCESS, function (SearchEditor $form) use ($ruleId) {
            $cache = $this->sessionNamespace->get($ruleId);
            $cache['object_filter'] = EventRuleConfig::createFilterString($form->getFilter());

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
