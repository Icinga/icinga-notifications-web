<?php

// SPDX-FileCopyrightText: 2025 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Icinga\Module\Notifications\Forms;

use ArrayIterator;
use DateTime;
use Icinga\Module\Notifications\Form\ConfigProviderInterface;
use Icinga\Module\Notifications\Forms\EventRuleConfigForm;
use Icinga\Module\Notifications\Model\Channel;
use Icinga\Module\Notifications\Model\Contact;
use Icinga\Module\Notifications\Model\Contactgroup;
use Icinga\Module\Notifications\Model\Rule;
use Icinga\Module\Notifications\Model\RuleEscalation;
use Icinga\Module\Notifications\Model\RuleEscalationRecipient;
use Icinga\Module\Notifications\Model\Schedule;
use ipl\Orm\Query;
use ipl\Orm\ResultSet;
use ipl\Sql\Connection;
use ipl\Web\Url;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

class EventRuleConfigFormTest extends TestCase
{
    public function testRequiresAnEscalationWithOneRecipient(): void
    {
        $providerMock = $this->createMock(ConfigProviderInterface::class);

        $providerMock->expects($this->once())
            ->method('fetchContacts')
            ->willReturn([]);
        $providerMock->expects($this->once())
            ->method('fetchContactGroups')
            ->willReturn([]);
        $providerMock->expects($this->once())
            ->method('fetchSchedules')
            ->willReturn([]);
        $providerMock->expects($this->once())
            ->method('fetchChannels')
            ->willReturn([]);

        $requestStub = $this->createStub(ServerRequestInterface::class);
        $requestStub->method('getMethod')->willReturn('POST');
        $requestStub->method('getUploadedFiles')->willReturn([]);
        $requestStub->method('getParsedBody')->willReturn([
            'id' => 1337,
            'source' => 1338,
            'name' => 'Test'
        ]);

        $form = new EventRuleConfigForm($providerMock, $this->createStub(Url::class));
        $form->disableCsrfCounterMeasure();

        $form->handleRequest($requestStub);

        $elements = $form->getElements();
        $this->assertNotEmpty($elements, 'Form has no elements');

        // Form must be invalid for one reason only, the escalations
        foreach ($elements as $element) {
            if ($element->getName() === 'escalations') {
                $this->assertFalse($element->isValid(), 'Escalations are not required');
                $this->assertTrue($element->hasElement('0'), 'At least one escalation is required');
                $escalation = $element->getElement('0');
                $this->assertFalse($escalation->isValid(), 'The escalation is not required to have recipients');
                $this->assertTrue($escalation->hasElement('recipients'), 'The escalation has no recipients');
                $recipients = $escalation->getElement('recipients');
                $this->assertFalse($recipients->isValid(), 'The escalation does not require recipients');
                $this->assertTrue($recipients->hasElement('0'), 'At least one recipient is required');
                $recipient = $recipients->getElement('0');
                $this->assertFalse($recipient->isValid(), 'The escalation recipient is not required');
            } else {
                $this->assertTrue($element->isValid(), sprintf('Element %s is not valid', $element->getName()));
            }
        }
    }
}
