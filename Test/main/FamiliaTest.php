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
use FacturaScripts\Core\Internal\PluginsDeploy;
use FacturaScripts\Core\Lib\MenuManager;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Controller\AdminPermisos;
use FacturaScripts\Dinamic\Model\Page;
use FacturaScripts\Plugins\OkoPermisos\Lib\PermisoEspecial;
use FacturaScripts\Test\Traits\LogErrorsTrait;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Un permiso de prueba con la familia que se le diga. La hoja se pinta a una cadena, como en
 * `PuntosDeExtensionTest`: `view()` hace lo mismo que el de `Template\Controller` salvo enviar la respuesta.
 */
final class PermisoFamiliaOkoPermisosPrueba extends PermisoEspecial
{
    public static string $familia = '';

    public string $html = '';

    protected function familia(): string
    {
        return self::$familia;
    }

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
 * **La familia de un permiso especial va a `pages.submenu`**, y por ella agrupan el índice del panel y la
 * ficha del rol del core.
 *
 * Las familias de prueba son claves del core elegidas para que el orden por clave y el orden por traducción
 * discrepen: por clave `users` va antes que `warehouse`, por traducción «Almacén» antes que «Usuarios».
 */
final class FamiliaTest extends TestCase
{
    use LogErrorsTrait;

    private const PAGINA = 'PermisoFamiliaOkoPermisosPrueba';

    /**
     * @description
     * **Con familia, la página lleva su `submenu`; sin familia, `null` como hasta ahora.** Se da de alta y se
     * vuelve a desplegar por el `loadPage()` real del deploy, así que también se comprueba que un redeploy
     * actualiza la familia de una página que ya existía, en los dos sentidos.
     */
    public function testLaFamiliaVaAlSubmenuYElRedeployLaActualiza(): void
    {
        PermisoFamiliaOkoPermisosPrueba::$familia = '';
        $this->desplegar();
        $this->assertNull($this->submenu(), 'sin familia, null como hasta ahora');

        PermisoFamiliaOkoPermisosPrueba::$familia = 'warehouse';
        $this->desplegar();
        $this->assertSame('warehouse', $this->submenu(), 'el redeploy pone la familia a una página que ya existía');

        PermisoFamiliaOkoPermisosPrueba::$familia = 'users';
        $this->desplegar();
        $this->assertSame('users', $this->submenu(), 'y la cambia');

        PermisoFamiliaOkoPermisosPrueba::$familia = '';
        $this->desplegar();
        $this->assertNull($this->submenu(), 'y la quita');
    }

    /**
     * @description
     * **Una familia de más de 20 caracteres no hace fallar el alta**: la página se da de alta sin familia, y
     * el aviso dice qué clave y de qué permiso. El control va primero: en esta base, un `submenu` de 21
     * caracteres guardado tal cual hace fallar el `save()` (modo estricto), así que sin la guarda el alta
     * fallaría. Con 20 justos, la familia entra.
     */
    public function testUnaFamiliaLargaNoImpideElAltaYAvisa(): void
    {
        $control = new Page();
        $control->name = 'ControlLargoOkoPermisosPrueba';
        $control->title = 'okopermisos-especial-permiso';
        $control->menu = PermisoEspecial::MENU;
        $control->submenu = str_repeat('a', PermisoEspecial::FAMILIA_MAX + 1);
        $this->assertFalse($control->save(), 'control: sin la guarda, 21 caracteres hacen fallar el alta');
        Tools::log()::clear();

        $larga = str_repeat('b', PermisoEspecial::FAMILIA_MAX + 1);
        PermisoFamiliaOkoPermisosPrueba::$familia = $larga;
        $this->desplegar();
        $this->assertTrue((new Page())->load(self::PAGINA), 'la página se dio de alta');
        $this->assertNull($this->submenu(), 'sin familia');

        $avisos = array_column(Tools::log()::read('', ['warning']), 'message');
        $this->assertContains(Tools::trans('okopermisos-especial-familia-larga', [
            '%familia%' => $larga,
            '%pagina%' => self::PAGINA,
            '%maximo%' => PermisoEspecial::FAMILIA_MAX,
        ]), $avisos, 'el aviso nombra la clave y el permiso');
        Tools::log()::clear();

        $justa = str_repeat('c', PermisoEspecial::FAMILIA_MAX);
        PermisoFamiliaOkoPermisosPrueba::$familia = $justa;
        $this->desplegar();
        $this->assertSame($justa, $this->submenu(), 'con 20 justos, entra');

        // se mide lo que se guarda: con el escape de Page::test(), una comilla crece a 5 (&#39;), y 20 se hacen 24
        $conComilla = str_repeat('d', PermisoEspecial::FAMILIA_MAX - 1) . "'";
        PermisoFamiliaOkoPermisosPrueba::$familia = $conComilla;
        $this->desplegar();
        $this->assertTrue((new Page())->load(self::PAGINA), 'con comilla, la página sigue dada de alta');
        $this->assertNull($this->submenu(), '20 con comilla son 24 escapados: sin familia');
        $avisos = array_column(Tools::log()::read('', ['warning']), 'message');
        $this->assertContains(Tools::trans('okopermisos-especial-familia-larga', [
            '%familia%' => Tools::noHtml($conComilla),
            '%pagina%' => self::PAGINA,
            '%maximo%' => PermisoEspecial::FAMILIA_MAX,
        ]), $avisos, 'y se avisa');
        Tools::log()::clear();
    }

    /**
     * @description
     * **El índice agrupa por familia traducida**, con una cabecera por familia y las que no tienen al final.
     * Y **sin ninguna familia no sale ninguna cabecera**: la tabla queda como antes.
     */
    public function testElIndiceAgrupaPorFamiliaTraducida(): void
    {
        $controlador = new AdminPermisos('AdminPermisos');
        $this->assertStringNotContainsString('okopermisos-familia', $this->pintarIndice($controlador),
            'sin familias, ninguna cabecera');

        $this->sembrar('UnoOkoPermisosPrueba', 'users');
        $this->sembrar('DosOkoPermisosPrueba', 'warehouse');
        $this->sembrar('TresOkoPermisosPrueba', 'users');

        $orden = array_column(PermisoEspecial::instalados(), 'pagina');
        $orden = array_values(array_filter($orden, fn(string $p): bool => str_contains($p, 'OkoPermisosPrueba')));
        $this->assertSame('DosOkoPermisosPrueba', $orden[0], 'Almacén va antes que Usuarios, aunque su clave vaya después');
        $this->assertSame(['TresOkoPermisosPrueba', 'UnoOkoPermisosPrueba'], array_slice($orden, 1, 2),
            'las de Usuarios, seguidas y por título');
        $this->assertSame(self::PAGINA, end($orden), 'la que no tiene familia, al final');

        $html = $this->pintarIndice($controlador);
        $almacen = strpos($html, '<th colspan="3">' . Tools::trans('warehouse') . '</th>');
        $usuarios = strpos($html, '<th colspan="3">' . Tools::trans('users') . '</th>');
        $sinFamilia = strpos($html, '<th colspan="3">' . Tools::trans('okopermisos-especial-sin-familia') . '</th>');
        $this->assertIsInt($almacen, 'cabecera de Almacén');
        $this->assertIsInt($usuarios, 'cabecera de Usuarios');
        $this->assertIsInt($sinFamilia, 'cabecera de las que no tienen familia');
        $this->assertSame(1, substr_count($html, '<th colspan="3">' . Tools::trans('users') . '</th>'), 'una cabecera por familia');

        $dos = strpos($html, 'DosOkoPermisosPrueba');
        $uno = strpos($html, 'UnoOkoPermisosPrueba');
        $tres = strpos($html, 'TresOkoPermisosPrueba');
        $propia = strpos($html, '<code>' . self::PAGINA . '</code>');
        $this->assertTrue($almacen < $dos && $dos < $usuarios, 'Dos, bajo Almacén');
        $this->assertTrue($usuarios < $tres && $tres < $uno && $uno < $sinFamilia, 'Tres y Uno, bajo Usuarios');
        $this->assertTrue($sinFamilia < $propia, 'la que no tiene familia, bajo su cabecera');
    }

    /**
     * @description
     * **La hoja enseña la familia si la tiene**, traducida, y no enseña nada si no.
     */
    public function testLaHojaEnsenaLaFamilia(): void
    {
        PermisoFamiliaOkoPermisosPrueba::$familia = 'warehouse';
        $hoja = new PermisoFamiliaOkoPermisosPrueba(self::PAGINA);
        $this->assertSame(Tools::trans('warehouse'), $hoja->familiaTraducida());
        $html = $this->pintarHoja($hoja);
        $this->assertStringContainsString('<dt class="col-sm-3">' . Tools::trans('okopermisos-especial-familia') . '</dt>', $html);
        $this->assertStringContainsString('<dd class="col-sm-9">' . Tools::trans('warehouse') . '</dd>', $html);

        PermisoFamiliaOkoPermisosPrueba::$familia = '';
        $hoja = new PermisoFamiliaOkoPermisosPrueba(self::PAGINA);
        $this->assertSame('', $hoja->familiaTraducida());
        $this->assertStringNotContainsString(Tools::trans('okopermisos-especial-familia') . '</dt>', $this->pintarHoja($hoja));
    }

    /**
     * @description
     * **Cómo se concede nombra la familia si la tiene**, porque en la ficha del rol la fila sale como
     * « » familia » página »; sin familia, el mensaje de siempre.
     */
    public function testComoSeConcedeNombraLaFamilia(): void
    {
        $claves = [
            'okopermisos-especial-como-se-concede',
            'okopermisos-especial-como-se-concede-familia',
            'okopermisos-especial-familia',
            'okopermisos-especial-familia-larga',
            'okopermisos-especial-sin-familia',
            'warehouse',
        ];
        foreach ($claves as $clave) {
            $this->assertNotSame($clave, Tools::trans($clave), 'control positivo: «' . $clave . '» tiene traducción');
        }

        PermisoFamiliaOkoPermisosPrueba::$familia = '';
        $sin = (new PermisoFamiliaOkoPermisosPrueba(self::PAGINA))->comoSeConcede();
        $this->assertStringContainsString(Tools::trans('okopermisos-especial-permiso'), $sin, 'control positivo: nombra la página');
        $this->assertStringNotContainsString(Tools::trans('warehouse'), $sin);

        PermisoFamiliaOkoPermisosPrueba::$familia = 'warehouse';
        $con = (new PermisoFamiliaOkoPermisosPrueba(self::PAGINA))->comoSeConcede();
        $this->assertStringContainsString('«' . Tools::trans('warehouse') . '»', $con, 'nombra la familia traducida: ' . $con);
        $this->assertStringContainsString(Tools::trans('okopermisos-especial-permiso'), $con, 'y la página');
        $this->assertStringNotContainsString('%familia%', $con);
    }

    /** Lo que hace el deploy con la página de este permiso: su `loadPage()`, el real. */
    private function desplegar(): void
    {
        $loadPage = new ReflectionMethod(PluginsDeploy::class, 'loadPage');
        $loadPage->invoke(null, (new PermisoFamiliaOkoPermisosPrueba(self::PAGINA))->getPageData());
    }

    private function pintarHoja(PermisoFamiliaOkoPermisosPrueba $hoja): string
    {
        $hoja->user = new \FacturaScripts\Dinamic\Model\User();
        $hoja->user->nick = 'okofam1';
        $hoja->user->admin = true;
        $view = new ReflectionMethod($hoja, 'view');
        $view->invoke($hoja, 'OkoPermisosHoja.html.twig');
        return $hoja->html;
    }

    private function pintarIndice(AdminPermisos $controlador): string
    {
        return Html::render('OkoPermisosIndice.html.twig', ['fsc' => $controlador]);
    }

    /** Una página de permiso con familia, como la dejaría el deploy. */
    private function sembrar(string $nombre, string $familia): void
    {
        $page = new Page();
        $page->name = $nombre;
        $page->title = $nombre;
        $page->menu = PermisoEspecial::MENU;
        $page->submenu = $familia;
        $page->showonmenu = false;
        $this->assertTrue($page->save(), 'no se pudo sembrar ' . $nombre);
    }

    private function submenu(): ?string
    {
        $page = new Page();
        $this->assertTrue($page->load(self::PAGINA), 'la página no está');
        return $page->submenu;
    }

    protected function setUp(): void
    {
        PermisoFamiliaOkoPermisosPrueba::$familia = '';
        $this->desplegar();
    }

    protected function tearDown(): void
    {
        PermisoFamiliaOkoPermisosPrueba::$familia = '';
        $db = new DataBase();
        $db->connect();
        $db->exec('DELETE FROM pages WHERE name LIKE \'%OkoPermisosPrueba%\';');
        Cache::clear();

        $this->logErrors();
    }
}
