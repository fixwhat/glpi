<?php

/**
 * ---------------------------------------------------------------------
 *
 * GLPI - Gestionnaire Libre de Parc Informatique
 *
 * http://glpi-project.org
 *
 * @copyright 2015-2025 Teclib' and contributors.
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

namespace Glpi\Search\Output;

use PhpOffice\PhpSpreadsheet\Writer\Ods\Mimetype;
use RuntimeException;

final class Ods extends Spreadsheet
{
    public function __construct()
    {
        parent::__construct();
        $this->writer = new \PhpOffice\PhpSpreadsheet\Writer\Ods($this->spread);
    }

    public function getMime(): string
    {
        // This can't happen but it helps with static analysis
        if (!$this->writer instanceof \PhpOffice\PhpSpreadsheet\Writer\Ods) {
            throw new RuntimeException();
        }

        $mime = new Mimetype($this->writer);
        return $mime->write();
    }

    public function getFileName(): string
    {
        return "glpi.ods";
    }
}
