<?php

/**
 * ---------------------------------------------------------------------
 *
 * GLPI - Gestionnaire Libre de Parc Informatique
 *
 * http://glpi-project.org
 *
 * @copyright 2015-2024 Teclib' and contributors.
 * @copyright 2003-2014 by the INDEPNET Development Team.
 * @licence   https://www.gnu.org/licenses/gpl-3.0.html
 *
 * ---------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of GLPI.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * ---------------------------------------------------------------------
 */

namespace Glpi\Form\Export\Context\ForeignKey;

use Glpi\Form\Export\Context\DatabaseMapper;
use Glpi\Form\Export\Specification\DataRequirementSpecification;

/**
 * Handle a foreign keys.
 */
final class ForeignKeyHandler implements JsonConfigForeignKeyHandlerInterface
{
    /** @param class-string<\CommonDBTM> $itemtype */
    public function __construct(
        private string $key,
        private string $itemtype,
    ) {
    }

    public function getDataRequirements(array $serialized_data): array
    {
        if (!$this->keyExistInSerializedData($serialized_data)) {
            return [];
        }

        $requirements = [];
        $foreign_key = $serialized_data[$this->key];

        // Create a data requirement for the foreign key and load item
        $item = new $this->itemtype();
        if ($item->getFromDB($foreign_key)) {
            $requirements[] = new DataRequirementSpecification(
                $this->itemtype,
                $item->getName(),
            );
        }

        return $requirements;
    }

    public function replaceForeignKeysByNames(array $serialized_data): array
    {
        if (!$this->keyExistInSerializedData($serialized_data)) {
            return [];
        }

        $foreign_key = $serialized_data[$this->key];

        // Replace the foreign key by the name of the item it references and load item
        $item = new $this->itemtype();
        if ($item->getFromDB($foreign_key)) {
            $serialized_data[$this->key] = $item->getName();
        }

        return $serialized_data;
    }

    public function replaceNamesByForeignKeys(
        array $serialized_data,
        DatabaseMapper $mapper,
    ): array {
        if (!$this->keyExistInSerializedData($serialized_data)) {
            return [];
        }

        // Replace name by its database id
        $serialized_data[$this->key] = $mapper->getItemId(
            $this->itemtype,
            $serialized_data[$this->key]
        );

        return $serialized_data;
    }

    private function keyExistInSerializedData(array $serialized_data): bool
    {
        return isset($serialized_data[$this->key]);
    }
}
