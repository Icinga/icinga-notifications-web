<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Forms;

use Exception;
use Icinga\Exception\Http\HttpNotFoundException;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Model\Rule;
use Icinga\Module\Notifications\Model\RuleEscalation;
use Icinga\Module\Notifications\Model\RuleEscalationRecipient;
use Icinga\Web\Notification;
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
    public const ON_REMOVE = 'on_remove';

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

    /** @var bool Whether to show a button to dismiss cached changes */
    protected $showDismissChangesButton = false;

    /** @var bool Whether to disable the remove button */
    protected $disableRemoveButton = false;

    /** @var int The rule id */
    protected $ruleId;

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
     * Set whether to enable or disable the remove button
     *
     * @param bool $state
     *
     * @return $this
     */
    public function setRemoveButtonDisabled(bool $state = true): self
    {
        $this->disableRemoveButton = $state;

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
     * Set whether to show a button to dismiss cached changes
     *
     * @param bool $state
     *
     * @return $this
     */
    public function setShowDismissChangesButton(bool $state = true): self
    {
        $this->showDismissChangesButton = $state;

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
            'label' => $this->getSubmitLabel(),
            'class' => 'btn-primary'
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

            $this->getElement('remove')
                ->getAttributes()
                ->registerAttributeCallback('disabled', function () {
                    return $this->disableRemoveButton;
                })
                ->registerAttributeCallback('title', function () {
                    if ($this->disableRemoveButton) {
                        return $this->translate(
                            'There exist active incidents for this event rule and hence cannot be deleted'
                        );
                    }
                });

            $additionalButtons[] = $removeBtn;
        }

        if ($this->showDismissChangesButton) {
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
        if (! isset($config['name'])) {
            throw new Exception('Name of the event rule is not set');
        }

        $db = Database::get();

        $db->beginTransaction();

        $db->insert('rule', [
            'name' => $config['name'],
            'timeperiod_id' => $config['timeperiod_id'] ?? null,
            'object_filter' => $config['object_filter'] ?? null
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
                    'channel_id' => $recipientConfig['channel_id']
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
        $changedAt = time() * 1000;
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
                    'fallback_for' => $escalationConfig['fallback_for'] ?? null,
                    'changed_at' => $changedAt
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
                    $db->update(
                        'rule_escalation_recipient',
                        ['changed_at' => $changedAt, 'deleted' => 'y'],
                        ['id IN (?)' => $recipientsToRemove, 'deleted = ?' => 'n']
                    );
                }
            }

            foreach ($escalationConfig['recipient'] ?? [] as $recipientConfig) {
                $data = [
                    'rule_escalation_id' => $escalationId,
                    'channel_id' => $recipientConfig['channel_id']
                ];

                switch (true) {
                    case isset($recipientConfig['contact_id']):
                        $data['contact_id'] = $recipientConfig['contact_id'];
                        $data['contactgroup_id'] = null;
                        $data['schedule_id'] = null;
                        break;
                    case isset($recipientConfig['contactgroup_id']):
                        $data['contact_id'] = null;
                        $data['contactgroup_id'] = $recipientConfig['contactgroup_id'];
                        $data['schedule_id'] = null;
                        break;
                    case isset($recipientConfig['schedule_id']):
                        $data['contact_id'] = null;
                        $data['contactgroup_id'] = null;
                        $data['schedule_id'] = $recipientConfig['schedule_id'];
                        break;
                }

                if (! isset($recipientConfig['id'])) {
                    $db->insert('rule_escalation_recipient', $data);
                } else {
                    $db->update(
                        'rule_escalation_recipient',
                        $data + ['changed_at' => $changedAt],
                        ['id = ?' => $recipientConfig['id']]
                    );
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
        $this->ruleId = $id;

        $db = Database::get();

        $db->beginTransaction();

        $storedValues = $this->fetchDbValues();

        $values = $this->getChanges($storedValues, $config);

        $data = array_filter([
            'name'      => $values['name'] ?? null
        ]);

        if (array_key_exists('object_filter', $values)) {
            $data['object_filter'] = $values['object_filter'];
        }

        $changedAt = time() * 1000;
        if (! empty($data)) {
            $db->update('rule', $data + ['changed_at' => $changedAt], ['id = ?' => $id]);
        }

        if (! isset($values['rule_escalation'])) {
            $db->commitTransaction();

            return;
        }

        $escalationsInCache = $config['rule_escalation'];

        $escalationsToUpdate = [];
        $escalationsToRemove = [];

        foreach ($storedValues['rule_escalation'] as $escalationInDB) {
            $escalationId = $escalationInDB['id'];
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

        $markAsDeleted = ['changed_at' => $changedAt, 'deleted' => 'y'];
        if (! empty($escalationsToRemove)) {
            $db->update(
                'rule_escalation_recipient',
                $markAsDeleted,
                ['rule_escalation_id IN (?)' => $escalationsToRemove, 'deleted = ?' => 'n']
            );

            $db->update(
                'rule_escalation',
                $markAsDeleted + ['position' => null],
                ['id IN (?)' => $escalationsToRemove]
            );
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

        $escalationsToRemove = $db->fetchCol(
            RuleEscalation::on($db)
                ->columns('id')
                ->filter(Filter::equal('rule_id', $id))
                ->assembleSelect()
        );

        $markAsDeleted = ['changed_at' => time() * 1000, 'deleted' => 'y'];
        if (! empty($escalationsToRemove)) {
            $db->update(
                'rule_escalation_recipient',
                $markAsDeleted,
                ['rule_escalation_id IN (?)' => $escalationsToRemove, 'deleted = ?' => 'n']
            );
        }

        $db->update('rule_escalation', $markAsDeleted + ['position' => null], ['rule_id = ?' => $id]);
        $db->update('rule', $markAsDeleted, ['id = ?' => $id]);

        $db->commitTransaction();
    }

    protected function onError()
    {
        foreach ($this->getMessages() as $message) {
            if ($message instanceof Exception) {
                Notification::error($this->translate($message->getMessage()));
            }
        }
    }

    /**
     * Fetch the values from the database
     *
     * @return array
     *
     * @throws HttpNotFoundException
     */
    private function fetchDbValues(): array
    {
        $query = Rule::on(Database::get())
            ->columns(['id', 'name', 'object_filter'])
            ->filter(Filter::equal('id', $this->ruleId));

        $rule = $query->first();
        if ($rule === null) {
            throw new HttpNotFoundException($this->translate('Rule not found'));
        }

        $config = iterator_to_array($rule);

        $ruleEscalations = $rule
            ->rule_escalation
            ->withoutColumns(['changed_at', 'deleted']);

        foreach ($ruleEscalations as $re) {
            foreach ($re as $k => $v) {
                $config[$re->getTableName()][$re->position][$k] = $v;
            }

            $escalationRecipients = $re
                ->rule_escalation_recipient
                ->withoutColumns(['changed_at', 'deleted']);

            foreach ($escalationRecipients as $recipient) {
                $config[$re->getTableName()][$re->position]['recipient'][] = iterator_to_array($recipient);
            }
        }

        $config['showSearchbar'] = ! empty($config['object_filter']);

        return $config;
    }

    /**
     * Get the newly made changes
     *
     * @return array
     */
    public function getChanges(array $storedValues, array $formValues): array
    {
        unset($formValues['conditionPlusButtonPosition']);
        $dbValuesToCompare = array_intersect_key($storedValues, $formValues);

        if (count($formValues, COUNT_RECURSIVE) < count($dbValuesToCompare, COUNT_RECURSIVE)) {
            // fewer values in the form than in the db, escalation(s) has been removed
            if ($formValues['name'] === $dbValuesToCompare['name']) {
                unset($formValues['name']);
            }

            if ($formValues['object_filter'] === $dbValuesToCompare['object_filter']) {
                unset($formValues['object_filter']);
            }

            return $formValues;
        }

        $checker = static function ($a, $b) use (&$checker) {
            if (! is_array($a) || ! is_array($b)) {
                return $a <=> $b;
            }

            return empty(array_udiff_assoc($a, $b, $checker)) ? 0 : 1;
        };

        return array_udiff_assoc($formValues, $dbValuesToCompare, $checker);
    }
}
