<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Widget;

use Icinga\Application\Logger;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Common\Links;
use Icinga\Module\Notifications\Model\Channel;
use Icinga\Module\Notifications\Model\Contact;
use Icinga\Module\Notifications\Model\Rule;
use Icinga\Module\Notifications\Model\Source;
use ipl\Html\Attributes;
use ipl\Html\BaseHtmlElement;
use ipl\Html\Contract\MutableHtml;
use ipl\Html\HtmlElement;
use ipl\Html\Text;
use ipl\I18n\Translation;
use ipl\Sql\Expression;
use ipl\Web\Url;
use ipl\Web\Widget\ButtonLink;
use ipl\Web\Widget\Icon;
use Throwable;

class InitialSetup extends BaseHtmlElement
{
    use Translation;

    protected $tag = 'div';

    protected $defaultAttributes = ['class' => 'initial-setup'];

    /** @var bool Whether the setup has an add button */
    protected bool $hasAddButton = false;

    /** @var bool Whether the setup is finished */
    protected bool $finished = false;

    /**
     * Whether the setup is finished
     *
     * @return bool
     */
    public function isFinished(): bool
    {
        return $this->finished;
    }

    protected function assemble(): void
    {
        $this->addHtml(new HtmlElement(
            'h1',
            Attributes::create(['class' => 'title']),
            Text::create($this->translate('Welcome to the initial setup of Icinga Notifications'))
        ));

        $source = $this->createStep(
            $this->translate('Source configuration'),
            $this->translate(
                'Sources are the most vital part of Icinga Notifications. They submit events that will be'
                . ' processed to notify users about incidents.'
            ),
            ! $this->hasAddButton && ! Source::on(Database::get())->columns([new Expression('1')])->limit(1)->first(),
            $this->translate('Create new Source'),
            Links::sourceAdd()
        );

        $this->addHtml($source);

        if ($this->hasAddButton) {
            try {
                $hooks = [];
                $container = new HtmlElement('div', Attributes::create(['class' => 'integrations']));

                foreach ($hooks as $hook) {
                    // add hook content to the $container
                }

                if (! $container->isEmpty()) {
                    $container->prependHtml(new HtmlElement(
                        'p',
                        content: Text::create($this->translate(
                            'You can either create a new Source or add an existing integration from the following:'
                        ))
                    ));

                    $source->addHtml($container);
                }
            } catch (Throwable $e) {
                Logger::error('Failed to load source integration setup hook:', $e);
            }
        }

        $this->addHtml($this->createStep(
            $this->translate('Channel configuration'),
            $this->translate(
                'You need to configure at least one valid communication channel to fully configure Icinga'
                . ' Notifications.'
            ),
            ! $this->hasAddButton && ! Channel::on(Database::get())->columns([new Expression('1')])->limit(1)->first(),
            $this->translate('Create new Channel'),
            Links::channelAdd()
        ));

        $this->addHtml($this->createStep(
            $this->translate('Contact configuration'),
            $this->translate('Specify at least one contact to which the notifications will be sent.'),
            ! $this->hasAddButton && ! Contact::on(Database::get())->columns([new Expression('1')])->limit(1)->first(),
            $this->translate('Create new Contact'),
            Links::contactAdd()
        ));

        $this->addHtml($this->createStep(
            $this->translate('Event Rule configuration'),
            $this->translate('Create at least one event rule to start receiving notifications.'),
            ! $this->hasAddButton && ! Rule::on(Database::get())->columns([new Expression('1')])->limit(1)->first(),
            $this->translate('Create new Event Rule'),
            Url::fromPath('notifications/event-rules/add')
        ));

        $this->finished = ! $this->hasAddButton;
    }

    /**
     * Create a step
     *
     * @param string $title The title of the step
     * @param string $description The description of the step
     * @param bool $wantButton Whether the step should have a button
     * @param string $buttonTitle The title of the button
     * @param Url $buttonUrl The URL of the button
     *
     * @return MutableHtml
     */
    protected function createStep(
        string $title,
        string $description,
        bool $wantButton,
        string $buttonTitle,
        Url $buttonUrl
    ): MutableHtml {
        $visual = new HtmlElement('div', Attributes::create(['class' => 'visual']));
        $main = new HtmlElement(
            'div',
            Attributes::create(['class' => 'main']),
            $visual,
            new HtmlElement(
                'header',
                null,
                new HtmlElement('div', Attributes::create(['class' => 'title']), Text::create($title)),
                new HtmlElement('p', Attributes::create(['class' => 'description']), Text::create($description))
            )
        );
        if ($wantButton) {
            $this->hasAddButton = true;

            $main->addHtml(
                (new ButtonLink($buttonTitle, $buttonUrl, 'plus', ['class' => 'setup-button']))
                    ->setBaseTarget('_next')
            );

            $visual->addHtml(new Icon('circle-plus'));
            $stepState = 'current';
        } elseif (! $this->hasAddButton) {
            $visual->addHtml(new Icon('check-circle'));
            $stepState = 'completed';
        } else {
            $visual->addHtml(new Icon('circle-exclamation'));
            $stepState = 'locked';
        }

        return new HtmlElement('div', Attributes::create(['class' => ['step', $stepState]]), $main);
    }
}
