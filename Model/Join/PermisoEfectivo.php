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
use FacturaScripts\Plugins\OkoPermisos\Lib\NombreDePagina;

/**
 * **Lo que un usuario puede hacer de verdad, y por qué rol le llega.**
 *
 * En FacturaScripts los permisos **sólo** vienen de roles: no hay tabla usuario–página, así que la
 * única «asignación directa» que existe es el flag `users.admin`, que salta todo. Por eso esta vista
 * no distingue dos orígenes —no los hay— sino **cuántos y cuáles** roles conceden lo mismo, que es la
 * pregunta que bloquea una poda: si dos roles dan el mismo acceso, quitarlo de uno no cambia nada.
 *
 * Y es lo habitual: en una instalación con años de roles acumulados, la mayoría de las páginas de un
 * usuario le llegan por más de un rol, y eso hace inútil quitar permisos de a uno.
 *
 * ## La agregación va en una SUBCONSULTA DERIVADA, y no es un adorno
 *
 * Lo natural sería agrupar en la consulta principal con `getGroupFields()`. Se hizo así al principio y
 * hubo que rehacerlo: `JoinModel` construye `WHERE … GROUP BY …` y **no genera `HAVING`**, así que un
 * campo agregado no se puede filtrar —`WHERE MAX(...) = 0` es SQL inválido— y `Where` no admite SQL
 * crudo con el que esquivarlo. Con la agregación dentro del `FROM`, los flags son **columnas normales**
 * de la derivada y se filtran con un `Where::eq` cualquiera. Es el mismo patrón que ya se usó en
 * `BuscadorAcumulado` por otro motivo.
 *
 * Y hay una diferencia que importa para el filtro «no puede escribir»: filtrar `ra.allowupdate = 0`
 * **antes** de agrupar daría los usuarios que tienen *algún* rol sin escritura, aunque otro se la
 * conceda. Con la derivada se filtra el valor ya agregado, que es el permiso de verdad.
 *
 * ## El efectivo de cada flag es el MÁS PERMISIVO
 *
 * De ahí los `MAX()` sobre `allowupdate`, `allowdelete`, `allowexport` e `allowimport`: un rol
 * restrictivo **no restringe** si otro concede. Y `onlyownerdata` va con `MIN()` porque es al revés
 * —restringe— y basta un rol sin la restricción para que deje de aplicarse.
 *
 * **Ojo con exportar e importar**: `RoleAccess::clear()` los pone a `true` por omisión, así que un
 * acceso creado sin tocarlos permite sacar los datos aunque no permita modificarlos, y es fácil que
 * haya accesos así sin que nadie lo decidiera.
 *
 * ## «Entrar» y «escribir» son cosas distintas
 *
 * `allowAccess` decide si el usuario **entra** en la página; `allowupdate` si **guarda**. Un rol con
 * la página y sin `allowupdate` entra y mira. Se separan en columnas a propósito: confundirlos es lo
 * que llevó a buscar restricciones inexistentes en el core cuando lo que faltaba era el acceso a la
 * ficha.
 *
 * ## `en_el_menu` es del USUARIO: si la página le sale en SU menú
 *
 * Esta vista busca una representación fiel de los permisos efectivos del usuario, así que «Visible en
 * el menú» responde por él y no por la página: **sí** sólo si la página sale en el menú
 * (`showonmenu = 1` y `menu` no vacío, las dos condiciones de `MenuManager`) **y** el usuario tiene
 * acceso. Es el mismo criterio que `MenuDelUsuario`. Para el admin, que tiene acceso a todo, coincide
 * con la propiedad de la página.
 *
 * Fue `pages.showonmenu` a secas, y con las filas sin acceso eso mentía: una lista de menú que el
 * usuario no tiene salía «visible: sí» sin que la viera nunca. Lo que responde cada columna: **«Tiene
 * acceso»**, si *puede* entrar; **«Visible en el menú»**, si *lo encuentra*. Acceso sin visible significa
 * que sólo llega pinchando desde una lista.
 *
 * El filtro «sólo menú / sólo fichas» del panel sigue mirando la PÁGINA (`p.showonmenu`): separa listas
 * de fichas, que es otra pregunta.
 *
 * ## `ListCliente`, `EditCliente` y `ReportCliente` salen juntas
 *
 * Alfabéticamente están lejos y son la misma cosa. El campo `entidad` quita el prefijo de acción —con la
 * lista configurable de `Lib/NombreDePagina`, la misma de la columna «Página»— para que ordenen juntas,
 * y `prefijo` las ordena dentro por orden alfabético: Edit antes que List, igual que se leen en la
 * columna. Una página sin prefijo de la lista va la primera de su entidad: es la entidad misma.
 *
 * **Y las entidades se ordenan alfabéticamente, no por el `ordernum` ni por el título.** Hubo un orden
 * por el `ordernum` y el título de la LISTA de cada entidad —un `JOIN` a `pages pl`—, para imitar el
 * recorrido del menú. Se retiró: casi todas las páginas tienen el mismo `ordernum`, así que mandaba el
 * título, y `pages.title` es la clave de traducción SIN traducir —`city`, `countries`—, no lo que se lee.
 * El resultado parecía desordenado. El orden del menú real sigue a un clic, como orden alternativo.
 *
 * Una página de la entidad que vive en otro menú —un informe en «Informes»— sigue en su menú: agrupar
 * por menú va antes que emparejar.
 *
 * ## Una fila por usuario × página, con o sin acceso: `tiene_acceso` dice cuál
 *
 * Al principio la consulta partía de `roles_users → roles_access`, así que **cada fila era un acceso** y
 * la lista no lo decía en ningún sitio: «Visible en el menú» se leía como si fuera el acceso. El caso que
 * lo destapó: un usuario con una ficha concedida por un rol, fuera del menú y con todos los flags a «no»
 * ENTRA y no puede guardar, y la fila no explicaba por qué estaba ahí.
 *
 * Ahora el universo es **usuarios × páginas** y los accesos agregados se unen por la izquierda:
 * `tiene_acceso` es 1 si algún rol del usuario tiene la página, o si el usuario es admin —es lo que hace
 * `User::can()`: el admin pasa en cualquier página que exista—. Las filas sin acceso llevan los flags a 0
 * y sin roles. El panel arranca filtrando «Tiene acceso: sí», que es la lista de antes.
 *
 * **Para el admin, los flags y los roles siguen siendo los de sus roles**, y la columna de roles lleva
 * «(admin)» delante: lo que interesa de un admin es qué le quedaría sin el flag, como en la Vista del
 * menú. `cuantos_roles` y `roles_por_rol` son los campos para quien quiera sólo lo que dan los roles.
 *
 * **Quien reutiliza esta vista tiene que filtrar**: sin un `Where` sobre `e.tiene_acceso` o
 * `e.cuantos_roles`, cuenta también las páginas que el usuario NO tiene. `MenuDelUsuario::accesosDe()`
 * filtra por `cuantos_roles > 0` (evalúa al admin sin el flag) y `PermisoEspecial::titularesDe()` por
 * `puede_escribir = 1`, que en una fila sin acceso es 0.
 *
 * **Un acceso a una página que ya no existe** —una fila de `roles_access` huérfana— sigue saliendo, pero
 * sólo a quien la tiene concedida: el universo de páginas suma esos nombres con la marca `existe = 0`, y
 * dentro de la derivada se descartan las combinaciones huérfanas sin acceso. Va dentro y no en un `WHERE`
 * exterior porque ése lo pone `JoinModel`.
 *
 * ## Las fichas cuentan, aunque no salgan en ningún menú
 *
 * Se incluyen las páginas con `showonmenu = 0`, que suelen ser la mayoría. Son las fichas —`EditCliente`, `EditProducto`…— y son la mitad invisible del problema: nadie
 * las asigna porque no aparecen en el menú, y son las que cortan la navegación desde una lista.
 */
class PermisoEfectivo extends JoinModel
{
    public function clear(): void
    {
        parent::clear();
        $this->nick = '';
        $this->pagina = '';
    }

    protected function getFields(): array
    {
        return [
            // el id es sintético: esta vista no tiene fila propia en ninguna tabla
            'id' => 'CONCAT(e.nick, "|", e.pagename)',
            'nick' => 'e.nick',
            'pagina' => 'e.pagename',
            // el que se LEE y por el que se ORDENA: «EmailConfig (ConfigEmail)». `pagina` sigue siendo el real
            'pagina_nombre' => NombreDePagina::sql('e.pagename'),
            'titulo' => 'p.title',
            'menu' => 'p.menu',
            'submenu' => 'p.submenu',
            // del usuario: la página sale en el menú Y él tiene acceso (ver el docblock)
            'en_el_menu' => 'CASE WHEN p.showonmenu = 1 AND p.menu IS NOT NULL AND p.menu <> ""'
                . ' AND e.tiene_acceso = 1 THEN 1 ELSE 0 END',
            // La entidad a la que se refiere la página: `ListCliente`, `EditCliente` y `ReportCliente` son
            // «Cliente», así que ordenando por aquí salen juntas. Con la lista de prefijos de la instalación,
            // la misma de la columna «Página» (`Lib/NombreDePagina`).
            'entidad' => NombreDePagina::entidadSql('e.pagename'),
            // y dentro de la entidad, sus prefijos por orden alfabético —Edit antes que List, como en la
            // columna «Página»—; la que no tiene prefijo, primero
            'prefijo' => NombreDePagina::prefijoSql('e.pagename'),
            'tiene_acceso' => 'e.tiene_acceso',
            // la columna que se ve: «(admin)» delante si lo es, porque entonces tiene acceso por el flag
            'roles' => 'CONCAT_WS(" ", CASE WHEN e.es_admin = 1 THEN "(admin)" END, e.roles)',
            // sólo lo que dan los roles, sin la marca: para quien evalúa al admin sin el flag
            'roles_por_rol' => 'e.roles',
            // los mismos roles cercados por comas, para poder filtrar por UNO sin casar de más
            'roles_cercado' => 'e.roles_cercado',
            'cuantos_roles' => 'e.cuantos_roles',
            'puede_escribir' => 'e.puede_escribir',
            'puede_borrar' => 'e.puede_borrar',
            'puede_exportar' => 'e.puede_exportar',
            'puede_importar' => 'e.puede_importar',
            'solo_lo_suyo' => 'e.solo_lo_suyo',
        ];
    }

    protected function getSQLFrom(): string
    {
        return '(SELECT u.nick, pg.name AS pagename,'
            . '   CASE WHEN u.admin = 1 THEN 1 ELSE 0 END AS es_admin,'
            // como `User::can()`: el admin pasa en toda página; los demás, si algún rol la tiene
            . '   CASE WHEN u.admin = 1 OR a.nick IS NOT NULL THEN 1 ELSE 0 END AS tiene_acceso,'
            . '   a.roles,'
            . '   COALESCE(a.cuantos_roles, 0) AS cuantos_roles,'
            . '   COALESCE(a.roles_cercado, "") AS roles_cercado,'
            . '   COALESCE(a.puede_escribir, 0) AS puede_escribir,'
            . '   COALESCE(a.puede_borrar, 0) AS puede_borrar,'
            . '   COALESCE(a.puede_exportar, 0) AS puede_exportar,'
            . '   COALESCE(a.puede_importar, 0) AS puede_importar,'
            . '   COALESCE(a.solo_lo_suyo, 0) AS solo_lo_suyo'
            . ' FROM users u'
            // el universo de páginas: las que existen, más los nombres de accesos huérfanos
            . ' CROSS JOIN (SELECT name, 1 AS existe FROM pages'
            . '   UNION ALL SELECT DISTINCT pagename, 0 FROM roles_access'
            . '   WHERE pagename NOT IN (SELECT name FROM pages)) pg'
            . ' LEFT JOIN (SELECT ru.nick, ra.pagename,'
            . '   GROUP_CONCAT(DISTINCT ra.codrole ORDER BY ra.codrole) AS roles,'
            . '   COUNT(DISTINCT ra.codrole) AS cuantos_roles,'
            // «,ventas,» no casa «,ventasconsulta,»: sin las comas, filtrar por un rol traería también
            // los que empiezan igual (los nombres son de ejemplo)
            . '   CONCAT(",", GROUP_CONCAT(DISTINCT ra.codrole ORDER BY ra.codrole), ",") AS roles_cercado,'
            // el más permisivo gana: un rol restrictivo no restringe si otro concede
            . '   MAX(ra.allowupdate) AS puede_escribir,'
            . '   MAX(ra.allowdelete) AS puede_borrar,'
            . '   MAX(ra.allowexport) AS puede_exportar,'
            . '   MAX(ra.allowimport) AS puede_importar,'
            // ésta restringe, así que basta un rol sin ella para que no aplique
            . '   MIN(ra.onlyownerdata) AS solo_lo_suyo'
            . '   FROM roles_users ru'
            . '   JOIN roles_access ra ON ra.codrole = ru.codrole'
            . '   GROUP BY ru.nick, ra.pagename) a ON a.nick = u.nick AND a.pagename = pg.name'
            // una página huérfana sólo sale a quien la tiene concedida
            . ' WHERE pg.existe = 1 OR a.nick IS NOT NULL) e'
            . ' LEFT JOIN pages p ON p.name = e.pagename';
    }

    protected function getTables(): array
    {
        return ['users', 'roles_users', 'roles_access', 'pages'];
    }
}
