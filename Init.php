<?php
/**
 * This file is part of OkoPermisos plugin for FacturaScripts
 * Copyright (C) 2026 Oko Digital Experts, S.L.L. (Okodex)
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 * @author Alexis Serafín <alexis@okodex.com>
 */

namespace FacturaScripts\Plugins\OkoPermisos;

use FacturaScripts\Core\Html;
use FacturaScripts\Core\Template\InitClass;
use FacturaScripts\Plugins\OkoPermisos\Lib\PermisosEspeciales;
use Twig\TwigFunction;

/**
 * **Este plugin no crea ni una tabla.** Lee `roles`, `roles_access`, `roles_users`, `users` y `pages`,
 * que son del core, y no escribe en ninguna. De ahí que no haya `Table/` ni migraciones: si algún día
 * aparecen, será porque dejó de ser de sólo lectura, y eso es otra decisión.
 */
class Init extends InitClass
{
    public function init(): void
    {
        // el aviso de la API retirada en el gestor de plugins: lo pinta Extension/View/MenuTemplate_HeadEnd.
        // El gancho sólo incluye la llamada si existe @OkoPermisosAviso, que se registra aquí con la función:
        // en la petición que activa el plugin este Init no corre, y así no se pide una función que no existe.
        Html::addFunction(new TwigFunction('okopermisosAvisarObsoletos', [PermisosEspeciales::class, 'avisarEnElGestor']));
        Html::addPath('OkoPermisosAviso', __DIR__ . '/View');
    }

    public function uninstall(): void
    {
    }

    public function update(): void
    {
    }
}
