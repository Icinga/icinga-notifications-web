<?php

namespace Icinga\Module\Noma\Forms;

use Icinga\Module\Noma\Common\Database;
use Icinga\Module\Noma\Model\RuleEscalation;
use Icinga\Web\Session;
use ipl\Html\Form;
use ipl\Html\HtmlDocument;
use ipl\I18n\Translation;
use ipl\Stdlib\Filter;
use ipl\Web\Common\CsrfCounterMeasure;
use ipl\Web\Common\FormUid;

class SaveEventRuleForm extends Form
{
    use CsrfCounterMeasure;
    use FormUid;
    use Translation;

    /** @var string Emitted in case the rule should be deleted */
    const ON_REMOVE = 'on_remove';

    protected $defaultAttributes = [
        'class' => 'icinga-controls',
        'name'  => 'save-event-rule'
    ];

    /** @var bool Whether to disable the submit button */
    protected $disableSubmitButton = false;

    /** @var string The label to use on the submit button */
    protected $submitLabel;

    /** @var bool Whether to show a button to delete the rule */
    protected $showRemoveButton = false;

    /**
     * Create a new SaveEventRuleForm
     */
    public function __construct()
    {
        $this->on(self::ON_SENT, function () {
            if ($this->hasBeenRemoved()) {
                $this->emit(self::ON_REMOVE, [$this]);
            }
        });
    }

    /**
     * Set whether to enable or disable the submit button
     *
     * @param bool $state
     *
     * @return $this
     */
    public function setSubmitButtonDisabled(bool $state = true): self
    {
        $this->disableSubmitButton = $state;

        return $this;
    }

    /**
     * Set the submit label
     *
     * @param string $label
     *
     * @return $this
     */
    public function setSubmitLabel(string $label): self
    {
        $this->submitLabel = $label;

        return $this;
    }

    /**
     * Get the submit label
     *
     * @return string
     */
    public function getSubmitLabel(): string
    {
        return $this->submitLabel ?? t('Add Event Rule');
    }

    /**
     * Set whether to show a button to delete the rule
     *
     * @param bool $state
     *
     * @return $this
     */
    public function setShowRemoveButton(bool $state = true): self
    {
        $this->showRemoveButton = $state;

        return $this;
    }

    /**
     * Get whether the user pushed the remove button
     *
     * @return bool
     */
    private function hasBeenRemoved(): bool
    {
        $btn = $this->getPressedSubmitElement();
        $csrf = $this->getElement('CSRFToken');

        return $csrf !== null && $csrf->isValid() && $btn !== null && $btn->getName() === 'remove';
    }

    public function isValidEvent($event)
    {
        if ($event === self::ON_REMOVE) {
            return true;
        }

        return parent::isValidEvent($event);
    }

    protected function assemble()
    {
        $this->addElement($this->createUidElement());
        $this->addElement($this->createCsrfCounterMeasure(Session::getSession()->getId()));

        $this->addElement('submit', 'submit', [
            'label' => $this->getSubmitLabel()
        ]);

        $this->getElement('submit')
            ->getAttributes()
            ->registerAttributeCallback('disabled', function () {
                return $this->disableSubmitButton;
            });

        $additionalButtons = [];
        if ($this->showRemoveButton) {
            $removeBtn = $this->createElement('submit', 'remove', [
                'label' => $this->translate('Remove'),
                'class' => 'btn-remove',
                'formnovalidate' => true
            ]);
            $this->registerElement($removeBtn);
            $additionalButtons[] = $removeBtn;
        }

        $this->getElement('submit')->prependWrapper((new HtmlDocument())->setHtmlContent(...$additionalButtons));
    }

    /**
     * Add a new event rule with the given configuration
     *
     * @param array $config
     *
     * @return int The id of the new event rule
     */
    public function addRule(array $config): int
    {
        $db = Database::get();

        $db->beginTransaction();

        $db->insert('rule', [
            'name' => $config['name'],
            'timeperiod_id' => $config['timeperiod_id'] ?? null,
            'object_filter' => $config['object_filter'] ?? null,
            'is_active' => $config['is_active'] ?? 'n'
        ]);
        $ruleId = $db->lastInsertId();

        foreach ($config['rule_escalation'] ?? [] as $position => $escalationConfig) {
            $db->insert('rule_escalation', [
                'rule_id' => $ruleId,
                'position' => $position,
                'condition' => $escalationConfig['condition'] ?? null,
                'name' => $escalationConfig['name'] ?? null,
                'fallback_for' => $escalationConfig['fallback_for'] ?? null
            ]);
            $escalationId = $db->lastInsertId();

            foreach ($escalationConfig['recipient'] ?? [] as $recipientConfig) {
                $data = [
                    'rule_escalation_id' => $escalationId,
                    'channel_type' => $recipientConfig['channel_type']
                ];

                switch (true) {
                    case isset($recipientConfig['contact_id']):
                        $data['contact_id'] = $recipientConfig['contact_id'];
                        break;
                    case isset($recipientConfig['contactgroup_id']):
                        $data['contactgroup_id'] = $recipientConfig['contactgroup_id'];
                        break;
                    case isset($recipientConfig['schedule_id']):
                        $data['schedule_id'] = $recipientConfig['schedule_id'];
                        break;
                }

                $db->insert('rule_escalation_recipient', $data);
            }
        }

        $db->commitTransaction();

        return $ruleId;
    }

    /**
     * Edit an existing event rule
     *
     * @param int $id The id of the event rule
     * @param array $config The new configuration
     *
     * @return void
     */
    public function editRule(int $id, array $config): void
    {
        $db = Database::get();

        $db->beginTransaction();

        $db->update('rule', [
            'name' => $config['name'],
            'timeperiod_id' => $config['timeperiod_id'] ?? null,
            'object_filter' => $config['object_filter'] ?? null,
            'is_active' => $config['is_active'] ?? 'n'
        ], ['id = ?' => $id]);

        $escalations = RuleEscalation::on($db)
            ->columns('id')
            ->filter(Filter::equal('rule_id', $id));

        $escalationsToRemove = [];
        foreach ($escalations as $escalation) {
            $escalationsToRemove[] = $escalation->id;
        }

        // TODO: Update existing rows instead
        if (! empty($escalationsToRemove)) {
            $db->delete('rule_escalation_recipient', ['rule_escalation_id IN (?)' => $escalationsToRemove]);
        }

        $db->delete('rule_escalation', ['rule_id = ?' => $id]);

        foreach ($config['rule_escalation'] ?? [] as $position => $escalationConfig) {
            $db->insert('rule_escalation', [
                'rule_id' => $id,
                'position' => $position,
                'condition' => $escalationConfig['condition'] ?? null,
                'name' => $escalationConfig['name'] ?? null,
                'fallback_for' => $escalationConfig['fallback_for'] ?? null
            ]);
            $escalationId = $db->lastInsertId();

            foreach ($escalationConfig['recipient'] ?? [] as $recipientConfig) {
                $data = [
                    'rule_escalation_id' => $escalationId,
                    'channel_type' => $recipientConfig['channel_type']
                ];

                switch (true) {
                    case isset($recipientConfig['contact_id']):
                        $data['contact_id'] = $recipientConfig['contact_id'];
                        break;
                    case isset($recipientConfig['contactgroup_id']):
                        $data['contactgroup_id'] = $recipientConfig['contactgroup_id'];
                        break;
                    case isset($recipientConfig['schedule_id']):
                        $data['schedule_id'] = $recipientConfig['schedule_id'];
                        break;
                }

                $db->insert('rule_escalation_recipient', $data);
            }
        }

        $db->commitTransaction();
    }

    /**
     * Remove the given event rule
     *
     * @param int $id
     *
     * @return void
     */
    public function removeRule(int $id): void
    {
        $db = Database::get();

        $db->beginTransaction();

        $escalations = RuleEscalation::on($db)
            ->columns('id')
            ->filter(Filter::equal('rule_id', $id));

        $escalationsToRemove = [];
        foreach ($escalations as $escalation) {
            $escalationsToRemove[] = $escalation->id;
        }

        if (! empty($escalationsToRemove)) {
            $db->delete('rule_escalation_recipient', ['rule_escalation_id IN (?)' => $escalationsToRemove]);
        }

        $db->delete('rule_escalation', ['rule_id = ?' => $id]);
        $db->delete('rule', ['id = ?' => $id]);

        $db->commitTransaction();
    }
}
