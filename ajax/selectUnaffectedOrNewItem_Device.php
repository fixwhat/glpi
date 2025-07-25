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

/**
 * @since 0.85
 */

use Glpi\DBAL\QueryExpression;
use Glpi\DBAL\QueryFunction;

/** @var DBmysql $DB */
global $DB;

header("Content-Type: text/html; charset=UTF-8");
Html::header_nocache();

Session::checkCentralAccess();

// Make a select box
if (
    $_POST['items_id']
    && $_POST['itemtype'] && class_exists($_POST['itemtype'])
) {
    $devicetype = $_POST['itemtype'];
    $linktype   = $devicetype::getItem_DeviceType();

    if (count($linktype::getSpecificities())) {
        $keys = array_keys($linktype::getSpecificities());
        $name_field = QueryFunction::concat_ws(
            separator: new QueryExpression($DB::quoteValue(' - ')),
            params: $keys,
            alias: 'name'
        );
    } else {
        $name_field = 'id AS name';
    }
    $result = $DB->request(
        [
            'SELECT' => ['id', $name_field],
            'FROM'   => $linktype::getTable(),
            'WHERE'  => [
                $devicetype::getForeignKeyField() => $_POST['items_id'],
                'itemtype'                        => '',
            ],
        ]
    );
    echo "<table class='w-100'><tr><td>" . __s('Choose an existing device') . "</td><td rowspan='2'>" .
        __s('and/or') . "</td><td>" . __s('Add new devices') . '</td></tr>';
    echo "<tr><td>";
    if (count($result) === 0) {
        echo __s('No unaffected device!');
    } else {
        $devices = [];
        foreach ($result as $row) {
            $name = $row['name'];
            if (empty($name)) {
                $name = $row['id'];
            }
            $devices[$row['id']] = $name;
        }
        Dropdown::showFromArray($linktype::getForeignKeyField(), $devices, ['multiple' => true]);
    }
    echo "</td><td>";
    Dropdown::showNumber('new_devices', ['min'   => 0, 'max'   => 10]);
    echo "</td></tr></table>";
}
