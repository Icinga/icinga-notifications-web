<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Common;

use Icinga\Util\LessParser;
use ipl\Html\BaseHtmlElement;
use ipl\Html\Text;

class Style extends BaseHtmlElement
{
    protected $tag = 'style';

    /** @var string */
    protected $module;

    /** @var string */
    protected $parentSelector;

    /** @var array<string,array> */
    protected $rules = [];

    public function setModule(string $name): self
    {
        $this->module = $name;

        return $this;
    }

    public function getModule(): ?string
    {
        return $this->module;
    }

    public function setParentSelector(string $selector): self
    {
        $this->parentSelector = $selector;

        return $this;
    }

    public function getParentSelector(): ?string
    {
        return $this->parentSelector;
    }

    public function addRule(string $selector, array $set): self
    {
        $this->rules[$selector] = array_merge($this->rules[$selector] ?? [], $set);

        return $this;
    }

    protected function assemble()
    {
        $lessc = new LessParser();
        $lessc->setFormatter('compressed');

        $source = '';
        if (($module = $this->getModule()) !== null) {
            $source = '.icinga-module.module-' . $module;
        }

        if (($parentSelector = $this->getParentSelector()) !== null) {
            $source .= " $parentSelector";
        }

        $source .= " {\n";

        foreach ($this->rules as $selector => $set) {
            $rule = "$selector {\n";
            foreach ($set as $property => $value) {
                $rule .= "$property: $value;\n";
            }

            $rule .= "}\n";
            $source .= $rule;
        }

        $source .= '}';

        $this->addHtml(Text::create($lessc->compile($source)));
    }
}
