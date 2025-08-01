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

use Glpi\Dashboard\Widget;
use Safe\DateTime;

/**
 * Store network port metrics
 */
class NetworkPortMetrics extends CommonDBChild
{
    public static $itemtype        = 'NetworkPort';
    public static $items_id        = 'networkports_id';
    public $dohistory              = false;

    public static function getTypeName($nb = 0)
    {
        return __('Network port metrics');
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        $array_ret = [];

        if ($item::class === NetworkPort::class) {
            $cnt = countElementsInTable([static::getTable()], [static::$items_id => $item->getField('id')]);
            $array_ret[] = self::createTabEntry(self::getTypeName(Session::getPluralNumber()), $cnt, $item::class);
        }
        return $array_ret;
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item::class === NetworkPort::class && $item->getID() > 0) {
            $metrics = new self();
            $metrics->showMetrics($item);
            return true;
        }
        return false;
    }

    /**
     * Get metrics
     *
     * @param NetworkPort $netport      Printer instance
     * @param array       $user_filters User filters
     *
     * @return array
     */
    public function getMetrics(NetworkPort $netport, $user_filters = []): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $bdate = new DateTime();
        $bdate->sub(new DateInterval('P1Y'));
        $filters = [
            'date' => ['>', $bdate->format('Y-m-d')],
        ];
        $filters = array_merge($filters, $user_filters);

        $iterator = $DB->request([
            'FROM'   => static::getTable(),
            'WHERE'  => [
                static::$items_id  => $netport->fields['id'],
            ] + $filters,
        ]);

        return iterator_to_array($iterator);
    }

    /**
     * Display form for agent
     *
     * @param NetworkPort $netport Port instance
     */
    public function showMetrics(NetworkPort $netport)
    {
        $raw_metrics = $this->getMetrics($netport);

        // build graph data
        $params = [
            'icon'          => NetworkPort::getIcon(),
        ];

        $bytes_series = [];
        $errors_series = [];
        $labels = [];
        foreach ($raw_metrics as $metrics) {
            $date = new DateTime($metrics['date']);
            $labels[] = $date->format(__('Y-m-d'));
            unset($metrics['id'], $metrics['date'], $metrics['date_creation'], $metrics['date_mod'], $metrics[static::$items_id]);

            $bytes_metrics = $metrics;
            unset($bytes_metrics['ifinerrors'], $bytes_metrics['ifouterrors']);
            foreach ($bytes_metrics as $key => $value) {
                $bytes_series[$key]['name'] = $this->getLabelFor($key);
                $bytes_series[$key]['data'][] = round($value / 1024 / 1024, 0); //convert bytes to megabytes
            }

            $errors_metrics = $metrics;
            unset($errors_metrics['ifinbytes'], $errors_metrics['ifoutbytes']);
            foreach ($errors_metrics as $key => $value) {
                $errors_series[$key]['name'] = $this->getLabelFor($key);
                $errors_series[$key]['data'][] = $value;
            }
        }

        $bytes_bar_conf = [
            'data'  => [
                'labels' => $labels,
                'series' => array_values($bytes_series),
            ],
            'label' => __('Input/Output megabytes'),
            'icon'  => $params['icon'],
            'color' => '#ffffff',
            'distributed' => false,
            'show_points' => false,
            'line_width'  => 2,
        ];

        //display bytes graph
        echo "<div class='dashboard netports_metrics bytes'>";
        echo Widget::multipleAreas($bytes_bar_conf);
        echo "</div>";

        $errors_bar_conf = [
            'data'  => [
                'labels' => $labels,
                'series' => array_values($errors_series),
            ],
            'label' => __('Input/Output errors'),
            'icon'  => $params['icon'],
            'color' => '#ffffff',
            'distributed' => false,
            'show_points' => false,
            'line_width'  => 2,
        ];

        echo "</br>";

        //display error graph
        echo "<div class='dashboard netports_metrics'>";
        echo Widget::multipleAreas($errors_bar_conf);
        echo "</div>";
    }

    private function getLabelFor($key): string
    {
        return match ($key) {
            'ifinbytes' => __('Input megabytes'),
            'ifoutbytes' => __('Output megabytes'),
            'ifinerrors' => __('Input errors'),
            'ifouterrors' => __('Output errors'),
            default => $key,
        };
    }

    public static function getIcon()
    {
        return 'ti ti-chart-line';
    }
}
