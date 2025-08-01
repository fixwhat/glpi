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
use Glpi\Application\View\TemplateRenderer;
use Glpi\Asset\Asset_PeripheralAsset;
use Glpi\Features\AssignableItem;
use Glpi\Features\AssignableItemInterface;
use Glpi\Features\Clonable;
use Glpi\Features\DCBreadcrumb;
use Glpi\Features\DCBreadcrumbInterface;
use Glpi\Features\Inventoriable;

/**
 * Monitor Class
 **/
class Monitor extends CommonDBTM implements AssignableItemInterface, DCBreadcrumbInterface
{
    use DCBreadcrumb;
    use Clonable;
    use Inventoriable;
    use Glpi\Features\State;
    use AssignableItem {
        prepareInputForAdd as prepareInputForAddAssignableItem;
    }

    // From CommonDBTM
    public $dohistory                   = true;
    protected static $forward_entity_to = ['Infocom', 'ReservationItem', 'Item_OperatingSystem', 'NetworkPort',
        'Item_SoftwareVersion',
    ];

    public static $rightname            = 'monitor';
    protected $usenotepad               = true;

    public function getCloneRelations(): array
    {
        return [
            Item_OperatingSystem::class,
            Item_Devices::class,
            Infocom::class,
            Contract_Item::class,
            Document_Item::class,
            Asset_PeripheralAsset::class,
            KnowbaseItem_Item::class,
            Appliance_Item::class,
            Domain_Item::class,
            Item_Project::class,
            Item_SoftwareLicense::class,
            Item_SoftwareVersion::class,
            ManualLink::class,
            NetworkPort::class,
        ];
    }

    /**
     * Name of the type
     *
     * @param $nb  string   number of item in the type
     **/
    public static function getTypeName($nb = 0)
    {
        return _n('Monitor', 'Monitors', $nb);
    }

    public static function getSectorizedDetails(): array
    {
        return ['assets', self::class];
    }

    public static function getLogDefaultServiceName(): string
    {
        return 'inventory';
    }

    /**
     * @see CommonDBTM::useDeletedToLockIfDynamic()
     *
     * @since 0.84
     **/
    public function useDeletedToLockIfDynamic()
    {
        return false;
    }


    public function defineTabs($options = [])
    {

        $ong = [];
        $this->addDefaultFormTab($ong);
        $this->addImpactTab($ong, $options);
        $this->addStandardTab(Item_OperatingSystem::class, $ong, $options);
        $this->addStandardTab(Item_SoftwareVersion::class, $ong, $options);
        $this->addStandardTab(Item_Devices::class, $ong, $options);
        $this->addStandardTab(Asset_PeripheralAsset::class, $ong, $options);
        $this->addStandardTab(NetworkPort::class, $ong, $options);
        $this->addStandardTab(Infocom::class, $ong, $options);
        $this->addStandardTab(Contract_Item::class, $ong, $options);
        $this->addStandardTab(Document_Item::class, $ong, $options);
        $this->addStandardTab(KnowbaseItem_Item::class, $ong, $options);
        $this->addStandardTab(Item_Ticket::class, $ong, $options);
        $this->addStandardTab(Item_Problem::class, $ong, $options);
        $this->addStandardTab(Change_Item::class, $ong, $options);
        $this->addStandardTab(Item_Project::class, $ong, $options);
        $this->addStandardTab(ManualLink::class, $ong, $options);
        $this->addStandardTab(Lock::class, $ong, $options);
        $this->addStandardTab(Notepad::class, $ong, $options);
        $this->addStandardTab(Reservation::class, $ong, $options);
        $this->addStandardTab(Domain_Item::class, $ong, $options);
        $this->addStandardTab(Appliance_Item::class, $ong, $options);
        $this->addStandardTab(RuleMatchedLog::class, $ong, $options);
        $this->addStandardTab(Log::class, $ong, $options);

        return $ong;
    }

    public function prepareInputForAdd($input)
    {
        if (isset($input["id"]) && ($input["id"] > 0)) {
            $input["_oldID"] = $input["id"];
        }
        if (isset($input["size"]) && ($input["size"] == '')) {
            unset($input["size"]);
        }
        unset($input['id']);
        unset($input['withtemplate']);

        $input = $this->prepareInputForAddAssignableItem($input);
        return $input;
    }


    /**
     * Print the monitor form
     *
     * @param $ID integer ID of the item
     * @param $options array
     *     - target filename : where to go when done.
     *     - withtemplate boolean : template or basic item
     *
     * @return boolean item found
     **/
    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);
        TemplateRenderer::getInstance()->display('pages/assets/monitor.html.twig', [
            'item'   => $this,
            'params' => $options,
        ]);
        return true;
    }


    /**
     * Return the linked items (`Asset_PeripheralAsset` relations)
     *
     * @return array of linked items  like array('Computer' => array(1,2), 'Printer' => array(5,6))
     * @since 0.84.4
     **/
    public function getLinkedItems()
    {
        /** @var DBmysql $DB */
        global $DB;

        $iterator = $DB->request([
            'SELECT' => [
                'itemtype_asset',
                'items_id_asset',
            ],
            'FROM'   => Asset_PeripheralAsset::getTable(),
            'WHERE'  => [
                'itemtype_peripheral' => $this->getType(),
                'items_id_peripheral' => $this->fields['id'],
            ],
        ]);
        $tab = [];
        foreach ($iterator as $data) {
            $tab[$data['itemtype_asset']][$data['items_id_asset']] = $data['items_id_asset'];
        }
        return $tab;
    }


    public function getSpecificMassiveActions($checkitem = null)
    {

        $actions = parent::getSpecificMassiveActions($checkitem);
        if (static::canUpdate()) {
            Asset_PeripheralAsset::getMassiveActionsForItemtype($actions, self::class, false, $checkitem);
            $actions += [
                'Item_SoftwareLicense' . MassiveAction::CLASS_ACTION_SEPARATOR . 'add'
               => "<i class='ma-icon fas fa-key'></i>" .
                  _sx('button', 'Add a license'),
            ];
            KnowbaseItem_Item::getMassiveActionsForItemtype($actions, self::class, false, $checkitem);
        }

        return $actions;
    }


    public function rawSearchOptions()
    {
        $tab = parent::rawSearchOptions();

        $tab[] = [
            'id'                 => '2',
            'table'              => $this->getTable(),
            'field'              => 'id',
            'name'               => __('ID'),
            'massiveaction'      => false,
            'datatype'           => 'number',
        ];

        $tab = array_merge($tab, Location::rawSearchOptionsToAdd());

        $tab[] = [
            'id'                 => '4',
            'table'              => 'glpi_monitortypes',
            'field'              => 'name',
            'name'               => _n('Type', 'Types', 1),
            'datatype'           => 'dropdown',
        ];

        $tab[] = [
            'id'                 => '40',
            'table'              => 'glpi_monitormodels',
            'field'              => 'name',
            'name'               => _n('Model', 'Models', 1),
            'datatype'           => 'dropdown',
        ];

        $tab[] = [
            'id'                 => '31',
            'table'              => State::getTable(),
            'field'              => 'completename',
            'name'               => __('Status'),
            'datatype'           => 'dropdown',
            'condition'          => $this->getStateVisibilityCriteria(),
        ];

        $tab[] = [
            'id'                 => '5',
            'table'              => $this->getTable(),
            'field'              => 'serial',
            'name'               => __('Serial number'),
            'datatype'           => 'string',
        ];

        $tab[] = [
            'id'                 => '6',
            'table'              => $this->getTable(),
            'field'              => 'otherserial',
            'name'               => __('Inventory number'),
            'datatype'           => 'string',
        ];

        $tab[] = [
            'id'                 => '7',
            'table'              => $this->getTable(),
            'field'              => 'contact',
            'name'               => __('Alternate username'),
            'datatype'           => 'string',
        ];

        $tab[] = [
            'id'                 => '8',
            'table'              => $this->getTable(),
            'field'              => 'contact_num',
            'name'               => __('Alternate username number'),
            'datatype'           => 'string',
        ];

        $tab[] = [
            'id'                 => '10',
            'table'              => $this->getTable(),
            'field'              => 'uuid',
            'name'               => __('UUID'),
            'datatype'           => 'string',
        ];

        $tab[] = [
            'id'                 => '70',
            'table'              => 'glpi_users',
            'field'              => 'name',
            'name'               => User::getTypeName(1),
            'datatype'           => 'dropdown',
            'right'              => 'all',
        ];

        $tab[] = [
            'id'                 => '71',
            'table'              => 'glpi_groups',
            'field'              => 'completename',
            'name'               => Group::getTypeName(1),
            'condition'          => ['is_itemgroup' => 1],
            'joinparams'         => [
                'beforejoin'         => [
                    'table'              => 'glpi_groups_items',
                    'joinparams'         => [
                        'jointype'           => 'itemtype_item',
                        'condition'          => ['NEWTABLE.type' => Group_Item::GROUP_TYPE_NORMAL],
                    ],
                ],
            ],
            'forcegroupby'       => true,
            'massiveaction'      => false,
            'datatype'           => 'dropdown',
        ];

        $tab[] = [
            'id'                 => '19',
            'table'              => $this->getTable(),
            'field'              => 'date_mod',
            'name'               => __('Last update'),
            'datatype'           => 'datetime',
            'massiveaction'      => false,
        ];

        $tab[] = [
            'id'                 => '121',
            'table'              => $this->getTable(),
            'field'              => 'date_creation',
            'name'               => __('Creation date'),
            'datatype'           => 'datetime',
            'massiveaction'      => false,
        ];

        $tab[] = [
            'id'                 => '16',
            'table'              => $this->getTable(),
            'field'              => 'comment',
            'name'               => _n('Comment', 'Comments', Session::getPluralNumber()),
            'datatype'           => 'text',
        ];

        $tab[] = [
            'id'                 => '11',
            'table'              => $this->getTable(),
            'field'              => 'size',
            'name'               => __('Size'),
            'datatype'           => 'decimal',
        ];

        $tab[] = [
            'id'                 => '41',
            'table'              => $this->getTable(),
            'field'              => 'have_micro',
            'name'               => __('Microphone'),
            'datatype'           => 'bool',
        ];

        $tab[] = [
            'id'                 => '42',
            'table'              => $this->getTable(),
            'field'              => 'have_speaker',
            'name'               => __('Speakers'),
            'datatype'           => 'bool',
        ];

        $tab[] = [
            'id'                 => '43',
            'table'              => $this->getTable(),
            'field'              => 'have_subd',
            'name'               => __('Sub-D'),
            'datatype'           => 'bool',
        ];

        $tab[] = [
            'id'                 => '44',
            'table'              => $this->getTable(),
            'field'              => 'have_bnc',
            'name'               => __('BNC'),
            'datatype'           => 'bool',
        ];

        $tab[] = [
            'id'                 => '45',
            'table'              => $this->getTable(),
            'field'              => 'have_dvi',
            'name'               => __('DVI'),
            'datatype'           => 'bool',
        ];

        $tab[] = [
            'id'                 => '46',
            'table'              => $this->getTable(),
            'field'              => 'have_pivot',
            'name'               => __('Pivot'),
            'datatype'           => 'bool',
        ];

        $tab[] = [
            'id'                 => '47',
            'table'              => $this->getTable(),
            'field'              => 'have_hdmi',
            'name'               => __('HDMI'),
            'datatype'           => 'bool',
        ];

        $tab[] = [
            'id'                 => '48',
            'table'              => $this->getTable(),
            'field'              => 'have_displayport',
            'name'               => __('DisplayPort'),
            'datatype'           => 'bool',
        ];

        $tab[] = [
            'id'                 => '23',
            'table'              => 'glpi_manufacturers',
            'field'              => 'name',
            'name'               => Manufacturer::getTypeName(1),
            'datatype'           => 'dropdown',
        ];

        $tab[] = [
            'id'                 => '24',
            'table'              => 'glpi_users',
            'field'              => 'name',
            'linkfield'          => 'users_id_tech',
            'name'               => __('Technician in charge'),
            'datatype'           => 'dropdown',
            'right'              => 'own_ticket',
        ];

        $tab[] = [
            'id'                 => '49',
            'table'              => 'glpi_groups',
            'field'              => 'completename',
            'linkfield'          => 'groups_id',
            'name'               => __('Group in charge'),
            'condition'          => ['is_assign' => 1],
            'joinparams'         => [
                'beforejoin'         => [
                    'table'              => 'glpi_groups_items',
                    'joinparams'         => [
                        'jointype'           => 'itemtype_item',
                        'condition'          => ['NEWTABLE.type' => Group_Item::GROUP_TYPE_TECH],
                    ],
                ],
            ],
            'forcegroupby'       => true,
            'massiveaction'      => false,
            'datatype'           => 'dropdown',
        ];

        $tab[] = [
            'id'                 => '61',
            'table'              => $this->getTable(),
            'field'              => 'template_name',
            'name'               => __('Template name'),
            'datatype'           => 'text',
            'massiveaction'      => false,
            'nosearch'           => true,
            'nodisplay'          => true,
        ];

        $tab[] = [
            'id'                 => '80',
            'table'              => 'glpi_entities',
            'field'              => 'completename',
            'name'               => Entity::getTypeName(1),
            'massiveaction'      => false,
            'datatype'           => 'dropdown',
        ];

        $tab[] = [
            'id'                 => '82',
            'table'              => $this->getTable(),
            'field'              => 'is_global',
            'name'               => __('Global management'),
            'datatype'           => 'bool',
            'massiveaction'      => false,
        ];

        $tab = array_merge($tab, Notepad::rawSearchOptionsToAdd());

        $tab = array_merge($tab, Datacenter::rawSearchOptionsToAdd(get_class($this)));

        $tab = array_merge($tab, Rack::rawSearchOptionsToAdd(get_class($this)));

        $tab = array_merge($tab, MonitorModel::rawSearchOptionsToAdd());

        $tab = array_merge($tab, DCRoom::rawSearchOptionsToAdd());

        return $tab;
    }

    /**
     * @param $itemtype
     *
     * @return array
     */
    public static function rawSearchOptionsToAdd($itemtype = null)
    {
        $tab = [];

        $tab[] = [
            'id'                 => 'monitor',
            'name'               => self::getTypeName(Session::getPluralNumber()),
        ];

        $tab[] = [
            'id'                 => '1429',
            'table'              => Asset_PeripheralAsset::getTable(),
            'field'              => 'id',
            'name'               => _x('quantity', 'Number of monitors'),
            'forcegroupby'       => true,
            'usehaving'          => true,
            'datatype'           => 'count',
            'massiveaction'      => false,
            'joinparams'         => [
                'jointype'                  => 'itemtype_item',
                'specific_items_id_column'  => 'items_id_asset',
                'specific_itemtype_column'  => 'itemtype_asset',
                'condition'                 => ['NEWTABLE.' . 'itemtype_peripheral' => 'Monitor'],
            ],
        ];

        return $tab;
    }

    public static function getIcon()
    {
        return "ti ti-device-desktop";
    }
}
