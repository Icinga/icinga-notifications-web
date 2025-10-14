<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Controllers;

use Icinga\Exception\Http\HttpNotFoundException;
use Icinga\Module\Notifications\Common\Auth;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Common\Links;
use Icinga\Module\Notifications\Forms\EventRuleConfigElements\NotificationConfigProvider;
use Icinga\Module\Notifications\Forms\EventRuleConfigForm;
use Icinga\Module\Notifications\Forms\EventRuleForm;
use Icinga\Module\Notifications\Model\Rule;
use Icinga\Module\Notifications\Model\Source;
use Icinga\Module\Notifications\Web\Control\SearchBar\ExtraTagSuggestions;
use Icinga\Web\Notification;
use Icinga\Web\Session;
use ipl\Html\Contract\Form;
use ipl\Html\Html;
use ipl\Stdlib\Filter;
use ipl\Web\Compat\CompatController;
use ipl\Web\Control\SearchEditor;
use ipl\Web\Filter\Renderer;
use ipl\Web\Url;
use ipl\Web\Widget\Icon;
use ipl\Web\Widget\Link;
use Psr\Http\Message\ServerRequestInterface;

class EventRuleController extends CompatController
{
    use Auth;

    /** @var Session\SessionNamespace */
    private Session\SessionNamespace $session;

    public function init(): void
    {
        $this->assertPermission('notifications/config/event-rule');
        $this->session = Session::getSession()->getNamespace('notifications.event-rule');
    }

    public function indexAction(): void
    {
        $this->addTitleTab($this->translate('Event Rule'));
        $this->controls->addAttributes(['class' => 'event-rule-detail']);
        $this->content->addAttributes(['class' => 'event-rule-detail']);
        $this->getTabs()->disableLegacyExtensions();

        $ruleId = (int) $this->params->getRequired('id');

        $multiPartUpdate = false;
        $eventRuleConfig = (new EventRuleConfigForm(
            new NotificationConfigProvider(),
            Url::fromPath('notifications/event-rule/search-editor', ['id' => $ruleId])
        ))->setCsrfCounterMeasureId(Session::getSession()->getId());

        $eventRuleConfig
            ->on(Form::ON_SUBMIT, function (EventRuleConfigForm $form) use ($ruleId) {
                if ($ruleId !== -1) {
                    $rule = $this->fetchRule($ruleId);
                } else {
                    $rule = null;
                }

                $ruleId = $form->storeInDatabase(Database::get(), $rule);
                Notification::success(sprintf(
                    $this->translate('Successfully saved event rule %s'),
                    $form->getValue('name')
                ));
                $this->sendExtraUpdates(['#col1']);
                $this->redirectNow(Links::eventRule($ruleId));
            })
            ->on(Form::ON_SENT, function (EventRuleConfigForm $form) use ($ruleId) {
                if ($form->hasBeenRemoved()) {
                    Database::get()->transaction(
                        fn() => $form::removeRule(Database::get(), $this->fetchRule($ruleId))
                    );
                    Notification::success(sprintf(
                        $this->translate('Successfully deleted event rule %s'),
                        $form->getValue('name')
                    ));
                    $this->switchToSingleColumnLayout();
                }
            })
            ->on(Form::ON_REQUEST, function (
                ServerRequestInterface $request,
                EventRuleConfigForm $form
            ) use (
                $ruleId,
                &$multiPartUpdate
            ) {
                $nameOnly = (bool) $this->params->shift('_nameOnly');
                $filterOnly = (bool) $this->params->shift('_filterOnly');

                if ($nameOnly || $filterOnly) {
                    $multiPartUpdate = true;

                    if ($nameOnly) {
                        $this->addPart($form->prepareObjectFilterUpdate($this->session->get('object_filter')));
                        $this->addPart($form->prepareConfigUpdate(
                            $this->session->get('name'),
                            $this->session->get('source')
                        ));
                        $this->addPart(Html::tag('div', ['id' => 'event-rule-config-name'], [
                            Html::tag('h2', $this->session->get('name')),
                            (new Link(
                                new Icon('edit'),
                                Url::fromPath('notifications/event-rule/edit', ['id' => $ruleId]),
                                ['class' => 'control-button']
                            ))->openInModal()
                        ]));
                    } else {
                        $this->addPart($form->prepareConfigUpdate(
                            $this->session->get('name'),
                            $this->session->get('source')
                        ));
                        $this->addPart($form->prepareObjectFilterUpdate($this->session->get('object_filter')));
                    }

                    $this->getResponse()->setHeader('X-Icinga-Location-Query', $this->params->toString());
                } elseif ($ruleId !== -1) {
                    $rule = $this->fetchRule($ruleId);

                    $form->load($rule);

                    $this->session->set('name', $rule->name);
                    $this->session->set('source', $rule->source_id);
                    $this->session->set('object_filter', $rule->object_filter ?? '');
                } else {
                    $name = $this->params->getRequired('name');
                    $source = $this->params->getRequired('source');
                    $form->populate(['id' => $ruleId, 'name' => $name, 'source' => $source]);

                    $this->session->set('name', $name);
                    $this->session->set('source', $source);
                    $this->session->set('object_filter', '');
                }
            })
            ->handleRequest($this->getServerRequest());

        if ($multiPartUpdate) {
            return;
        }

        $this->addControl(Html::tag('div', ['class' => 'event-rule-and-save-forms'], [
            Html::tag('div', ['class' => 'event-rule-form', 'id' => 'event-rule-config-name'], [
                Html::tag('h2', $eventRuleConfig->getValue('name')),
                (new Link(
                    new Icon('edit'),
                    Url::fromPath('notifications/event-rule/edit', ['id' => $ruleId]),
                    ['class' => 'control-button']
                ))->openInModal()
            ]),
            Html::tag(
                'div',
                ['id' => 'save-config', 'class' => 'icinga-controls'],
                $eventRuleConfig->createExternalSubmitButtons()
            )
        ]));

        $this->addContent($eventRuleConfig);
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
        $ruleId = (int) $this->params->getRequired('id');

        $editor = new SearchEditor();

        $editor->setQueryString($this->session->get('object_filter'))
            ->setAction(Url::fromRequest()->getAbsoluteUrl())
            ->setSuggestionUrl(
                Url::fromPath('notifications/event-rule/complete', [
                    'id' => $ruleId,
                    '_disableLayout' => true,
                    'showCompact' => true
                ])
            );

        $editor->on(Form::ON_SUBMIT, function (SearchEditor $form) use ($ruleId) {
            $filter = (new Renderer($form->getFilter()))->render();
            // TODO: Should not be needed for the new filter implementation
            $filter = preg_replace('/(?:=|~|!|%3[EC])(?=[|&]|$)/', '', $filter);

            $this->session->set('object_filter', $filter);
            $this->redirectNow(Links::eventRule($ruleId)->setParam('_filterOnly'));
        });

        $editor->handleRequest($this->getServerRequest());

        $this->getDocument()->addHtml($editor);
        $this->setTitle($this->translate('Adjust Filter'));
    }

    public function editAction(): void
    {
        $ruleId = (int) $this->params->getRequired('id');

        $eventRuleForm = (new EventRuleForm())
            ->setCsrfCounterMeasureId(Session::getSession()->getId())
            ->setAvailableSources(
                Database::get()->fetchPairs(
                    Source::on(Database::get())->columns(['id', 'name'])->assembleSelect()
                )
            )
            ->populate([
                'name' => $this->session->get('name'),
                'source' => $this->session->get('source')
            ])
            ->setAction(Url::fromRequest()->getAbsoluteUrl())
            ->on(Form::ON_SUBMIT, function ($form) use ($ruleId) {
                $this->session->set('name', $form->getValue('name'));

                $newSource = $form->getValue('source');
                if ($newSource !== $this->session->get('source')) {
                    $this->session->set('source', $newSource);
                    $this->session->set('object_filter', '');
                }

                $this->redirectNow(Links::eventRule($ruleId)->setParam('_nameOnly'));
            })->handleRequest($this->getServerRequest());

        $this->setTitle($this->translate('Edit Event Rule'));

        $this->addContent($eventRuleForm);
    }

    /**
     * Fetch the rule with the given ID
     *
     * @param int $ruleId
     *
     * @return Rule
     * @throws HttpNotFoundException
     */
    private function fetchRule(int $ruleId): Rule
    {
        $query = Rule::on(Database::get())
            ->filter(Filter::equal('id', $ruleId));

        /* @var ?Rule $rule */
        $rule = $query->first();
        if ($rule === null) {
            $this->httpNotFound(t('Rule not found'));
        }

        return $rule;
    }
}
