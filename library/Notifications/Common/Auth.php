<?php

// SPDX-FileCopyrightText: 2023 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Common;

use Icinga\Authentication\Auth as IcingaAuth;
use Icinga\User;
use ipl\Orm\Query;
use ipl\Stdlib\Filter;
use ipl\Web\Filter\QueryString;

trait Auth
{
    public function getAuth(): IcingaAuth
    {
        return IcingaAuth::getInstance();
    }

    /**
     * Apply module restrictions depending on what is queried
     *
     * @param Query $query
     *
     * @return void
     */
    public function applyRestrictions(Query $query): void
    {
        // TODO: Since the recent integration rewrite, restriction support does not work anymore as expected.
        //       Will be reworked with the next release.
        return;

        /** @var User $user */
        $user = $this->getAuth()->getUser();
        if ($user->isUnrestricted()) {
            return;
        }

        $queryFilter = Filter::any();
        foreach ($user->getRoles() as $role) {
            $roleFilter = Filter::all();
            /** @var string $restriction */
            $restriction = $role->getRestrictions('notifications/filter/objects');
            if ($restriction) {
                $roleFilter->add($this->parseRestriction($restriction, 'notifications/filter/objects'));
            }

            if (! $roleFilter->isEmpty()) {
                $queryFilter->add($roleFilter);
            }
        }

        $query->filter($queryFilter);
    }

    /**
     * Parse the given restriction
     *
     * @param string $queryString
     * @param string $restriction The name of the restriction
     *
     * @return Filter\Rule
     */
    protected function parseRestriction(string $queryString, string $restriction): Filter\Rule
    {
        // 'notifications/filter/objects' restriction
        return QueryString::fromString($queryString)
            ->on(
                QueryString::ON_CONDITION,
                function (Filter\Condition $condition) {
                    //The condition column is actually the tag (eg): tag = hostgroup/linux, value = null
                    if ($condition->getValue() === true) {
                        $column = 'object.object_extra_tag.tag';

                        $condition->setValue($condition->getColumn());
                        $condition->setColumn($column);
                    }
                    //TODO: add support for foo=bar (tag=value)
                }
            )->parse();
    }
}
