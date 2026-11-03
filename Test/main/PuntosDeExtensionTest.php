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
use FacturaScripts\Core\Html;
use FacturaScripts\Core\Internal\Plugin;
use FacturaScripts\Core\Lib\MenuManager;
use FacturaScripts\Core\Plugins;
use FacturaScripts\Core\Request;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Controller\AdminPermisos;
use FacturaScripts\Dinamic\Model\Page;
use FacturaScripts\Dinamic\Model\Role;
use FacturaScripts\Dinamic\Model\RoleAccess;
use FacturaScripts\Dinamic\Model\RoleUser;
use FacturaScripts\Dinamic\Model\User;
use FacturaScripts\Plugins\OkoPermisos\Lib\PermisoEspecial;
use FacturaScripts\Test\Traits\LogErrorsTrait;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionObject;
use ReflectionProperty;

/**
 * Un permiso de prueba cuya hoja se pinta de verdad, a una cadena. `view()` hace lo mismo que el de
 * `Template\Controller` —los mismos datos— salvo enviar la respuesta, que dentro de PHPUnit no se puede.
 */
final class PermisoHuecosOkoPermisosPrueba extends PermisoEspecial
{
    public string $html = '';

    protected function titulo(): string
    {
        return 'okopermisos-especial-permiso';
    }

    protected function view(string $view, array $data = []): void
    {
        $data['controllerName'] = $this->getClassName();
        $data['fsc'] = $this;
        $data['menuManager'] = MenuManager::init()->selectPage($this->getPageData());
        $this->html = Html::render($view, $data);
    }
}

/**
 * **Los puntos de extensión salen una vez por permiso y con el contrato `hueco`.**
 *
 * Un hueco sólo se ve si el core encuentra un fichero en el `Extension/View` de un plugin activo: Twig no
 * deja redefinir `getIncludeViews`, así que no hay atajo. Se monta un **plugin sonda** que existe sólo
 * mientras dura el test, en la carpeta `Plugins` de la instalación de pruebas —una carpeta real, no el
 * enlace al repo de ningún plugin, así que no puede quedar nada en un árbol versionado ni en un zip—, y se
 * da por activo añadiéndolo por reflexión a `Plugins::$plugins`. Cada fichero de la sonda pinta un
 * marcador con las tres variables del contrato, y se cuentan.
 *
 * La otra mitad es que **sin la sonda no salga nada**: ni marcador ni error.
 */
final class PuntosDeExtensionTest extends TestCase
{
    use LogErrorsTrait;

    private const SONDA = 'OkoPermisosSonda';

    private const PAGINA = 'PermisoHuecosOkoPermisosPrueba';

    private const HUECOS = [
        'OkoPermisosVistaDelMenu_pagina',
        'OkoPermisosVistaDelMenu_especial',
        'OkoPermisosIndice_permiso',
        'OkoPermisosHoja_titular',
        'OkoPermisosHoja_permiso',
    ];

    /** Lo que dejó una corrida que murió a medias: la carpeta de la sonda no es de nadie. */
    public static function setUpBeforeClass(): void
    {
        Tools::folderDelete(self::carpetaSonda());
    }

    /**
     * @description
     * **Vista del menú: un marcador por página del árbol, concedida o no, y uno por permiso especial**,
     * con el nick elegido y `tiene` según el acceso. Se cuenta contra el árbol que arma el controlador.
     */
    public function testLaVistaDelMenuPintaUnHuecoPorPaginaYPorEspecial(): void
    {
        [$usuario, $rol] = $this->sembrarUsuario('okopermhueco1', 'okopermrolhueco1');
        $this->conceder($rol, 'AdminPermisos', false);
        $this->conceder($rol, self::PAGINA, true);

        $controlador = $this->vistaDelMenu($usuario->nick);
        $html = $this->conSonda(fn() => $this->pintarVistaDelMenu($controlador));

        $paginas = [];
        foreach ($controlador->arbol as $menu) {
            foreach ($menu['hijos'] as $hijo) {
                foreach ('submenu' === $hijo['tipo'] ? $hijo['paginas'] : [$hijo['pagina']] as $pagina) {
                    $paginas[] = $pagina;
                }
            }
        }
        $this->assertNotEmpty($paginas, 'control positivo: el árbol tiene páginas');
        $this->assertSame(count($paginas), $this->cuantos($html, 'OkoPermisosVistaDelMenu_pagina'), 'una por página');
        foreach ($paginas as $pagina) {
            $this->assertMarcador($html, 'OkoPermisosVistaDelMenu_pagina', $pagina['nombre'], $usuario->nick, $pagina['tiene']);
        }
        $this->assertMarcador($html, 'OkoPermisosVistaDelMenu_pagina', 'AdminPermisos', $usuario->nick, true);
        $this->assertMarcador($html, 'OkoPermisosVistaDelMenu_pagina', 'ListCliente', $usuario->nick, false);

        $this->assertSame(count($controlador->especiales), $this->cuantos($html, 'OkoPermisosVistaDelMenu_especial'), 'una por especial');
        $this->assertMarcador($html, 'OkoPermisosVistaDelMenu_especial', self::PAGINA, $usuario->nick, true);
    }

    /**
     * @description
     * **Un nick que no existe no llega a ningún hueco.** El `nick` de la vista del menú viene de la URL, y
     * el contrato promete «el usuario elegido»: con uno inventado no hay usuario, así que no se arma el
     * árbol —sería todo denegado— y la vista pide elegir uno, como sin nick.
     */
    public function testUnNickQueNoExisteNoLlegaANingunHueco(): void
    {
        $controlador = $this->vistaDelMenu('okopermnoexiste9');
        $html = $this->conSonda(fn() => $this->pintarVistaDelMenu($controlador));

        $this->assertStringContainsString('VistaDelMenu', $html, 'control positivo: la vista se pinta');
        $this->assertSame(0, $this->cuantos($html, 'OkoPermisosVistaDelMenu_pagina'), 'ningún hueco de página');
        $this->assertSame(0, $this->cuantos($html, 'OkoPermisosVistaDelMenu_especial'), 'ningún hueco de especial');
        $this->assertStringNotContainsString('okopermnoexiste9', $html, 'el nick inventado no se pinta en ningún sitio');
        $this->assertSame('', $controlador->nickElegido, 'un usuario que no existe no queda elegido');
    }

    /**
     * @description
     * **Índice: un marcador por fila**, sin usuario y sin `tiene`, que ahí no significan nada.
     */
    public function testElIndicePintaUnHuecoPorPermiso(): void
    {
        $controlador = new AdminPermisos('AdminPermisos');
        $html = $this->conSonda(fn() => Html::render('OkoPermisosIndice.html.twig', ['fsc' => $controlador]));

        $permisos = $controlador->permisosEspeciales();
        $this->assertContains(self::PAGINA, array_column($permisos, 'pagina'), 'control positivo: el permiso sembrado sale');
        $this->assertSame(count($permisos), $this->cuantos($html, 'OkoPermisosIndice_permiso'), 'una por fila');
        $this->assertMarcador($html, 'OkoPermisosIndice_permiso', self::PAGINA, null, null);
    }

    /**
     * @description
     * **Hoja: un marcador por titular y uno por hoja.** Se pinta por `run()`, la puerta incluida, y con la
     * maestra entera: es la plantilla que extiende `Master/MenuTemplate`.
     */
    public function testLaHojaPintaUnHuecoPorTitularYUnoGeneral(): void
    {
        [$usuario, $rol] = $this->sembrarUsuario('okopermhueco2', 'okopermrolhueco2');
        $this->conceder($rol, 'AdminPermisos', false);
        $this->conceder($rol, self::PAGINA, true);

        $hoja = $this->conSonda(fn() => $this->correrHoja($usuario));
        $html = $hoja->html;

        $titulares = $hoja->titulares();
        $this->assertContains($usuario->nick, array_column($titulares, 'nick'), 'control positivo: el titular sembrado sale');
        $this->assertSame(count($titulares), $this->cuantos($html, 'OkoPermisosHoja_titular'), 'uno por titular');
        foreach ($titulares as $titular) {
            $this->assertMarcador($html, 'OkoPermisosHoja_titular', self::PAGINA, $titular['nick'], true);
        }

        $this->assertSame(1, $this->cuantos($html, 'OkoPermisosHoja_permiso'), 'uno por hoja');
        $this->assertMarcador($html, 'OkoPermisosHoja_permiso', self::PAGINA, null, null);
    }

    /**
     * @description
     * **Sin la sonda no sale nada**: las tres vistas se pintan con su contenido y sin un solo marcador. Es
     * el control de los anteriores, y lo que garantiza que una instalación sin extensiones no cambia.
     */
    public function testSinExtensionesNoSaleNingunHueco(): void
    {
        [$usuario, $rol] = $this->sembrarUsuario('okopermhueco3', 'okopermrolhueco3');
        $this->conceder($rol, 'AdminPermisos', false);
        $this->conceder($rol, self::PAGINA, true);
        Tools::folderDelete(Tools::folder('MyFiles', 'Cache', 'Twig'));

        $vistas = [
            'menú' => $this->pintarVistaDelMenu($this->vistaDelMenu($usuario->nick)),
            'índice' => Html::render('OkoPermisosIndice.html.twig', ['fsc' => new AdminPermisos('AdminPermisos')]),
            'hoja' => $this->correrHoja($usuario)->html,
        ];
        foreach ($vistas as $nombre => $html) {
            $this->assertStringContainsString(self::PAGINA, $html, 'control positivo: la vista ' . $nombre . ' pinta el permiso');
            $this->assertStringNotContainsString('[[SONDA:', $html, 'la vista ' . $nombre . ' no pinta huecos sin extensiones');
        }
    }

    private function assertMarcador(string $html, string $hueco, string $pagina, ?string $nick, ?bool $tiene): void
    {
        $tieneTexto = null === $tiene ? 'null' : ($tiene ? 'si' : 'no');
        $marcador = '[[SONDA:' . $hueco . '|' . $pagina . '|' . ($nick ?? 'null') . '|' . $tieneTexto . ']]';
        $this->assertSame(1, substr_count($html, $marcador), 'falta, o se repite, ' . $marcador);
    }

    private static function carpetaSonda(): string
    {
        return FS_FOLDER . '/Plugins/' . self::SONDA;
    }

    private function conceder(Role $rol, string $pagina, bool $update): void
    {
        $acceso = new RoleAccess();
        $acceso->codrole = $rol->codrole;
        $acceso->pagename = $pagina;
        $acceso->allowupdate = $update;
        $this->assertTrue($acceso->save(), 'no se pudo dar acceso a ' . $pagina);
    }

    /**
     * Ejecuta `$pintar` con la sonda instalada y activa, y la retira pase lo que pase. La caché compilada
     * de Twig se vacía antes y después: una plantilla ya compilada sin huecos —o con ellos— taparía el
     * resultado.
     */
    private function conSonda(callable $pintar)
    {
        $vistas = self::carpetaSonda() . '/Extension/View';
        $this->assertTrue(Tools::folderCheckOrCreate($vistas), 'no se pudo crear la sonda');
        file_put_contents(self::carpetaSonda() . '/facturascripts.ini', "name = '" . self::SONDA . "'\nversion = 1\nmin_version = 2025\n");
        foreach (self::HUECOS as $hueco) {
            file_put_contents($vistas . '/' . $hueco . '.html.twig', '[[SONDA:' . $hueco
                . "|{{ hueco.pagina }}|{{ hueco.nick ?? 'null' }}|{{ hueco.tiene is null ? 'null' : (hueco.tiene ? 'si' : 'no') }}]]");
        }

        $plugins = new ReflectionProperty(Plugins::class, 'plugins');
        $plugins->setAccessible(true);
        Plugins::load();
        $antes = $plugins->getValue();
        try {
            $plugins->setValue(null, array_merge($antes, [new Plugin(['name' => self::SONDA, 'enabled' => true, 'order' => 99999])]));
            $this->assertContains(self::SONDA, Plugins::enabled(), 'control positivo: la sonda cuenta como activa');
            Tools::folderDelete(Tools::folder('MyFiles', 'Cache', 'Twig'));
            return $pintar();
        } finally {
            $plugins->setValue(null, $antes);
            Tools::folderDelete(self::carpetaSonda());
            Tools::folderDelete(Tools::folder('MyFiles', 'Cache', 'Twig'));
        }
    }

    /** Ejecuta la hoja por `run()` con la cookie de sesión del usuario, como un navegador. */
    private function correrHoja(User $usuario): PermisoHuecosOkoPermisosPrueba
    {
        $clave = $usuario->newLogkey('127.0.0.1');
        $this->assertTrue($usuario->save(), 'no se pudo guardar la clave de sesión');

        $antes = $_COOKIE;
        $_COOKIE = ['fsNick' => $usuario->nick, 'fsLogkey' => $clave];
        $hoja = new PermisoHuecosOkoPermisosPrueba(self::PAGINA);
        try {
            $hoja->run();
        } finally {
            $_COOKIE = $antes;
        }

        return $hoja;
    }

    private function cuantos(string $html, string $hueco): int
    {
        return substr_count($html, '[[SONDA:' . $hueco . '|');
    }

    private function pintarVistaDelMenu(AdminPermisos $controlador): string
    {
        return Html::render('OkoPermisosVistaDelMenu.html.twig', ['fsc' => $controlador]);
    }

    /** @return array{0: User, 1: Role} */
    private function sembrarUsuario(string $nick, string $codrole): array
    {
        $usuario = new User();
        $usuario->nick = $nick;
        $usuario->email = $nick . '@okopermisos.test';
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

    /** El controlador con la vista del menú cargada para `$nick`, como la deja `loadData()`. */
    private function vistaDelMenu(string $nick): AdminPermisos
    {
        $controlador = new AdminPermisos('AdminPermisos');
        $propiedad = (new ReflectionObject($controlador))->getProperty('request');
        $propiedad->setAccessible(true);
        $propiedad->setValue($controlador, new Request(['query' => ['nick' => $nick]]));

        $cargar = new ReflectionMethod($controlador, 'cargarVistaDelMenu');
        $cargar->setAccessible(true);
        $cargar->invoke($controlador);
        return $controlador;
    }

    protected function setUp(): void
    {
        $pagina = new Page();
        $pagina->name = self::PAGINA;
        $pagina->title = 'okopermisos-especial-permiso';
        $pagina->menu = PermisoEspecial::MENU;
        $pagina->showonmenu = false;
        $pagina->ordernum = 100;
        $this->assertTrue($pagina->save(), 'no se pudo sembrar la página del permiso');
    }

    /** Limpia siempre, aunque el test muera a mitad: si no, la corrida siguiente falla por duplicado. */
    protected function tearDown(): void
    {
        Tools::folderDelete(self::carpetaSonda());
        $db = new DataBase();
        $db->connect();
        $db->exec('DELETE FROM roles_users WHERE nick LIKE \'okopermhueco%\';');
        $db->exec('DELETE FROM roles_access WHERE codrole LIKE \'okopermrolhueco%\';');
        $db->exec('DELETE FROM roles WHERE codrole LIKE \'okopermrolhueco%\';');
        $db->exec('DELETE FROM users WHERE nick LIKE \'okopermhueco%\';');
        $db->exec('DELETE FROM pages WHERE name LIKE \'%OkoPermisosPrueba%\';');
        Cache::clear();

        $this->logErrors();
    }
}
