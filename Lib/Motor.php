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

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Tools;

/**
 * **Lo único del SQL del plugin que MySQL/MariaDB y PostgreSQL escriben distinto.**
 *
 * El resto de las vistas usa SQL que vale en los dos: textos con comilla simple —con comillas dobles
 * PostgreSQL lee un identificador—, booleanos pasados a 0/1 con un `CASE` y `CONCAT`/`CONCAT_WS`, que
 * existen en los dos. Lo que no tiene forma común está aquí, en una sola rama por motor, como hace el
 * core con `Tools::config('db_type')`:
 *
 * - **Comparar distinguiendo mayúsculas.** En MySQL/MariaDB la comparación sigue la colación de la
 *   columna, que en FacturaScripts no distingue, así que hace falta `BINARY`. En PostgreSQL ya distingue,
 *   y `BINARY` no existe.
 * - **Juntar valores en una lista.** `GROUP_CONCAT` es de MySQL/MariaDB; PostgreSQL tiene `STRING_AGG`.
 */
class Motor
{
    /** `expresion = texto` distinguiendo mayúsculas en los dos motores. */
    public static function igualExacto(string $expresion, string $texto): string
    {
        return (self::esPostgresql() ? '' : 'BINARY ') . $expresion . ' = ' . self::texto($texto);
    }

    /**
     * Los valores distintos de la expresión, ordenados y separados por comas. Los `NULL` no entran, igual
     * en los dos motores.
     */
    public static function lista(string $expresion): string
    {
        // con DISTINCT, PostgreSQL exige que el ORDER BY sea la misma expresión que se agrega
        return self::esPostgresql()
            ? 'STRING_AGG(DISTINCT ' . $expresion . ", ',' ORDER BY " . $expresion . ')'
            : 'GROUP_CONCAT(DISTINCT ' . $expresion . ' ORDER BY ' . $expresion . ')';
    }

    /** Un texto como literal SQL: entre comillas simples y escapado por el motor. */
    public static function texto(string $texto): string
    {
        return "'" . (new DataBase())->escapeString($texto) . "'";
    }

    private static function esPostgresql(): bool
    {
        return 'postgresql' === strtolower((string)Tools::config('db_type'));
    }
}
