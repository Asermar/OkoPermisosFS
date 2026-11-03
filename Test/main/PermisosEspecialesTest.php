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
use FacturaScripts\Core\KernelException;
use FacturaScripts\Core\Lib\MenuManager;
use FacturaScripts\Core\Session;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\Join\ImpactoDeQuitar;
use FacturaScripts\Dinamic\Model\Join\PermisoEfectivo;
use FacturaScripts\Dinamic\Model\Page;
use FacturaScripts\Dinamic\Model\Role;
use FacturaScripts\Dinamic\Model\RoleAccess;
use FacturaScripts\Dinamic\Model\RoleUser;
use FacturaScripts\Dinamic\Model\User;
use FacturaScripts\Plugins\OkoPermisos\Lib\MenuDelUsuario;
use FacturaScripts\Plugins\OkoPermisos\Lib\PermisoEspecial;
use FacturaScripts\Test\Plugins as Prueba;
use FacturaScripts\Test\Traits\LogErrorsTrait;
use PHPUnit\Framework\TestCase;

/**
 * Un permiso de prueba. El nombre de la clase ES el de la página, y lleva `OkoPermisosPrueba` para que el
 * barrido del `tearDown` no alcance páginas reales.
 */
final class PermisoOkoPermisosPrueba extends PermisoEspecial
{
    /** La plantilla que `run()` habría pintado. */
    public ?string $pintada = null;

    /** La puerta de la hoja, expuesta para el test. */
    public function puerta(): void
    {
        $this->exigirAccesoAlPanel();
    }

    protected function titulo(): string
    {
        return 'okopermisos-especial-permiso';
    }

    /**
     * Anota la plantilla en vez de enviarla: dentro de PHPUnit la salida ya empezó y `Response::send()`
     * no puede poner cabeceras. `run()` es el real; sólo cambia el último paso.
     */
    protected function view(string $view, array $data = []): void
    {
        $this->pintada = $view;
    }
}

/**
 * **Un permiso especial es una página**: tenerlo es tener `update` en ella.
 *
 * La página se siembra en `pages` como la dejaría el deploy —menú `PermisoEspecial::MENU`, fuera del
 * menú— porque el escenario no trae ningún plugin que declare uno. Los casos que dan valor son los
 * NEGATIVOS, y cada uno comprueba además que la negativa **lo dice**.
 */
final class PermisosEspecialesTest extends TestCase
{
    use LogErrorsTrait;

    private const PAGINA = 'PermisoOkoPermisosPrueba';

    /**
     * @description
     * **Con el acceso y `allowupdate` se tiene; con el acceso SIN él, no** —se ve la hoja en lectura—; sin
     * rol, tampoco. El control positivo va primero.
     */
    public function testElPermisoEsTenerUpdateEnSuPagina(): void
    {
        $conUpdate = $this->conPermiso('okoesp1', 'okoesprolA', true);
        $this->assertTrue(PermisoEspecial::puede(PermisoOkoPermisosPrueba::class, $conUpdate), 'control positivo');
        $this->assertTrue(PermisoEspecial::exigir(PermisoOkoPermisosPrueba::class, $conUpdate));
        $this->assertTrue($conUpdate->can(self::PAGINA, 'access'));

        $soloAcceso = $this->conPermiso('okoesp2', 'okoesprolB', false);
        $this->assertTrue($soloAcceso->can(self::PAGINA, 'access'), 'con el acceso ve la hoja');
        $this->assertFalse(PermisoEspecial::puede(PermisoOkoPermisosPrueba::class, $soloAcceso),
            'sin allowupdate no tiene el permiso');

        $sinRol = $this->nuevoUsuario('okoesp3', false);
        $motivo = PermisoEspecial::motivo(PermisoOkoPermisosPrueba::class, $sinRol);
        $this->assertSame(Tools::trans('okopermisos-especial-denegado', [
            '%permiso%' => Tools::trans('okopermisos-especial-permiso'),
            '%pagina%' => self::PAGINA,
        ]), $motivo, 'la negativa nombra el permiso y la página que lo concede');
    }

    /**
     * @description
     * **El índice lista las páginas del menú de permisos, y sólo ésas**: ni el propio índice ni una página
     * cualquiera. Y una negativa de `exigir()` queda en el registro, que es lo que ve el usuario.
     */
    public function testElIndiceListaLosPermisosYExigirAvisa(): void
    {
        $paginas = array_column(PermisoEspecial::instalados(), 'pagina');
        $this->assertContains(self::PAGINA, $paginas, 'control positivo');
        $this->assertNotContains('AdminPermisosEspeciales', $paginas);
        $this->assertNotContains('AdminPermisos', $paginas);

        Tools::log()::clear();
        $usuario = $this->nuevoUsuario('okoesp15', false);
        $this->assertFalse(PermisoEspecial::exigir(PermisoOkoPermisosPrueba::class, $usuario));
        $avisos = array_column(Tools::log()::read('', ['warning']), 'message');
        $this->assertContains(PermisoEspecial::motivo(PermisoOkoPermisosPrueba::class, $usuario), $avisos);
        Tools::log()::clear();
    }

    public function testElAdminLoTieneSinRol(): void
    {
        $this->assertTrue(PermisoEspecial::exigir(PermisoOkoPermisosPrueba::class, $this->nuevoUsuario('okoesp4', true)));
    }

    /**
     * @description
     * **Una página que no está instalada niega incluso al admin**, y lo dice: una errata en la clase no
     * debe dejar pasar a nadie. Una página real que NO es de permiso tampoco vale como permiso.
     */
    public function testLoNoInstaladoNiegaInclusoAlAdmin(): void
    {
        $admin = $this->nuevoUsuario('okoesp5', true);
        $this->assertTrue(PermisoEspecial::puede(PermisoOkoPermisosPrueba::class, $admin), 'control positivo');

        $this->assertFalse(PermisoEspecial::puede('FacturaScripts\\Dinamic\\Controller\\NoExisteOkoPermisosPrueba', $admin));
        $this->assertSame(Tools::trans('okopermisos-especial-no-instalado', ['%pagina%' => 'NoExisteOkoPermisosPrueba']),
            PermisoEspecial::motivo('NoExisteOkoPermisosPrueba', $admin));
        $this->assertFalse(PermisoEspecial::puede('AdminPermisos', $admin), 'una página cualquiera no es un permiso');
    }

    /**
     * @description
     * **`existe()` da `true` con un permiso instalado, se nombre como se nombre**: la clase con `::class`,
     * la de `Dinamic` o el nombre corto preguntan por la misma página.
     */
    public function testExisteUnPermisoInstaladoSeNombreComoSeNombre(): void
    {
        $this->assertTrue(PermisoEspecial::existe(PermisoOkoPermisosPrueba::class));
        $this->assertTrue(PermisoEspecial::existe('FacturaScripts\\Dinamic\\Controller\\' . self::PAGINA));
        $this->assertTrue(PermisoEspecial::existe(self::PAGINA));
    }

    /** Un permiso que no está instalado no existe. */
    public function testNoExisteUnPermisoSinInstalar(): void
    {
        $this->assertFalse(PermisoEspecial::existe('FacturaScripts\\Dinamic\\Controller\\NoExisteOkoPermisosPrueba'));
    }

    /** Una página real que no va bajo el menú de los permisos no es un permiso, aunque exista. */
    public function testUnaPaginaCualquieraNoExisteComoPermiso(): void
    {
        $this->assertTrue((new Page())->load('ListCliente'), 'control positivo: la página existe en pages');
        $this->assertFalse(PermisoEspecial::existe('ListCliente'));
    }

    /** Con varios, `true` sólo si están TODOS: basta uno que falte, vaya primero o el último. */
    public function testExisteExigeQueEstenTodos(): void
    {
        $segunda = new Page();
        $segunda->name = 'OtroPermisoOkoPermisosPrueba';
        $segunda->title = 'okopermisos-especial-permiso';
        $segunda->menu = PermisoEspecial::MENU;
        $segunda->showonmenu = false;
        $this->assertTrue($segunda->save(), 'no se pudo sembrar la segunda página');

        $this->assertTrue(PermisoEspecial::existe(PermisoOkoPermisosPrueba::class, 'OtroPermisoOkoPermisosPrueba'),
            'control positivo');
        $this->assertFalse(PermisoEspecial::existe(PermisoOkoPermisosPrueba::class, 'NoExisteOkoPermisosPrueba'));
        $this->assertFalse(PermisoEspecial::existe('NoExisteOkoPermisosPrueba', PermisoOkoPermisosPrueba::class));
    }

    /** Sin argumentos no falta ninguno: `true`. */
    public function testExisteSinArgumentosEsQueNoFaltaNinguno(): void
    {
        $this->assertTrue(PermisoEspecial::existe());
    }

    /**
     * @description
     * **Valen también desde el `Init` de otro plugin en una instalación sin desplegar**, donde las clases de
     * `Dinamic` aún no existen: el core ejecuta los `Init` antes que el controlador. Se prueba en un proceso
     * aparte con el autoload de `Dinamic` cortado, y con el mismo proceso sin cortar como control.
     */
    public function testFuncionanSinDinamic(): void
    {
        $conDinamic = $this->sinDinamic(false);
        $this->assertTrue($conDinamic['dinamic'], 'control: sin cortar, la clase de Dinamic se carga');

        $sinDinamic = $this->sinDinamic(true);
        $this->assertFalse($sinDinamic['dinamic'], 'el corte de Dinamic no surtió efecto');
        $this->assertSame('', $sinDinamic['error']);
        $this->assertTrue($sinDinamic['existe']);
        $this->assertFalse($sinDinamic['otra'], 'una página que no es permiso');
        $this->assertFalse($sinDinamic['puede'], 'sin sesión se niega');
        $this->assertFalse($sinDinamic['exigir']);
        $this->assertNotSame('', $sinDinamic['motivo']);
    }

    /**
     * @description
     * **Las cuatro formas de nombrar un permiso preguntan por la misma página** —las del README—: `use` de la
     * clase, `use` del namespace con alias, nombre completo y nombre corto. Y **`::class` no carga la clase**:
     * sólo compone el nombre, así que nombrar un permiso no crea dependencia al ejecutar.
     */
    public function testLasCuatroFormasDeNombrarUnPermiso(): void
    {
        $admin = $this->nuevoUsuario('okoesp12', true);
        $nombres = [
            'use de la clase' => PermisoOkoPermisosPrueba::class,
            'use del namespace con alias' => Prueba\PermisoOkoPermisosPrueba::class,
            'nombre completo' => \FacturaScripts\Test\Plugins\PermisoOkoPermisosPrueba::class,
            'nombre corto' => 'PermisoOkoPermisosPrueba',
        ];
        foreach ($nombres as $forma => $nombre) {
            $this->assertSame(self::PAGINA, PermisoEspecial::pagina($nombre), $forma);
            $this->assertTrue(PermisoEspecial::existe($nombre), $forma);
            $this->assertTrue(PermisoEspecial::puede($nombre, $admin), $forma);
        }

        $nombre = Prueba\SinCargarOkoPermisosPrueba::class;
        $this->assertSame('FacturaScripts\\Test\\Plugins\\SinCargarOkoPermisosPrueba', $nombre);
        $this->assertFalse(class_exists($nombre, false), '::class no carga la clase');
        $this->assertFalse(PermisoEspecial::existe($nombre), 'y la errata se niega al ejecutar');
    }

    /** Sin `$user`, se pregunta por el de la sesión. */
    public function testSinUserPreguntaPorElDeLaSesion(): void
    {
        Session::set('user', $this->nuevoUsuario('okoesp13', true));
        $this->assertTrue(PermisoEspecial::puede(PermisoOkoPermisosPrueba::class), 'el admin en sesión');

        Session::set('user', $this->nuevoUsuario('okoesp14', false));
        $this->assertFalse(PermisoEspecial::puede(PermisoOkoPermisosPrueba::class), 'un usuario sin rol en sesión');
        Session::set('user', new User());
    }

    /** Sin usuario en sesión (cron, worker, API) `Session::user()` da un User vacío: se niega y lo dice. */
    public function testSinUsuarioEnSesionNiegaYLoDice(): void
    {
        $this->assertSame(Tools::trans('okopermisos-especial-sin-sesion',
            ['%permiso%' => Tools::trans('okopermisos-especial-permiso')]),
            PermisoEspecial::motivo(PermisoOkoPermisosPrueba::class, new User()));
    }

    /**
     * @description
     * **El permiso no sale en el menú de nadie, y el índice ya no es una página**: es una pestaña de
     * `AdminPermisos`. Se contrasta contra `MenuManager`, que es lo que pinta la barra.
     */
    public function testLosPermisosNoSalenEnNingunMenu(): void
    {
        $this->assertFalse((new Page())->loadFromCode('AdminPermisosEspeciales'), 'la página suelta del índice ya no existe');

        $admin = $this->paginasDelMenu($this->nuevoUsuario('okoesp6', true));
        $this->assertContains('AdminPermisos', $admin, 'control positivo: el menú del admin trae las páginas del plugin');
        $this->assertNotContains(self::PAGINA, $admin, 'el permiso no sale en el menú, ni del admin');

        foreach ([true, false] as $update) {
            $titular = $this->paginasDelMenu($this->conPermiso('okoesp7' . (int)$update, 'okoesprolM' . (int)$update, $update));
            $this->assertNotContains(self::PAGINA, $titular);
        }
    }

    /**
     * @description
     * **La hoja se abre con acceso a `AdminPermisos`, no con el permiso.** Un titular sin más accesos se
     * queda fuera, como en cualquier página no concedida; con acceso al panel entra y ve la lista entera.
     */
    public function testLaHojaExigeAccesoAlPanel(): void
    {
        $titular = $this->conPermiso('okoesp8', 'okoesprolT', true);
        $this->conPermiso('okoesp9', 'okoesprolL', false);

        $hoja = new PermisoOkoPermisosPrueba(self::PAGINA);
        $hoja->user = $titular;
        try {
            $hoja->puerta();
            $this->fail('un titular sin acceso a AdminPermisos abrió la hoja');
        } catch (KernelException $e) {
            $this->assertSame('access-denied', $e->getMessage());
        }

        $panel = new RoleAccess();
        $panel->codrole = 'okoesprolT';
        $panel->pagename = 'AdminPermisos';
        $this->assertTrue($panel->save());
        $hoja->puerta();

        $nicks = array_column($hoja->titulares(), 'roles', 'nick');
        $this->assertSame('okoesprolT', $nicks['okoesp8'] ?? null, 'quien llega ve la lista: el titular con su rol');
        $this->assertArrayNotHasKey('okoesp9', $nicks, 'con acceso sin modificar no es titular');

        $hoja->user = $this->nuevoUsuario('okoesp10', true);
        $hoja->puerta();
        $this->assertArrayHasKey('okoesp10', array_column($hoja->titulares(), 'admin', 'nick'), 'el admin pasa y sale por serlo');
        $this->assertSame('OkoPermisos', $hoja->plugin());
    }

    /**
     * @description
     * **La puerta está en el camino real: `run()`.** Se entra como lo haría un navegador —la cookie de
     * sesión que comprueba `Template\Controller::auth()`— y no llamando a la puerta a mano: si alguien la
     * quitara de `run()`, un test que la invoca directamente seguiría en verde. Un titular sin acceso a
     * `AdminPermisos` se queda en `AccessDenied` antes de pintar nada; con acceso, la hoja se pinta.
     */
    public function testRunDeniegaLaHojaSinAccesoAlPanel(): void
    {
        $titular = $this->conPermiso('okoesp16', 'okoesprolR', true);
        try {
            $this->correr($titular);
            $this->fail('run() pintó la hoja a un titular sin acceso a AdminPermisos');
        } catch (KernelException $e) {
            $this->assertSame('access-denied', $e->getMessage());
        }

        // control positivo: con acceso al panel, el mismo run() pinta la hoja
        $panel = new RoleAccess();
        $panel->codrole = 'okoesprolR';
        $panel->pagename = 'AdminPermisos';
        $this->assertTrue($panel->save());
        $this->assertSame('OkoPermisosHoja.html.twig', $this->correr($titular)->pintada, 'la hoja se pinta');
    }

    /**
     * @description
     * **El mensaje de cómo se concede usa los textos de la pantalla del grupo**, sacados de sus claves:
     * si el core cambia una traducción, el mensaje la sigue.
     */
    public function testElMensajeUsaLosTextosDeLaPantallaDelGrupo(): void
    {
        $mensaje = (new PermisoOkoPermisosPrueba(self::PAGINA))->comoSeConcede();
        foreach (['roles', 'rules', 'show', 'allow-update', PermisoEspecial::MENU, 'okopermisos-especial-permiso'] as $clave) {
            $this->assertNotSame($clave, Tools::trans($clave), 'control positivo: «' . $clave . '» tiene traducción');
            $this->assertStringContainsString(Tools::trans($clave), $mensaje, $clave);
        }
    }

    /**
     * @description
     * **Efectivos e impacto lo ven sin código especial**: es una página más. Y el filtro de menú lo trae
     * con su nombre traducido.
     */
    public function testEfectivosEImpactoLoVenComoUnaPaginaMas(): void
    {
        $this->conPermiso('okoesp11', 'okoesprolE', true);

        $filas = (new PermisoEfectivo())->all([Where::eq('e.nick', 'okoesp11'), Where::eq('p.menu', PermisoEspecial::MENU)], [], 0, 0);
        $this->assertCount(1, $filas);
        $this->assertSame(self::PAGINA, $filas[0]->pagina);
        $this->assertSame(1, (int)$filas[0]->puede_escribir);

        $impacto = (new ImpactoDeQuitar())->all([Where::eq('ra.codrole', 'okoesprolE')], [], 0, 0);
        $this->assertCount(1, $impacto);
        $this->assertSame(1, (int)$impacto[0]->lo_perderian, 'quitárselo al rol se lo quita al titular');

        $this->assertNotSame(PermisoEspecial::MENU, Tools::trans(PermisoEspecial::MENU), 'el código del menú tiene traducción');
    }

    /**
     * @description
     * **La Vista del menú arma el nodo aparte**, evaluado por roles —un admin sin el flag no lo tendría—,
     * y distingue a quien sólo ve la hoja.
     */
    public function testLaVistaDelMenuLoPintaAparte(): void
    {
        $this->conPermiso('okoesp12', 'okoesprolV', true);
        $this->conPermiso('okoesp13', 'okoesprolH', false);

        $tiene = $this->especialDe('okoesp12');
        $this->assertTrue($tiene['tiene']);
        $this->assertSame('okoesprolV', $tiene['roles']);

        $hoja = $this->especialDe('okoesp13');
        $this->assertFalse($hoja['tiene']);
        $this->assertTrue($hoja['solo_hoja']);

        $nada = $this->especialDe($this->nuevoUsuario('okoesp14', true)->nick);
        $this->assertFalse($nada['tiene'], 'un admin se evalúa sin el flag');
        $this->assertFalse($nada['solo_hoja']);
    }

    /** Un usuario con un rol que da acceso a la página del permiso, con o sin `update`. */
    private function conPermiso(string $nick, string $codrole, bool $update): User
    {
        $usuario = $this->nuevoUsuario($nick, false);
        $rol = new Role();
        $rol->codrole = $codrole;
        $rol->descripcion = 'Rol de prueba ' . $codrole;
        $this->assertTrue($rol->save(), 'no se pudo sembrar el rol');

        $acceso = new RoleAccess();
        $acceso->codrole = $codrole;
        $acceso->pagename = self::PAGINA;
        $acceso->allowupdate = $update;
        $acceso->allowdelete = false;
        $acceso->allowexport = false;
        $acceso->allowimport = false;
        $this->assertTrue($acceso->save(), 'no se pudo dar el acceso');

        $pertenencia = new RoleUser();
        $pertenencia->nick = $nick;
        $pertenencia->codrole = $codrole;
        $this->assertTrue($pertenencia->save(), 'no se pudo asignar el rol');
        return $usuario;
    }

    /** Ejecuta la hoja por `run()` con la cookie de sesión del usuario, como un navegador. */
    private function correr(User $usuario): PermisoOkoPermisosPrueba
    {
        $clave = $usuario->newLogkey('127.0.0.1');
        $this->assertTrue($usuario->save(), 'no se pudo guardar la clave de sesión');

        $antes = $_COOKIE;
        $_COOKIE = ['fsNick' => $usuario->nick, 'fsLogkey' => $clave];
        $hoja = new PermisoOkoPermisosPrueba(self::PAGINA);
        try {
            $hoja->run();
        } finally {
            $_COOKIE = $antes;
        }

        return $hoja;
    }

    private function especialDe(string $nick): array
    {
        foreach (MenuDelUsuario::especiales($nick) as $esp) {
            if (self::PAGINA === $esp['pagina']) {
                return $esp;
            }
        }

        $this->fail('el permiso no salió en el nodo de la Vista del menú');
    }

    private function nuevoUsuario(string $nick, bool $admin): User
    {
        $usuario = new User();
        $usuario->nick = $nick;
        $usuario->email = $nick . '@okopermisos.test';
        $this->assertTrue($usuario->setPassword('okopermisos1-' . $nick));
        $usuario->admin = $admin;
        $this->assertTrue($usuario->save(), 'no se pudo sembrar el usuario');
        return $usuario;
    }

    /** @return string[] los nombres de página del menú que `MenuManager` pinta para ese usuario */
    private function paginasDelMenu(User $usuario): array
    {
        Session::set('user', $usuario);
        Cache::clear();

        $nombres = [];
        $recorrer = function (array $items) use (&$recorrer, &$nombres): void {
            foreach ($items as $item) {
                if (false === empty($item->menu)) {
                    $recorrer($item->menu);
                    continue;
                }
                $nombres[] = $item->name;
            }
        };
        $recorrer(MenuManager::init()->getMenu());
        return $nombres;
    }

    /**
     * Arranca el kernel en otro proceso —`Kernel::init()`, sin `Plugins::init()`: es el estado en el que corren
     * los `Init`, sin cargar los de todos los plugins del entorno— y pregunta por el permiso. Con `$cortar`, el
     * autoload no resuelve nada de `FacturaScripts\Dinamic`, que es lo que pasa antes del primer despliegue.
     *
     * @return array{dinamic: bool, error: string, existe: bool, otra: bool, puede: bool, exigir: bool, motivo: string}
     */
    private function sinDinamic(bool $cortar): array
    {
        $codigo = <<<'PHP'
<?php
define('FS_FOLDER', $argv[1]);
chdir(FS_FOLDER);
$loader = require FS_FOLDER . '/vendor/autoload.php';
if ('1' === $argv[2]) {
    $loader->unregister();
    spl_autoload_register(static function (string $clase) use ($loader): void {
        if (false === str_starts_with($clase, 'FacturaScripts\\Dinamic\\')) {
            $loader->loadClass($clase);
        }
    });
}
require FS_FOLDER . '/config.php';
\FacturaScripts\Core\Kernel::init();
$r = ['dinamic' => class_exists('FacturaScripts\\Dinamic\\Model\\Page'), 'error' => ''];
try {
    $permiso = \FacturaScripts\Plugins\OkoPermisos\Lib\PermisoEspecial::class;
    $r['existe'] = $permiso::existe($argv[3]);
    $r['otra'] = $permiso::existe('ListCliente');
    $r['puede'] = $permiso::puede($argv[3]);
    $r['exigir'] = $permiso::exigir($argv[3]);
    $r['motivo'] = $permiso::motivo($argv[3]);
} catch (\Throwable $e) {
    $r['error'] = get_class($e) . ': ' . $e->getMessage();
}
echo json_encode($r);
PHP;
        $base = tempnam(sys_get_temp_dir(), 'okopermisos');
        $script = $base . '.php';
        file_put_contents($script, $codigo);
        $orden = escapeshellarg(PHP_BINARY ?: 'php') . ' ' . escapeshellarg($script) . ' ' . escapeshellarg(FS_FOLDER)
            . ' ' . ($cortar ? '1' : '0') . ' ' . escapeshellarg(self::PAGINA) . ' 2>/dev/null';
        $salida = (string)shell_exec($orden);
        unlink($script);
        unlink($base);

        $json = substr($salida, (int)strrpos($salida, '{"dinamic"'));
        $r = json_decode($json, true);
        $this->assertIsArray($r, 'el proceso no devolvió el resultado: ' . $salida);
        return $r + ['existe' => null, 'otra' => null, 'puede' => null, 'exigir' => null, 'motivo' => ''];
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

    protected function tearDown(): void
    {
        $db = new DataBase();
        $db->connect();
        $db->exec('DELETE FROM roles_users WHERE nick LIKE \'okoesp%\';');
        $db->exec('DELETE FROM roles_access WHERE codrole LIKE \'okoesprol%\';');
        $db->exec('DELETE FROM roles WHERE codrole LIKE \'okoesprol%\';');
        $db->exec('DELETE FROM users WHERE nick LIKE \'okoesp%\';');
        $db->exec('DELETE FROM pages WHERE name LIKE \'%OkoPermisosPrueba%\';');
        Cache::clear();

        $this->logErrors();
    }
}
