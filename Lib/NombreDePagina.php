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

namespace FacturaScripts\Plugins\OkoPermisos\Lib;

use FacturaScripts\Core\Tools;

/**
 * **El nombre de una página para LEERLO y ORDENARLO: el prefijo de acción va al final.**
 *
 * Los controladores de FacturaScripts empiezan por lo que hacen —`ListUser`, `EditUser`, `ConfigEmail`—,
 * así que ordenados por nombre se agrupan por acción y la misma cosa queda repartida: `ConfigEmail` lejos
 * de `EditEmailSent`. Girado, `EmailConfig (ConfigEmail)` y `EmailSentEdit (EditEmailSent)` salen juntas,
 * y el controlador real va entre paréntesis porque es el que se concede en la ficha del rol.
 *
 * **La lista de prefijos es cerrada y configurable** (Panel de control, pestaña de OkoPermisos): girar la
 * primera palabra de cualquier nombre rompe los que no empiezan por una acción —`EmployeePanel`,
 * `UserChat`— y no casa los que son una sigla. Un prefijo nuevo es una opción de la instalación, no una
 * versión del plugin.
 *
 * **El grupo de ajustes es propio** (`okopermisosprefijos`, del fichero `SettingsOkoPermisosPrefijos.xml`):
 * `EditSettings` toma el grupo del nombre del fichero, y el grupo `okopermisos` lo leen otros plugins
 * —la migración de los permisos especiales del diseño anterior—, así que esta pestaña no puede escribir
 * en él.
 *
 * Un prefijo sólo gira si detrás viene una MAYÚSCULA: `Newsletter` no es «New» + «sletter».
 */
class NombreDePagina
{
    public const AJUSTES = 'okopermisosprefijos';

    public const CLAVE = 'prefijos';

    public const POR_DEFECTO = 'List, Edit, Config, Report, Admin, Send, New, Export, Import';

    /** @var array<string, true> los prefijos no válidos ya avisados en esta petición */
    private static array $avisados = [];

    /**
     * Los prefijos de la instalación, del más largo al más corto para que uno no tape a otro. Sólo letras:
     * van dentro del SQL de `sql()`, así que lo demás se descarta, y **se avisa** —una vez por petición,
     * porque esto se llama una vez por expresión—: un prefijo mal escrito que no hace nada sin decirlo se
     * lee como «el giro no funciona». Una entrada vacía, como la de una coma de más, no es un error.
     *
     * @return string[]
     */
    public static function prefijos(): array
    {
        $valor = (string)Tools::settings(self::AJUSTES, self::CLAVE, '');
        $prefijos = [];
        foreach (explode(',', '' === trim($valor) ? self::POR_DEFECTO : $valor) as $prefijo) {
            $prefijo = trim($prefijo);
            if (preg_match('/^[A-Za-z]+$/', $prefijo)) {
                $prefijos[$prefijo] = strlen($prefijo);
            } elseif ('' !== $prefijo && false === isset(self::$avisados[$prefijo])) {
                self::$avisados[$prefijo] = true;
                Tools::log()->warning('okopermisos-prefijo-no-valido', ['%prefijo%' => $prefijo]);
            }
        }
        arsort($prefijos);

        return array_keys($prefijos);
    }

    /**
     * La ENTIDAD de la página, para el orden por defecto: el nombre sin el prefijo de acción —`EditUser`,
     * `ListUser` y `ReportUser` son «User»—. Sin prefijo de la lista, el nombre entero.
     */
    public static function entidadSql(string $columna): string
    {
        return self::segun($columna, static fn (string $prefijo, int $largo): string =>
            'SUBSTRING(' . $columna . ', ' . ($largo + 1) . ')', $columna);
    }

    /**
     * El PREFIJO de acción de la página, para ordenar dentro de su entidad. Una página sin prefijo de la
     * lista da cadena vacía, así que va la PRIMERA de su entidad: es la entidad misma, no una acción sobre
     * ella.
     */
    public static function prefijoSql(string $columna): string
    {
        return self::segun($columna, static fn (string $prefijo): string => '"' . $prefijo . '"', '""');
    }

    /**
     * La expresión SQL del nombre girado sobre una columna con el nombre de la página. MySQL/MariaDB,
     * como el resto de las vistas del plugin: `BINARY` hace la comparación sensible a mayúsculas.
     */
    public static function sql(string $columna): string
    {
        return self::segun($columna, static fn (string $prefijo, int $largo): string =>
            'CONCAT(SUBSTRING(' . $columna . ', ' . ($largo + 1) . '), "' . $prefijo . ' (", ' . $columna . ', ")")',
            $columna);
    }

    /**
     * Un `CASE` con una rama por prefijo de la instalación: la condición es la misma para las tres
     * expresiones —empieza por el prefijo y detrás viene una mayúscula—, y cambia sólo qué se devuelve.
     */
    private static function segun(string $columna, callable $entonces, string $sino): string
    {
        $casos = '';
        foreach (self::prefijos() as $prefijo) {
            $largo = strlen($prefijo);
            $casos .= ' WHEN BINARY LEFT(' . $columna . ', ' . $largo . ') = "' . $prefijo . '"'
                . ' AND BINARY SUBSTRING(' . $columna . ', ' . ($largo + 1) . ', 1) REGEXP "^[A-Z]$"'
                . ' THEN ' . $entonces($prefijo, $largo);
        }

        return '' === $casos ? $sino : '(CASE' . $casos . ' ELSE ' . $sino . ' END)';
    }
}
