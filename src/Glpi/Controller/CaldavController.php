<?php

/**
 * ---------------------------------------------------------------------
 *
 * GLPI - Gestionnaire Libre de Parc Informatique
 *
 * http://glpi-project.org
 *
 * @copyright 2015-2025 Teclib' and contributors.
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

namespace Glpi\Controller;

use Glpi\CalDAV\Server;
use Glpi\Http\HeaderlessStreamedResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CaldavController extends AbstractController
{
    #[Route(
        "/caldav.php{request_parameters}",
        name: "glpi_caldav",
        requirements: [
            'request_parameters' => '.*',
        ]
    )]
    public function __invoke(Request $request): Response
    {
        // @phpstan-ignore-next-line method.deprecatedClass (refactoring is planned later)
        return new HeaderlessStreamedResponse(function () {
            /** @var array $CFG_GLPI */
            global $CFG_GLPI;

            $server = new Server();
            $server->setBaseUri($CFG_GLPI['root_doc'] . '/caldav.php');
            $server->start();
        });
    }
}
