<?php

/* Icinga Notifications Web | (c) 2025 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Forms\EventRuleConfigElements;

use ipl\Html\Attributes;

/**
 * @internal This trait is only intended for use by the {@see EventRuleConfigForm} classes.
 */
trait ConfigProvider
{
    /** @var ?ConfigProviderInterface The config provider */
    protected ?ConfigProviderInterface $provider = null;

    /**
     * Set the config provider to use
     *
     * @param ConfigProviderInterface $provider
     *
     * @return void
     */
    public function setProvider(ConfigProviderInterface $provider): void
    {
        $this->provider = $provider;
    }

    protected function registerAttributeCallbacks(Attributes $attributes): void
    {
        $attributes->registerAttributeCallback('provider', null, $this->setProvider(...));

        parent::registerAttributeCallbacks($attributes);
    }
}
