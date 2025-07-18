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
 * @since 10.0.0
 */
class PendingReasonCron extends CommonDBTM
{
    public const TASK_NAME = 'pendingreason_autobump_autosolve';

    /**
     * Get task description
     *
     * @return string
     */
    public static function getTaskDescription(): string
    {
        return __("Send automated follow-ups on pending tickets and solve them if necessary");
    }

    public static function cronInfo($name)
    {
        return [
            'description' => self::getTaskDescription(),
        ];
    }

    /**
     * Run from cronTask
     *
     * @param CronTask $task
     */
    public static function cronPendingreason_autobump_autosolve(CronTask $task)
    {
        /** @var DBmysql $DB */
        global $DB;

        $config = Config::getConfigurationValues('core', ['system_user']);

        if (empty($config['system_user'])) {
            trigger_error("Missing system_user config", E_USER_WARNING);
            return 0;
        }

        $user = User::getById($config['system_user']);
        if (!$user) {
            trigger_error("Missing system_user user", E_USER_WARNING);
            return 0;
        }

        $targets = [
            Ticket::getType(),
            Change::getType(),
            Problem::getType(),
        ];

        $now = $_SESSION['glpi_currenttime'];

        $data = $DB->request([
            'SELECT' => 'id',
            'FROM'   => PendingReason_Item::getTable(),
            'WHERE'  => [
                'pendingreasons_id'  => ['>', 0],
                'followup_frequency' => ['>', 0],
                'itemtype'           => $targets,
            ],
        ]);

        foreach ($data as $row) {
            $pending_item = PendingReason_Item::getById($row['id']);
            $itemtype = $pending_item->fields['itemtype'];
            $item = $itemtype::getById($pending_item->fields['items_id']);
            if (!$item instanceof $itemtype || !$pending_item instanceof PendingReason_Item) {
                trigger_error("Failed to load item", E_USER_WARNING);
                continue;
            }

            if ($item->fields['status'] != CommonITILObject::WAITING) {
                $pending_item->delete([
                    'id' => $pending_item->fields['id'],
                ]);
                continue;
            }

            // Load pending reason
            $pending_reason = PendingReason::getById($pending_item->fields['pendingreasons_id']);
            if (!$pending_reason) {
                trigger_error("Failed to load PendingReason", E_USER_WARNING);
                continue;
            }

            $next_bump = $pending_item->getNextFollowupDate();
            $resolve = $pending_item->getAutoResolvedate();

            if ($next_bump && $now > $next_bump) {
                $template_id = $pending_reason->fields['itilfollowuptemplates_id'];

                // No template defined; can't bump
                if (!$template_id) {
                    continue;
                }

                $success = $pending_item->update([
                    'id'             => $pending_item->getID(),
                    'bump_count'     => $pending_item->fields['bump_count'] + 1,
                    'last_bump_date' => $_SESSION['glpi_currenttime'],
                ]);

                if (!$success) {
                    trigger_error("Can't bump, unable to update pending item", E_USER_WARNING);
                    continue;
                }

                $itilfup_template = ITILFollowupTemplate::getById(
                    $pending_reason->fields['itilfollowuptemplates_id']
                );
                $content = '';
                if ($itilfup_template instanceof ITILFollowupTemplate) {
                    $content = $itilfup_template->getRenderedContent($item);
                }

                // Add reminder (new ITILReminder)
                $reminder = new ITILReminder();
                $reminder->add([
                    'itemtype' => $item::getType(),
                    'items_id' => $item->getID(),
                    'pendingreasons_id' => $pending_reason->getID(),
                    'name' => $pending_reason->fields['name'],
                    'content' => $content,
                ]);
                $task->addVolume(1);

                // Send notification
                NotificationEvent::raiseEvent('auto_reminder', $item);
            } elseif ($resolve && $now > $resolve) {
                // Load solution template
                $solution_template = SolutionTemplate::getById($pending_reason->fields['solutiontemplates_id']);
                if (!$solution_template instanceof SolutionTemplate) {
                    trigger_error("Failed to load SolutionTemplate::{$pending_reason->fields['solutiontemplates_id']}", E_USER_WARNING);
                    continue;
                }

                // Add solution
                $solution = new ITILSolution();
                $solution->add([
                    'itemtype'             => $item::getType(),
                    'items_id'             => $item->getID(),
                    'solutiontypes_id'     => $solution_template->fields['solutiontypes_id'],
                    'content'              => $solution_template->getRenderedContent($item),
                    'users_id'             => $config['system_user'],
                    '_disable_auto_assign' => true,
                ]);
                $task->addVolume(1);
                NotificationEvent::raiseEvent('pendingreason_close', $item);
            }
        }

        return 1;
    }

    /**
     * Return the localized name of the current Type
     * Should be overloaded in each new class
     *
     * @param integer $nb Number of items
     *
     * @return string
     **/
    public static function getTypeName($nb = 0)
    {
        return __('Automatic followups / resolution');
    }
}
