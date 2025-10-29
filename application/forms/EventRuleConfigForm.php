<?php

/* Icinga Notifications Web | (c) 2025 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Forms;

use DateTime;
use Icinga\Module\Notifications\Forms\EventRuleConfigElements\ConfigProviderInterface;
use Icinga\Module\Notifications\Forms\EventRuleConfigElements\Escalation;
use Icinga\Module\Notifications\Forms\EventRuleConfigElements\EscalationRecipient;
use Icinga\Module\Notifications\Model\Rule;
use Icinga\Module\Notifications\Model\RuleEscalation;
use ipl\Html\Attributes;
use ipl\Html\Form;
use ipl\Html\FormElement\SubmitButtonElement;
use ipl\Html\HtmlElement;
use ipl\Html\ValidHtml;
use ipl\I18n\Translation;
use ipl\Sql\Connection;
use ipl\Web\Common\CsrfCounterMeasure;
use ipl\Web\Url;
use ipl\Web\Widget\Icon;
use ipl\Web\Widget\Link;

class EventRuleConfigForm extends Form
{
    use CsrfCounterMeasure;
    use Translation;

    protected $defaultAttributes = [
        'class' => ['event-rule-config', 'icinga-controls'],
        'name'  => 'event-rule-config-form',
        'id'    => 'event-rule-config-form'
    ];

    /** @var ConfigProviderInterface */
    protected ConfigProviderInterface $configProvider;

    /** @var Url Search editor URL for the config filter fieldset */
    protected Url $searchEditorUrl;

    /**
     * Create a new EventRuleConfigForm
     *
     * @param ConfigProviderInterface $configProvider
     * @param Url $searchEditorUrl
     */
    public function __construct(ConfigProviderInterface $configProvider, Url $searchEditorUrl)
    {
        $this->configProvider = $configProvider;
        $this->searchEditorUrl = $searchEditorUrl;
    }

    public function hasBeenSubmitted(): bool
    {
        $pressedButton = $this->getPressedSubmitElement();

        if ($pressedButton && $pressedButton->getName() === 'save') {
            return true;
        }

        return false;
    }

    protected function assemble(): void
    {
        $this->addCsrfCounterMeasure();

        // Replicate the save button outside the form
        $this->addElement(
            'submitButton',
            'save',
            [
                'hidden' => true,
                'class'  => 'primary-submit-btn-duplicate'
            ]
        );

        // Replicate the delete button outside the form
        $this->addElement(
            'submitButton',
            'delete',
            [
                'hidden' => true,
                'class'  => 'primary-submit-btn-duplicate'
            ]
        );

        $this->addHtml(
            new HtmlElement('div', Attributes::create(['class' => 'connector-line'])),
            new HtmlElement(
                'div',
                Attributes::create(['id' => 'object-filter-controls']),
                $this->createObjectFilterControls()
            ),
            new HtmlElement('div', Attributes::create(['class' => 'connector-line']))
        );

        $escalations = new EventRuleConfigElements\Escalations('escalations', [
            'provider' => $this->configProvider,
            'required' => true
        ]);
        $this->addElement($escalations);

        $this->addElement('hidden', 'id', ['required' => true]);

        $name = $this->createElement('hidden', 'name', ['required' => true]);
        $this->registerElement($name);
        $source = $this->createElement('hidden', 'source', ['required' => true]);
        $this->registerElement($source);

        $this->addHtml(new HtmlElement(
            'div',
            Attributes::create(['id' => 'event-rule-config-form-name', 'hidden' => true]),
            $name,
            $source
        ));
    }

    /**
     * Create and return the controls to configure the object filter
     *
     * @return ValidHtml
     */
    protected function createObjectFilterControls(): ValidHtml
    {
        $hiddenInput = $this->createElement('hidden', 'object_filter');
        $this->registerElement($hiddenInput);

        if ($hiddenInput->hasValue()) {
            $label = new Icon('filter');
            $title = $this->translate('Adjust Filter');
        } else {
            $label = new Icon('plus');
            $title = $this->translate('Add filter');
        }

        return new HtmlElement(
            'div',
            Attributes::create(['class' => 'button-wrapper']),
            new Link(
                $label,
                $this->searchEditorUrl,
                Attributes::create([
                    'class'               => ['search-editor-opener', 'filter-button'],
                    'title'               => $title,
                    'data-icinga-modal'   => true,
                    'data-no-icinga-ajax' => true
                ])
            ),
            $hiddenInput
        );
    }

    /**
     * Get the element to update in case the config of the rule is changed
     *
     * @param string $newName
     * @param int $newSource
     *
     * @return ValidHtml
     */
    public function prepareConfigUpdate(string $newName, int $newSource): ValidHtml
    {
        return new HtmlElement(
            'div',
            Attributes::create(['id' => 'event-rule-config-form-name']),
            $this->createElement('hidden', 'name', ['required' => true, 'value' => $newName]),
            $this->createElement('hidden', 'source', ['required' => true, 'value' => $newSource])
        );
    }

    /**
     * Get the element to update in case the object filter of the rule is changed
     *
     * @param string $newFilter
     *
     * @return ValidHtml
     */
    public function prepareObjectFilterUpdate(string $newFilter): ValidHtml
    {
        $this->populate(['object_filter' => $newFilter]);

        return new HtmlElement(
            'div',
            Attributes::create(['id' => 'object-filter-controls']),
            $this->createObjectFilterControls()
        );
    }

    /**
     * Create and return the submit-buttons for the form
     *
     * @return SubmitButtonElement[]
     */
    public function createExternalSubmitButtons(): array
    {
        $buttons = [];

        if ((int) $this->getValue('id') !== -1) {
            $buttons[] = $this->createElement('submitButton', 'delete', [
                'label' => $this->translate('Delete'),
                'data-progress-label' => $this->translate('Deleting rule'),
                'form' => 'event-rule-config-form',
                'class' => 'btn-remove',
                'formnovalidate' => true
            ]);
        }

        $buttons[] = $this->createElement('submitButton', 'save', [
            'data-progress-label' => $this->translate('Saving rule'),
            'label' => $this->translate('Save'),
            'form' => 'event-rule-config-form'
        ]);

        return $buttons;
    }

    /**
     * Load the given event rule into the form
     *
     * @param Rule $rule
     *
     * @return void
     */
    public function load(Rule $rule): void
    {
        $fields = [
            'id'                => $rule->id,
            'name'              => $rule->name,
            'source'            => $rule->source_id,
            'object_filter'     => $rule->object_filter
        ];

        $escalations = $rule->rule_escalation->orderBy('position', 'asc')->execute();
        if ($escalations->hasResult()) {
            $fields['escalations'] = EventRuleConfigElements\Escalations::prepare(
                $escalations
            );
        }

        $this->populate($fields);
    }

    /**
     * Check whether the name or object filter changed according to the given previous rule
     *
     * @param Rule $previousRule
     *
     * @return bool
     */
    protected function hasChanged(Rule $previousRule): bool
    {
        if ($previousRule->name !== $this->getValue('name')) {
            return true;
        }

        if ($previousRule->source_id !== (int) $this->getValue('source')) {
            return true;
        }

        if ($previousRule->object_filter !== $this->getValue('object_filter')) {
            return true;
        }

        return false;
    }

    /**
     * Insert to or update event rule in the database and return the id of the event rule
     *
     * @param Connection $db
     * @param ?Rule $previousRule
     *
     * @return int
     */
    public function storeInDatabase(Connection $db, ?Rule $previousRule): int
    {
        $db->beginTransaction();

        $ruleId = (int) $this->getValue('id');
        if ($previousRule === null) {
            $db->insert('rule', [
                'name'          => $this->getValue('name'),
                'source_id'     => $this->getValue('source'),
                'timeperiod_id' => null,
                'object_filter' => $this->getValue('object_filter'),
                'changed_at'    => (int) (new DateTime())->format("Uv"),
                'deleted'       => 'n'
            ]);

            $ruleId = (int) $db->lastInsertId();
        } elseif ($this->hasChanged($previousRule)) {
            $db->update('rule', [
                'name'          => $this->getValue('name'),
                'source_id'     => $this->getValue('source'),
                'object_filter' => $this->getValue('object_filter'),
                'changed_at'    => (int) (new DateTime())->format("Uv")
            ], ['id = ?' => $ruleId]);
        }

        $escalationsFromDb = [];
        foreach ($previousRule?->rule_escalation ?? [] as $escalationFromDb) {
            /** @var RuleEscalation $escalationFromDb */
            $escalationsFromDb[$escalationFromDb->id] = $escalationFromDb;
        }

        $recipients = [];
        foreach ($this->getElement('escalations')->getEscalations() as $escalation) {
            /** @var Escalation $escalation */
            $config = $escalation->getEscalation();
            if ($config['id'] === null) {
                $db->insert('rule_escalation', [
                    'rule_id' => $ruleId,
                    'position' => $config['position'],
                    $db->quoteIdentifier('condition') => $config['condition'],
                    'name' => null,
                    'fallback_for' => null,
                    'changed_at' => (int) (new DateTime())->format("Uv"),
                    'deleted' => 'n'
                ]);

                $recipients[(int) $db->lastInsertId()] = [$escalation->getRecipients(), []];
            } else {
                $escalationFromDb = $escalationsFromDb[$config['id']];

                $recipientsFromDb = [];
                foreach ($escalationFromDb->rule_escalation_recipient as $recipientFromDb) {
                    $recipientsFromDb[$recipientFromDb->id] = $recipientFromDb;
                }

                $recipients[(int) $config['id']] = [$escalation->getRecipients(), $recipientsFromDb];

                if ($escalation->hasChanged($escalationFromDb)) {
                    $db->update('rule_escalation', [
                        'position' => $config['position'],
                        $db->quoteIdentifier('condition') => $config['condition'],
                        'changed_at' => (int) (new DateTime())->format("Uv")
                    ], ['id = ?' => $config['id'], 'rule_id = ?' => $ruleId]);
                }

                unset($escalationsFromDb[$config['id']]);
            }
        }

        // What's left must be removed
        $escalationsToRemove = array_keys($escalationsFromDb);
        if (! empty($escalationsToRemove)) {
            $db->update('rule_escalation_recipient', [
                'changed_at' => (int) (new DateTime())->format("Uv"),
                'deleted'    => 'y'
            ], ['rule_escalation_id IN (?)' => $escalationsToRemove, 'deleted = ?' => 'n']);
            $db->update('rule_escalation', [
                'changed_at' => (int) (new DateTime())->format("Uv"),
                'position'   => null,
                'deleted'    => 'y'
            ], ['id IN (?)' => $escalationsToRemove]);
        }

        foreach ($recipients as $escalationId => [$escalationRecipients, $recipientsFromDb]) {
            foreach ($escalationRecipients as $escalationRecipient) {
                /** @var EscalationRecipient $escalationRecipient */
                $config = $escalationRecipient->getRecipient();
                if ($config['id'] === null) {
                    unset($config['id']);
                    $db->insert('rule_escalation_recipient', $config + [
                        'rule_escalation_id' => $escalationId,
                        'contact_id' => null,
                        'contactgroup_id' => null,
                        'schedule_id' => null,
                        'changed_at' => (int) (new DateTime())->format("Uv"),
                        'deleted'    => 'n'
                    ]);
                } else {
                    if ($escalationRecipient->hasChanged($recipientsFromDb[$config['id']])) {
                        $db->update('rule_escalation_recipient', $config + [
                            'changed_at' => (int) (new DateTime())->format("Uv"),
                            // Ensure unused fields are reset to null
                            'contact_id' => null,
                            'contactgroup_id' => null,
                            'schedule_id' => null
                        ], ['id = ?' => $config['id']]);
                    }

                    unset($recipientsFromDb[$config['id']]);
                }
            }

            $recipientsToRemove = array_keys($recipientsFromDb);
            if (! empty($recipientsToRemove)) {
                $db->update('rule_escalation_recipient', [
                    'changed_at' => (int) (new DateTime())->format("Uv"),
                    'deleted'    => 'y'
                ], ['id IN (?)' => $recipientsToRemove, 'deleted = ?' => 'n']);
            }
        }

        $db->commitTransaction();

        return $ruleId;
    }

    /**
     * Get whether the delete button was pressed
     *
     * @return bool
     */
    public function hasBeenRemoved(): bool
    {
        $btn = $this->getPressedSubmitElement();
        $csrf = $this->getElement('CSRFToken');

        return $csrf->isValid() && $btn !== null && $btn->getName() === 'delete';
    }

    /**
     * Remove the given event rule
     *
     * @param Connection $db
     * @param Rule $rule
     *
     * @return void
     */
    public static function removeRule(Connection $db, Rule $rule): void
    {
        $escalationsToRemove = [];
        /** @var RuleEscalation $escalation */
        foreach ($rule->rule_escalation as $escalation) {
            $escalationsToRemove[] = $escalation->id;
        }

        if (! empty($escalationsToRemove)) {
            $db->update('rule_escalation_recipient', [
                'changed_at' => (int) (new DateTime())->format("Uv"),
                'deleted'    => 'y'
            ], ['rule_escalation_id IN (?)' => $escalationsToRemove, 'deleted = ?' => 'n']);
        }

        $db->update('rule_escalation', [
            'changed_at' => (int) (new DateTime())->format("Uv"),
            'position'   => null,
            'deleted'    => 'y'
        ], ['rule_id = ?' => $rule->id]);
        $db->update('rule', [
            'changed_at' => (int) (new DateTime())->format("Uv"),
            'deleted'    => 'y'
        ], ['id = ?' => $rule->id]);
    }
}
