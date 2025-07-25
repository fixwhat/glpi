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

require_once(__DIR__ . '/../_check_webserver_config.php');

use Glpi\Form\AnswersSet;
use Glpi\Form\Form;

// Read parameters
$id = $_REQUEST['id'] ?? null;

if (isset($_POST["purge"])) {
    $answers_set = new AnswersSet();
    // TODO: Add a specific right to ensure the user can delete an answer
    $answers_set->check($id, DELETE);
    $answers_set->delete($_POST, true);
    $answers_set->redirectToList();
} else {
    Session::checkRight(Form::$rightname, READ);
    AnswersSet::displayFullPageForItem($id, ['admin', Form::getType()], []);
}
