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

namespace FacturaScripts\Plugins\OkoPermisos\Model\Join;

use FacturaScripts\Core\Template\JoinModel;
use FacturaScripts\Plugins\OkoPermisos\Lib\Motor;
use FacturaScripts\Plugins\OkoPermisos\Lib\NombreDePagina;

/**
 * **Si quito este acceso de este rol, ¿quién lo pierde DE VERDAD?**
 *
 * Es la pregunta que la interfaz del core no responde, y por la que una poda de permisos se hace a
 * ciegas: al desmarcar una casilla no se sabe si el usuario sigue teniendo el acceso por otro rol. Un
 * usuario sólo lo pierde si **ningún otro** de sus roles se lo concede.
 *
 * Por ejemplo: quitar `EditCliente` de un rol con tres usuarios puede afectar sólo a dos, porque el
 * tercero lo tiene también por otro rol y no notaría nada. Desmarcar la casilla y suponer que se cerró
 * el acceso es el error que esto evita.
 *
 * ## Los admin no cuentan
 *
 * Se excluyen con `u.admin = FALSE`: para ellos `allowAccess` es `true` pase lo que pase en los roles, así
 * que incluirlos inflaría «usuarios afectados» con gente a la que no le cambia nada. Si el objetivo es
 * cerrar un acceso de verdad, el flag `admin` es otra conversación.
 *
 * ## Los WHERE sobre esta vista van CUALIFICADOS
 *
 * `codrole` existe en `roles_access`, en `roles_users` y en la subconsulta, así que un
 * `Where::eq('codrole', …)` sale con «Column 'codrole' in WHERE is ambiguous» — el WHERE se aplica
 * sobre las tablas, no sobre los alias del SELECT, que ningún motor admite ahí. Se filtra por
 * **`ra.codrole`**. Con `nick` no hace falta porque sólo está en una tabla del FROM, pero cualificar
 * de más nunca rompe.
 *
 * ## Por qué no dice «esto no se usa»
 *
 * Porque no se puede saber. `logs` registra escrituras, logins y errores —no navegación—, así que
 * medir «páginas visitadas» ahí da por «sin usar» la mayoría de los permisos, que en realidad son de
 * lectura y se ejercen con normalidad. Se comprobó y se descartó: esta herramienta responde por
 * **redundancia**, que es exacta, y no por uso, que sería una conjetura con formato de tabla.
 */
class ImpactoDeQuitar extends JoinModel
{
    public function clear(): void
    {
        parent::clear();
        $this->codrole = '';
        $this->pagina = '';
    }

    protected function getFields(): array
    {
        return [
            'id' => "CONCAT(ra.codrole, '|', ra.pagename)",
            'codrole' => 'ra.codrole',
            'pagina' => 'ra.pagename',
            // el que se LEE y por el que se ORDENA: «EmailConfig (ConfigEmail)». `pagina` sigue siendo el real
            'pagina_nombre' => NombreDePagina::sql('ra.pagename'),
            // el mismo, en minúsculas, para ORDENAR: PostgreSQL no admite `LOWER(pagina_nombre)` en el ORDER BY
            'pagina_orden' => 'LOWER(' . NombreDePagina::sql('ra.pagename') . ')',
            'titulo' => 'p.title',
            // a 0/1 como los demás flags: crudo, PostgreSQL devuelve el booleano como 't' o 'f'
            'en_el_menu' => 'CASE WHEN p.showonmenu = TRUE THEN 1 ELSE 0 END',
            'usuarios_del_rol' => 'COUNT(DISTINCT ru.nick)',
            // los que NO lo tienen por otro rol: el `otro.nick IS NULL` es el corazón de la consulta
            'lo_perderian' => 'COUNT(DISTINCT CASE WHEN otro.nick IS NULL THEN ru.nick END)',
            'quienes' => Motor::lista('CASE WHEN otro.nick IS NULL THEN ru.nick END'),
            // PostgreSQL no tiene MAX de un booleano: se agrega como 0/1
            'puede_escribir' => 'MAX(CASE WHEN ra.allowupdate = TRUE THEN 1 ELSE 0 END)',
        ];
    }

    protected function getGroupFields(): string
    {
        return 'ra.codrole, ra.pagename, p.title, p.showonmenu';
    }

    protected function getSQLFrom(): string
    {
        return 'roles_access ra'
            . ' JOIN roles_users ru ON ru.codrole = ra.codrole'
            . ' JOIN users u ON u.nick = ru.nick AND u.admin = FALSE'
            . ' LEFT JOIN pages p ON p.name = ra.pagename'
            // la subconsulta trae, por usuario y página, los OTROS roles que se lo conceden
            . ' LEFT JOIN (SELECT DISTINCT ru2.nick, ra2.pagename, ra2.codrole'
            . '   FROM roles_users ru2 JOIN roles_access ra2 ON ra2.codrole = ru2.codrole) otro'
            . '   ON otro.nick = ru.nick AND otro.pagename = ra.pagename AND otro.codrole <> ra.codrole';
    }

    protected function getTables(): array
    {
        return ['roles_access', 'roles_users', 'users', 'pages'];
    }
}
