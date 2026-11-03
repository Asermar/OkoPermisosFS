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

use FacturaScripts\Core\DbQuery;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\User;
use FacturaScripts\Plugins\OkoPermisos\Model\Join\PermisoEfectivo;
use FacturaScripts\Core\Tools;

/**
 * **El menú de FacturaScripts tal como lo vería un usuario**, con lo que no ve marcado al lado.
 *
 * Las dos listas del plugin responden por filas y eso vale para auditar, pero no para contestar «¿qué se
 * encuentra esta persona al entrar?». Esto arma el árbol —menú → submenú → página— con las entradas que
 * el usuario tiene y, al lado, las que no, para que la comparación esté delante y no en dos consultas.
 *
 * ## Se reconstruye el criterio del core, no se adivina
 *
 * `MenuManager` filtra las páginas por los accesos del usuario, se salta las de `menu` vacío y sólo pinta
 * las de `showonmenu = 1`. Aquí se usan las mismas dos condiciones, así que entra el mismo conjunto.
 *
 * **El orden no es el del SQL.** La consulta del core ordena por `lower(menu)`, `lower(submenu)`,
 * `ordernum` y `lower(title)`, pero luego `sortMenu()` hace un `uasort` con `strcasecmp` sobre el título
 * **ya traducido**, recursivamente en los tres niveles. Así que el orden que se ve es alfabético por
 * *traducción*, no por la clave: `admin` se lee «Administrador» y cae donde le toca por la A, no por la a
 * de su clave. Ordenar por la clave —que fue el primer intento— da un orden parecido y equivocado.
 *
 * Corolario que sorprende: ese `uasort` **pisa el `ordernum`**, así que en el menú real `ordernum` no
 * decide nada. Se conserva en la consulta sólo porque desempata, ya que los ordenamientos de PHP 8 son
 * estables.
 *
 * Y una diferencia deliberada más: en el core los submenús y las páginas sueltas de un menú se ordenan
 * **mezclados** en el mismo nivel, y aquí igual; lo que se añade es la cabecera del submenú, que la barra
 * real resuelve con un desplegable anidado.
 *
 * La diferencia deliberada: **también se traen las páginas SIN acceso**, que el menú del core nunca
 * pinta. Sin ellas la vista respondería «esto ve» pero no «esto le falta», que es la mitad de la
 * pregunta cuando se está repartiendo permisos.
 *
 * ## Un admin lo ve todo, y hay que decirlo
 *
 * Para un usuario con `admin = 1` el árbol real es «todo», sin que sus roles importen. Se marca aparte
 * en vez de pintarle todo en verde: lo que interesa de un admin es qué le quedaría **sin** el flag.
 */
class MenuDelUsuario
{
    /**
     * El árbol del menú para un usuario.
     *
     * @return array<string, array{titulo: string, tiene: int, total: int, hijos: array}>
     */
    public static function arbol(string $nick): array
    {
        $accesos = self::accesosDe($nick);

        $arbol = [];
        foreach (self::paginasDelMenu() as $pagina) {
            $menu = (string)$pagina['menu'];
            $submenu = (string)($pagina['submenu'] ?? '');

            if (false === isset($arbol[$menu])) {
                $arbol[$menu] = [
                    'titulo' => Tools::trans($menu),
                    'tiene' => 0,
                    'total' => 0,
                    'hijos' => [],
                ];
            }

            $entrada = self::entrada($pagina, $accesos);
            if ('' === $submenu) {
                $arbol[$menu]['hijos'][] = [
                    'tipo' => 'pagina',
                    'titulo' => $entrada['titulo'],
                    'pagina' => $entrada,
                ];
            } else {
                // la clave lleva prefijo para no chocar con los índices numéricos de las páginas sueltas,
                // que conviven con los submenús en este mismo nivel igual que en el menú real
                $clave = 'sub:' . $submenu;
                if (false === isset($arbol[$menu]['hijos'][$clave])) {
                    $arbol[$menu]['hijos'][$clave] = [
                        'tipo' => 'submenu',
                        'titulo' => Tools::trans($submenu),
                        'paginas' => [],
                    ];
                }
                $arbol[$menu]['hijos'][$clave]['paginas'][] = $entrada;
            }

            $arbol[$menu]['total']++;
            if ($entrada['tiene']) {
                $arbol[$menu]['tiene']++;
            }
        }

        // el orden del menú real, que es por título TRADUCIDO y en los tres niveles
        self::ordenar($arbol);
        foreach ($arbol as &$menu) {
            self::ordenar($menu['hijos']);
            foreach ($menu['hijos'] as &$hijo) {
                if ('submenu' === $hijo['tipo']) {
                    self::ordenar($hijo['paginas']);
                }
            }
        }

        return $arbol;
    }

    /**
     * **El nodo «Permisos especiales»**: cada permiso instalado, con su estado para este usuario.
     *
     * Las páginas de permiso no están en el menú real —`showonmenu = 0`—, así que no salen en el árbol y
     * el nodo se arma aparte. Tener el permiso es tener `update` en su página; con sólo el acceso se ve
     * su hoja, en lectura, y se dice.
     *
     * Para un admin se evalúa **sin el flag**, igual que el resto del árbol: con él lo tendría siempre y
     * la vista no diría nada. Por eso sale de los roles —`accesosDe()`— y no de `User::can()`.
     *
     * @return array<int, array{pagina: string, titulo: string, plugin: string, tiene: bool, solo_hoja: bool, roles: string}>
     */
    public static function especiales(string $nick): array
    {
        $accesos = self::accesosDe($nick);
        $especiales = [];
        foreach (PermisoEspecial::instalados() as $permiso) {
            $acceso = $accesos[$permiso['pagina']] ?? null;
            $tiene = null !== $acceso && 1 === (int)$acceso['puede_escribir'];
            $especiales[] = $permiso + [
                'tiene' => $tiene,
                'solo_hoja' => null !== $acceso && false === $tiene,
                'roles' => null === $acceso ? '' : (string)$acceso['roles'],
            ];
        }

        return $especiales;
    }

    /**
     * Lo que el usuario tiene, indexado por página.
     *
     * **Reutiliza `Join\PermisoEfectivo`** en vez de repetir su consulta: ahí ya está el agregado por el
     * valor más permisivo y la subconsulta derivada que lo hace filtrable. Repetirlo aquí sería tener dos
     * verdades sobre el mismo cálculo, y la segunda se descubriría rota el día que se cambie una.
     *
     * (El primer intento lo hizo con `DbQuery`, que **no tiene `join()`** —comprobado en el core—, así
     * que ni habría corrido.)
     *
     * @return array<string, array<string, mixed>>
     */
    private static function accesosDe(string $nick): array
    {
        $porPagina = [];
        // Sólo lo que dan los ROLES: la vista tiene también las páginas sin acceso, y aquí al admin se le
        // evalúa sin el flag. Por eso `cuantos_roles` y no `tiene_acceso`, y `roles_por_rol` sin «(admin)».
        $where = [Where::eq('e.nick', $nick), Where::gt('e.cuantos_roles', 0)];
        foreach ((new PermisoEfectivo())->all($where, [], 0, 0) as $fila) {
            $porPagina[(string)$fila->pagina] = [
                'roles' => (string)$fila->roles_por_rol,
                'puede_escribir' => (int)$fila->puede_escribir,
                'puede_borrar' => (int)$fila->puede_borrar,
                'puede_exportar' => (int)$fila->puede_exportar,
                'puede_importar' => (int)$fila->puede_importar,
                'solo_lo_suyo' => (int)$fila->solo_lo_suyo,
            ];
        }

        return $porPagina;
    }

    /**
     * Una página del árbol: lo que se pinta en su fila.
     *
     * @param array<string, mixed> $pagina
     * @param array<string, array<string, mixed>> $accesos
     * @return array<string, mixed>
     */
    private static function entrada(array $pagina, array $accesos): array
    {
        $nombre = (string)$pagina['name'];
        $acceso = $accesos[$nombre] ?? null;

        return [
            'nombre' => $nombre,
            'titulo' => Tools::trans((string)$pagina['title']),
            // el mismo respaldo que `MenuItem`, para que una página sin icono no salga descolocada
            'icono' => '' === (string)$pagina['icon'] ? 'fa-solid fa-file' : (string)$pagina['icon'],
            'tiene' => null !== $acceso,
            // las etiquetas sólo se calculan si hay acceso: sin él no significan nada
            'etiquetas' => null === $acceso ? [] : self::etiquetas($acceso),
            'roles' => null === $acceso ? '' : (string)$acceso['roles'],
        ];
    }

    /**
     * Las etiquetas de lo que puede hacer en una página.
     *
     * `Lectura` es lo que hay cuando no hay nada más: tener el acceso ya permite entrar y mirar. Las
     * demás se añaden sólo si el permiso está, para que una fila con muchas etiquetas salte a la vista.
     *
     * @param array<string, mixed> $acceso
     * @return array<int, array{texto: string, clase: string}>
     */
    private static function etiquetas(array $acceso): array
    {
        $etiquetas = [['texto' => Tools::trans('okopermisos-et-lectura'), 'clase' => 'secondary']];

        // el orden va de menos a más grave: lo que más pesa queda a la derecha, donde se mira
        $mapa = [
            'puede_exportar' => ['okopermisos-et-exportar', 'info'],
            'puede_importar' => ['okopermisos-et-importar', 'warning'],
            'puede_escribir' => ['okopermisos-et-escritura', 'primary'],
            'puede_borrar' => ['okopermisos-et-borrado', 'danger'],
        ];
        foreach ($mapa as $campo => [$clave, $clase]) {
            if (1 === (int)($acceso[$campo] ?? 0)) {
                $etiquetas[] = ['texto' => Tools::trans($clave), 'clase' => $clase];
            }
        }

        // ésta RESTRINGE, así que se pinta distinta: no es un permiso más, es un límite
        if (1 === (int)($acceso['solo_lo_suyo'] ?? 0)) {
            $etiquetas[] = ['texto' => Tools::trans('okopermisos-et-solo-suyo'), 'clase' => 'dark'];
        }

        return $etiquetas;
    }

    /**
     * El criterio del core, literal: `strcasecmp` sobre el título traducido, conservando las claves.
     *
     * @param array<string|int, array{titulo: string}> $lista
     */
    private static function ordenar(array &$lista): void
    {
        uasort($lista, function (array $uno, array $otro): int {
            return strcasecmp($uno['titulo'], $otro['titulo']);
        });
    }

    /**
     * Las páginas que el core pintaría en el menú: `showonmenu = 1` y `menu` no vacío, en su orden.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function paginasDelMenu(): array
    {
        return DbQuery::table('pages')
            ->select('name, title, menu, submenu, ordernum, icon')
            ->where([
                Where::eq('showonmenu', true),
                Where::isNotNull('menu'),
                Where::notEq('menu', ''),
            ])
            // `orderMulti`, no `orderBy`: éste último toma un solo campo, y aquí hacen falta cuatro
            ->orderMulti([
                'LOWER(menu)' => 'ASC',
                'LOWER(submenu)' => 'ASC',
                'ordernum' => 'ASC',
                'LOWER(title)' => 'ASC',
            ])
            ->get();
    }
}
