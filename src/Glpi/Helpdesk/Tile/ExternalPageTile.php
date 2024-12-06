<?php

/**
 * ---------------------------------------------------------------------
 *
 * GLPI - Gestionnaire Libre de Parc Informatique
 *
 * http://glpi-project.org
 *
 * @copyright 2015-2024 Teclib' and contributors.
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

namespace Glpi\Helpdesk\Tile;

use CommonDBTM;
use Glpi\Session\SessionInfo;
use Override;

final class ExternalPageTile extends CommonDBTM implements TileInterface
{
    #[Override]
    public function getTitle(): string
    {
        return $this->fields['title'];
    }

    #[Override]
    public function getDescription(): string
    {
        return $this->fields['description'];
    }

    #[Override]
    public function getIllustration(): string
    {
        return $this->fields['illustration'];
    }

    #[Override]
    public function getTileUrl(): string
    {
        return $this->fields['url'];
    }

    #[Override]
    public function isValid(SessionInfo $session_info): bool
    {
        return true;
    }
}