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

namespace FacturaScripts\Test\Plugins;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Cache;
use FacturaScripts\Core\Lib\MenuManager;
use FacturaScripts\Core\Request;
use FacturaScripts\Core\Response;
use FacturaScripts\Core\Session;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Controller\AdminPermisos;
use FacturaScripts\Dinamic\Model\Page;
use FacturaScripts\Dinamic\Model\Role;
use FacturaScripts\Dinamic\Model\RoleAccess;
use FacturaScripts\Dinamic\Model\RoleUser;
use FacturaScripts\Dinamic\Model\User;
use FacturaScripts\Plugins\OkoPermisos\Lib\MenuDelUsuario;
use FacturaScripts\Test\Traits\LogErrorsTrait;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionObject;

/**
 * **Que la vista del menú se parezca al menú, y que el botón lleve a quien dice.**
 *
 * Lo que se prueba aquí no es aritmética como en `PermisosEfectivosTest`, sino dos cosas que se
 * comprobaron a mano al escribirlas y que sin test volverían a romperse sin avisar:
 *
 * - **el orden**, que es el del core y no el que parece: `MenuManager::sortMenu()` ordena por el título
 *   ya **traducido**, no por la clave, así que ordenar por clave da un orden parecido y equivocado;
 * - **el destino del botón**, que se lee de la petición porque en `execPreviousAction` el filtro
 *   todavía está vacío — un fallo que no da error, sólo lleva a nadie.
 */
final class VistaDelMenuTest extends TestCase
{
    use LogErrorsTrait;

    // 17 caracteres: `pages.menu` es varchar(20), y 'okopermisosprueba-menu' se pasaba por dos
    private const MENU = 'okopermisosprueba';

    /**
     * @description
     * **El botón de la lista se lleva el usuario del filtro, y lee la PETICIÓN.**
     *
     * En `PanelController::privateCore` el `execPreviousAction` corre **antes** del `processFormData` que
     * puebla los filtros, así que preguntarle su valor al filtro devolvería vacío siempre: el botón
     * funcionaría sin dar ningún error y llevaría a la vista sin usuario. Por eso se lee `filternick`,
     * que es el nombre con que viaja el filtro (`BaseFilter::name()` le pone el prefijo `filter`).
     *
     * El control negativo es la mitad del test: con otra acción tiene que devolver `true` y no redirigir.
     */
    public function testElBotonSeLlevaElUsuarioDelFiltro(): void
    {
        $this->assertSame('AdminPermisos?activetab=VistaDelMenu&nick=okopermnayra', $this->destinoDelBoton(['filternick' => 'okopermnayra']),
            'con el filtro puesto, el botón se lo lleva');
        $this->assertSame('AdminPermisos?activetab=VistaDelMenu', $this->destinoDelBoton([]),
            'sin filtro, a la vista sin usuario: la elige allí');
        $this->assertSame('AdminPermisos?activetab=VistaDelMenu&nick=raro%2F%26%3Fx', $this->destinoDelBoton(['filternick' => 'raro/&?x']),
            'el nick va escapado, que para eso se compone una URL');

        // control negativo: otra acción no redirige y deja seguir el flujo normal
        $controlador = new AdminPermisos('AdminPermisos');
        $respuesta = $this->inyectar($controlador, []);
        $metodo = new ReflectionMethod($controlador, 'execPreviousAction');
        $metodo->setAccessible(true);
        $this->assertTrue($metodo->invoke($controlador, 'una-accion-cualquiera'));
        // `has()`, no `get()`: el captador devuelve cadena vacía cuando la cabecera no está, así que
        // un `assertNull` falla siempre y no distingue «no redirige» de «redirige a nada»
        $this->assertFalse($respuesta->headers->has('Refresh'), 'y no redirige a ninguna parte');
    }

    /**
     * @description
     * **El árbol sale en el orden del menú de verdad**, contrastado con el instrumento del core y no con
     * el propio criterio, que pasaría siempre.
     *
     * Se siembra un usuario con acceso a varias páginas reales, se arma el menú con `MenuManager` —que es
     * quien pinta la barra— y se compara con este árbol filtrado a lo concedido. Tienen que decir lo
     * mismo, submenús incluidos.
     */
    public function testElOrdenEsElDeMenuManager(): void
    {
        [$usuario, $rol] = $this->sembrar('okopermorden', 'okopermrolorden');

        // páginas REALES de varios menús: hacen falta títulos con traducción, que es lo que ordena
        $reales = [];
        foreach ((new Page())->all([Where::eq('showonmenu', true)], ['menu' => 'ASC', 'name' => 'ASC'], 0, 0) as $pagina) {
            if (false === isset($reales[$pagina->menu]) || count($reales[$pagina->menu]) < 3) {
                $reales[$pagina->menu][] = $pagina->name;
            }
        }
        $reales = array_slice($reales, 0, 4, true);
        $this->assertGreaterThanOrEqual(2, count($reales), 'la instalación tiene que traer varios menús');

        foreach ($reales as $paginas) {
            foreach ($paginas as $nombre) {
                $acceso = new RoleAccess();
                $acceso->codrole = $rol->codrole;
                $acceso->pagename = $nombre;
                $this->assertTrue($acceso->save(), 'no se pudo dar acceso a ' . $nombre);
            }
        }

        // MenuManager lee el usuario de Session en su constructor, y cachea: las dos cosas hay que hacerlas
        Session::set('user', $usuario);
        Cache::clear();

        $delCore = [];
        foreach (MenuManager::init()->getMenu() as $menu) {
            foreach ($menu->menu as $hijo) {
                if (empty($hijo->menu)) {
                    $delCore[] = $menu->title . ' / ' . $hijo->title;
                    continue;
                }
                foreach ($hijo->menu as $pagina) {
                    $delCore[] = $menu->title . ' / ' . $hijo->title . ' / ' . $pagina->title;
                }
            }
        }

        $delPlugin = [];
        foreach (MenuDelUsuario::arbol($usuario->nick) as $menu) {
            foreach ($menu['hijos'] as $hijo) {
                if ('submenu' === $hijo['tipo']) {
                    foreach ($hijo['paginas'] as $pagina) {
                        if ($pagina['tiene']) {
                            $delPlugin[] = $menu['titulo'] . ' / ' . $hijo['titulo'] . ' / ' . $pagina['titulo'];
                        }
                    }
                } elseif ($hijo['pagina']['tiene']) {
                    $delPlugin[] = $menu['titulo'] . ' / ' . $hijo['pagina']['titulo'];
                }
            }
        }

        $this->assertNotEmpty($delCore, 'control positivo: el menú del core no puede salir vacío');
        $this->assertSame($delCore, $delPlugin, 'el árbol tiene que decir lo mismo que MenuManager, y en el mismo orden');

        $this->limpiar($usuario, [$rol]);
    }

    /**
     * @description
     * **El orden es por título TRADUCIDO, no por la clave**, que es el fallo real que tuvo esta vista.
     *
     * El par está elegido para que los dos criterios discrepen: por clave `users` va antes que
     * `warehouse`, pero por traducción «Almacén» va antes que «Usuarios». Ordenando por clave —lo que
     * hacía la primera versión— este test sale al revés, así que no puede pasar por casualidad.
     */
    public function testElOrdenUsaLaTraduccionYNoLaClave(): void
    {
        [$usuario, $rol] = $this->sembrar('okopermtrad', 'okopermroltrad');
        $this->pagina('ListOkoPermisosPruebaUsuarios', 'users', $rol);
        $this->pagina('ListOkoPermisosPruebaAlmacen', 'warehouse', $rol);

        $arbol = MenuDelUsuario::arbol($usuario->nick);
        $this->assertArrayHasKey(self::MENU, $arbol, 'control positivo: el menú sembrado tiene que estar');

        $titulos = array_map(
            static fn (array $hijo): string => $hijo['pagina']['titulo'],
            array_values($arbol[self::MENU]['hijos'])
        );
        $this->assertSame(['Almacén', 'Usuarios'], $titulos,
            'por clave iría «users» antes que «warehouse»; por traducción va «Almacén» antes que «Usuarios»');

        $this->limpiar($usuario, [$rol]);
    }

    /**
     * @description
     * **Lo que el usuario NO ve también sale**, que es la diferencia deliberada con el menú del core: un
     * árbol con sólo lo concedido responde la mitad de la pregunta. Y cada entrada lleva **el icono de su
     * página**, que es lo que hace que la vista se parezca al menú de verdad.
     */
    public function testTraeLoDenegadoYElIconoDeCadaPagina(): void
    {
        [$usuario, $rol] = $this->sembrar('okopermdeneg', 'okopermroldeneg');
        $this->pagina('ListOkoPermisosPruebaConcedida', 'users', $rol, 'fa-solid fa-vial');
        $this->pagina('ListOkoPermisosPruebaDenegada', 'warehouse', null, '');

        $arbol = MenuDelUsuario::arbol($usuario->nick);
        $this->assertSame(2, $arbol[self::MENU]['total'], 'cuenta las dos, tenga acceso o no');
        $this->assertSame(1, $arbol[self::MENU]['tiene'], 'y sólo una concedida');

        $porNombre = [];
        foreach ($arbol[self::MENU]['hijos'] as $hijo) {
            $porNombre[$hijo['pagina']['nombre']] = $hijo['pagina'];
        }

        $this->assertTrue($porNombre['ListOkoPermisosPruebaConcedida']['tiene']);
        $this->assertSame('fa-solid fa-vial', $porNombre['ListOkoPermisosPruebaConcedida']['icono'],
            'el icono sale de pages.icon, no de un valor fijo');
        $this->assertNotEmpty($porNombre['ListOkoPermisosPruebaConcedida']['etiquetas'],
            'con acceso hay etiquetas: al menos la de lectura');

        $this->assertFalse($porNombre['ListOkoPermisosPruebaDenegada']['tiene'], 'la denegada sale, y marcada');
        $this->assertSame([], $porNombre['ListOkoPermisosPruebaDenegada']['etiquetas'],
            'sin acceso no hay etiquetas: no significarían nada');
        $this->assertSame('fa-solid fa-file', $porNombre['ListOkoPermisosPruebaDenegada']['icono'],
            'una página sin icono cae en el mismo respaldo que usa MenuItem');

        $this->limpiar($usuario, [$rol]);
    }

    /** El destino al que redirige el botón, invocando el código real con una petición de verdad. */
    private function destinoDelBoton(array $post): string
    {
        $controlador = new AdminPermisos('AdminPermisos');
        $respuesta = $this->inyectar($controlador, $post);

        $metodo = new ReflectionMethod($controlador, 'execPreviousAction');
        $metodo->setAccessible(true);
        $this->assertFalse($metodo->invoke($controlador, 'ver-el-menu'),
            'devuelve false para que el controlador no siga cargando datos que va a tirar');

        // la cabecera es `Refresh: <retardo>; <url>`; aquí interesa la url
        return substr((string)$respuesta->headers->get('Refresh'), strlen('0; '));
    }

    /** Le pone al controlador una petición y una respuesta propias, que fuera del kernel no tiene. */
    private function inyectar(AdminPermisos $controlador, array $post): Response
    {
        $respuesta = new Response();
        $reflexion = new ReflectionObject($controlador);
        foreach (['request' => new Request(['request' => $post]), 'response' => $respuesta] as $nombre => $valor) {
            $propiedad = $reflexion->getProperty($nombre);
            $propiedad->setAccessible(true);
            $propiedad->setValue($controlador, $valor);
        }

        return $respuesta;
    }

    /** En orden inverso: primero lo que apunta, después lo apuntado. */
    private function limpiar(User $usuario, array $roles): void
    {
        foreach ((new RoleUser())->all([Where::eq('nick', $usuario->nick)], [], 0, 0) as $ru) {
            $ru->delete();
        }
        foreach ($roles as $rol) {
            foreach ((new RoleAccess())->all([Where::eq('codrole', $rol->codrole)], [], 0, 0) as $ra) {
                $ra->delete();
            }
            $rol->delete();
        }
        $usuario->delete();
    }

    /** Una página del menú de prueba; con rol, concedida; sin rol, denegada. */
    private function pagina(string $nombre, string $titulo, ?Role $rol, string $icono = 'fa-solid fa-star'): void
    {
        $pagina = new Page();
        $pagina->name = $nombre;
        $pagina->title = $titulo;
        $pagina->menu = self::MENU;
        $pagina->icon = $icono;
        $pagina->showonmenu = true;
        $this->assertTrue($pagina->save(), 'no se pudo sembrar la página ' . $nombre);

        if (null === $rol) {
            return;
        }
        $acceso = new RoleAccess();
        $acceso->codrole = $rol->codrole;
        $acceso->pagename = $nombre;
        $this->assertTrue($acceso->save(), 'no se pudo dar acceso a ' . $nombre);
    }

    /** @return array{0: User, 1: Role} */
    private function sembrar(string $nick, string $codrole): array
    {
        $usuario = new User();
        $usuario->nick = $nick;
        $usuario->email = $nick . '@okopermisos.test';
        // el `1` no es adorno: `User::setPassword()` exige un dígito y devuelve false sin él, dejando la
        // contraseña nula y el `save()` fallando por la columna NOT NULL — con un mensaje que señala
        // `password` y no el porqué. Se comprueba el retorno para que el fallo diga la causa.
        $this->assertTrue($usuario->setPassword('okopermisos1-' . $nick), 'la contraseña de prueba no vale');
        $usuario->admin = false;
        $this->assertTrue($usuario->save(), 'no se pudo sembrar el usuario ' . $nick);

        $rol = new Role();
        $rol->codrole = $codrole;
        $rol->descripcion = 'rol de prueba de OkoPermisos';
        $this->assertTrue($rol->save(), 'no se pudo sembrar el rol ' . $codrole);

        $ru = new RoleUser();
        $ru->codrole = $rol->codrole;
        $ru->nick = $usuario->nick;
        $this->assertTrue($ru->save(), 'no se pudo asignar el rol');

        return [$usuario, $rol];
    }

    /** Limpia siempre, aunque el test muera a mitad: si no, la corrida siguiente falla por duplicado. */
    protected function tearDown(): void
    {
        $db = new DataBase();
        $db->connect();
        $db->exec('DELETE FROM roles_users WHERE nick LIKE \'okoperm%\';');
        $db->exec('DELETE FROM roles_access WHERE codrole LIKE \'okopermrol%\';');
        $db->exec('DELETE FROM roles WHERE codrole LIKE \'okopermrol%\';');
        $db->exec('DELETE FROM users WHERE nick LIKE \'okoperm%\';');
        // el patrón lleva `OkoPermisosPrueba`, no `Oko`: `ListOko%` casa con las páginas REALES de los
        // demás plugins propios, que están registradas en la base de pruebas
        $db->exec('DELETE FROM pages WHERE name LIKE \'%OkoPermisosPrueba%\';');
        Cache::clear();

        $this->logErrors();
    }
}
