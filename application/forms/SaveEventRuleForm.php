<?php

namespace Icinga\Module\Noma\Forms;

use Icinga\Module\Noma\Common\Database;
use Icinga\Module\Noma\Model\RuleEscalation;
use Icinga\Module\Noma\Model\RuleEscalationRecipient;
use Icinga\Web\Session;
use ipl\Html\Form;
use ipl\Html\HtmlDocument;
use ipl\I18n\Translation;
use ipl\Sql\Connection;
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
        'class' => ['icinga-controls', 'save-event-rule'],
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

    public function hasBeenSubmitted(): bool
    {
        return $this->hasBeenSent() && $this->getPressedSubmitElement() !== null;
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
                'label' => $this->translate('Delete Event Rule'),
                'class' => 'btn-remove',
                'formnovalidate' => true
            ]);
            $this->registerElement($removeBtn);
            $additionalButtons[] = $removeBtn;
        }

        if (! $this->disableSubmitButton) {
            $clearCacheBtn = $this->createElement('submit', 'discard_changes', [
                'label' => $this->translate('Discard Changes'),
                'class' => 'btn-discard-changes',
                'formnovalidate' => true
            ]);
            $this->registerElement($clearCacheBtn);
            $additionalButtons[] = $clearCacheBtn;
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
     * Insert to or update Escalations and its recipients in Db
     *
     * @param $ruleId
     * @param array $escalations
     * @param Connection $db
     * @param bool $insert
     *
     * @return void
     */
    private function insertOrUpdateEscalations($ruleId, array $escalations, Connection $db, bool $insert = false): void
    {
        foreach ($escalations as $position => $escalationConfig) {
            if ($insert) {
                $db->insert('rule_escalation', [
                    'rule_id' => $ruleId,
                    'position' => $position,
                    'condition' => $escalationConfig['condition'] ?? null,
                    'name' => $escalationConfig['name'] ?? null,
                    'fallback_for' => $escalationConfig['fallback_for'] ?? null
                ]);

                $escalationId = $db->lastInsertId();
            } else {
                $escalationId = $escalationConfig['id'];

                $db->update('rule_escalation', [
                    'position' => $position,
                    'condition' => $escalationConfig['condition'] ?? null,
                    'name' => $escalationConfig['name'] ?? null,
                    'fallback_for' => $escalationConfig['fallback_for'] ?? null
                ], ['id = ?' => $escalationId, 'rule_id = ?' => $ruleId]);
                $recipientsToRemove = [];

                $recipients = RuleEscalationRecipient::on($db)
                    ->columns('id')
                    ->filter(Filter::equal('rule_escalation_id', $escalationId));

                foreach ($recipients as $recipient) {
                    $recipientId = $recipient->id;
                    $recipientInCache = array_filter(
                        $escalationConfig['recipient'],
                        function (array $element) use ($recipientId) {
                            return (int) $element['id'] === $recipientId;
                        }
                    );

                    if (empty($recipientInCache)) {
                        // Recipients to remove from Db not in cache
                        $recipientsToRemove[] = $recipientId;
                    }
                }

                if (! empty($recipientsToRemove)) {
                    $db->delete('rule_escalation_recipient', ['id IN (?)' => $recipientsToRemove]);
                }
            }

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

                if (! isset($recipientConfig['id'])) {
                    $db->insert('rule_escalation_recipient', $data);
                } else {
                    $db->update('rule_escalation_recipient', $data, ['id = ?' => $recipientConfig['id']]);
                }
            }
        }
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

        $escalationsFromDb = RuleEscalation::on($db)
            ->filter(Filter::equal('rule_id', $id));

        $escalationsInCache = $config['rule_escalation'];

        $escalationsToUpdate = [];
        $escalationsToRemove = [];

        foreach ($escalationsFromDb as $escalationInDB) {
            $escalationId = $escalationInDB->id;
            $escalationInCache = array_filter($escalationsInCache, function (array $element) use ($escalationId) {
                return (int) $element['id'] === $escalationId;
            });

            if ($escalationInCache) {
                $position = array_key_first($escalationInCache);
                // Escalations in DB to update
                $escalationsToUpdate[$position] = $escalationInCache[$position];
                unset($escalationsInCache[$position]);
            } else {
                // Escalation in DB to remove
                $escalationsToRemove[] = $escalationId;
            }
        }

        // Escalations to add
        $escalationsToAdd = $escalationsInCache;

        if (! empty($escalationsToRemove)) {
            $db->delete('rule_escalation_recipient', ['rule_escalation_id IN (?)' => $escalationsToRemove]);
            $db->delete('rule_escalation', ['id IN (?)' => $escalationsToRemove]);
        }

        if (! empty($escalationsToAdd)) {
            $this->insertOrUpdateEscalations($id, $escalationsToAdd, $db, true);
        }

        if (! empty($escalationsToUpdate)) {
            $this->insertOrUpdateEscalations($id, $escalationsToUpdate, $db);
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
