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

use FacturaScripts\Core\Base\ControllerPermissions;
use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Base\MiniLog;
use FacturaScripts\Core\Lib\ExtendedController\HtmlView;
use FacturaScripts\Core\Lib\ExtendedController\ListView;
use FacturaScripts\Core\Lib\MultiRequestProtection;
use FacturaScripts\Core\Response;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Controller\AdminPermisos;
use FacturaScripts\Dinamic\Model\Page;
use FacturaScripts\Dinamic\Model\Role;
use FacturaScripts\Dinamic\Model\RoleAccess;
use FacturaScripts\Dinamic\Model\RoleUser;
use FacturaScripts\Dinamic\Model\User;
use FacturaScripts\Test\Traits\LogErrorsTrait;
use PHPUnit\Framework\TestCase;

/**
 * **El panel único**: siete entradas laterales, cada una un listado o una pantalla.
 *
 * Todo se prueba por el camino real —`privateCore` con una petición de verdad— y no llamando a
 * `createViews` a mano: lo que se quiere cazar es justo lo que hace el panel por encima de las vistas
 * —desactivar las que no son la primera, procesar la búsqueda de la pestaña activa, ejecutar la acción
 * antes de cargar—, y eso sólo pasa dentro de `privateCore`.
 */
final class AdminPermisosTest extends TestCase
{
    use LogErrorsTrait;

    private const ENTRADAS = [
        'ListPermisoEfectivo' => ListView::class,
        'ListImpactoDeQuitar' => ListView::class,
        'ListUser-admins' => ListView::class,
        'PermisosEspeciales' => HtmlView::class,
        'VistaDelMenu' => HtmlView::class,
        'ListUser' => ListView::class,
        'ListRole' => ListView::class,
    ];

    /**
     * @description
     * **Una fila de usuario lleva a `EditUser` y una de rol a `EditRole`**: la edición se queda en el
     * core. Se comprueba sobre los modelos que carga la vista, que son los que pintan el enlace de la fila.
     */
    public function testLasFilasLlevanALaEdicionDelCore(): void
    {
        $admin = $this->nuevoUsuario('okopanel4', true);
        $this->nuevoRol('okopanelrolfila');

        $controlador = $this->ejecutar($admin, ['activetab' => 'ListRole', 'query' => 'okopanelrolfila']);
        $this->assertNotEmpty($controlador->views['ListRole']->cursor, 'control positivo: el rol sembrado sale');
        $this->assertStringStartsWith('EditRole?code=okopanelrolfila', $controlador->views['ListRole']->cursor[0]->url());

        $controlador = $this->ejecutar($admin, ['activetab' => 'ListUser', 'query' => 'okopanel4']);
        $this->assertNotEmpty($controlador->views['ListUser']->cursor, 'control positivo: el usuario sembrado sale');
        $this->assertStringStartsWith('EditUser?code=okopanel4', $controlador->views['ListUser']->cursor[0]->url());

        foreach (['ListUser', 'ListRole'] as $vista) {
            $this->assertTrue($controlador->views[$vista]->settings['clickable'], $vista . ' tiene que poder pincharse');
        }
    }

    /**
     * @description
     * **Tres entradas de menú se quedan en una**: las páginas viejas ya no existen y la del panel sí,
     * que es lo que lee el menú. `Plugins::deploy()` borra las páginas cuyo controlador desapareció.
     */
    public function testLasPaginasViejasDesaparecenDelMenu(): void
    {
        $pagina = new Page();
        $this->assertTrue($pagina->loadFromCode('AdminPermisos'), 'control positivo: la página del panel existe');
        $this->assertTrue((bool)$pagina->showonmenu, 'y sale en el menú');
        $this->assertSame('admin', $pagina->menu);

        foreach (['ListPermisoEfectivo', 'PermisosEspeciales', 'VistaDelMenu', 'AdminPermisosEspeciales'] as $vieja) {
            $this->assertFalse((new Page())->loadFromCode($vieja), $vieja . ' sigue en la tabla de páginas');
        }
    }

    /**
     * @description
     * **Se busca y se filtra DENTRO de cada entrada**, con la petición que manda el formulario de la
     * pestaña: la búsqueda en roles, y el filtro admin / no admin en usuarios.
     */
    public function testSeBuscaYSeFiltraDentroDeLaPestana(): void
    {
        $admin = $this->nuevoUsuario('okopanel2', true);
        $this->nuevoUsuario('okopanel3', false);
        $this->nuevoRol('okopanelrolbuscado');
        $this->nuevoRol('okopanelrolotro');

        $roles = $this->ejecutar($admin, ['activetab' => 'ListRole', 'query' => 'okopanelrolbuscado'])->views['ListRole'];
        $codigos = array_map(static fn ($rol) => $rol->codrole, $roles->cursor);
        $this->assertContains('okopanelrolbuscado', $codigos, 'control positivo: el rol buscado sale');
        $this->assertNotContains('okopanelrolotro', $codigos, 'la búsqueda no acotó la lista de roles');

        // el filtro `type` de usuarios va por posición desde 0 (`SelectWhereFilter`): 0 todos, 1 admin, 2 no admin
        $usuarios = $this->ejecutar($admin, ['activetab' => 'ListUser', 'query' => 'okopanel', 'filtertype' => '2'])
            ->views['ListUser'];
        $nicks = array_map(static fn ($usuario) => $usuario->nick, $usuarios->cursor);
        $this->assertContains('okopanel3', $nicks, 'control positivo: el no admin sale con el filtro «no admin»');
        $this->assertNotContains('okopanel2', $nicks, 'el filtro no acotó la lista de usuarios');
    }

    /**
     * @description
     * **Siete entradas, en su orden, y TODAS activas.** La trampa es de `PanelController`: desactiva todas
     * las vistas menos la primera si la principal «no tiene datos», y lo mide con `$model->exists()`, que
     * en una lista es siempre falso. Sin el `hasData` de `loadData()` el panel enseñaría una sola entrada.
     */
    public function testSieteEntradasEnSuOrdenYTodasActivas(): void
    {
        $controlador = $this->ejecutar($this->nuevoUsuario('okopanel1', true), []);

        $this->assertSame(array_keys(self::ENTRADAS), array_keys($controlador->views));
        foreach (self::ENTRADAS as $vista => $clase) {
            $this->assertInstanceOf($clase, $controlador->views[$vista], $vista);
            $this->assertTrue($controlador->views[$vista]->settings['active'],
                $vista . ' está desactivada: el panel sólo enseñaría la primera entrada');
        }
    }

    /**
     * @description
     * **«Permisos efectivos» arranca con «Tiene acceso: sí»**, que es la lista de siempre; «no» y «todos»
     * se piden a propósito. El filtro va por posición: 0 sí, 1 no, 2 todos.
     */
    public function testElFiltroTieneAccesoArrancaEnSi(): void
    {
        $admin = $this->nuevoUsuario('okopanel7', true);
        $usuario = $this->nuevoUsuario('okopanel8', false);
        $rol = $this->nuevoRol('okopanelroltiene');
        $acceso = new RoleAccess();
        $acceso->codrole = $rol->codrole;
        $acceso->pagename = 'AdminPermisos';
        $this->assertTrue($acceso->save());
        $pertenencia = new RoleUser();
        $pertenencia->codrole = $rol->codrole;
        $pertenencia->nick = $usuario->nick;
        $this->assertTrue($pertenencia->save());

        $filas = function (array $post) use ($admin): array {
            $vista = $this->ejecutar($admin, $post + ['activetab' => 'ListPermisoEfectivo', 'filternick' => 'okopanel8'])
                ->views['ListPermisoEfectivo'];
            return [$vista->count, array_unique(array_map(static fn ($f) => (int)$f->tiene_acceso, $vista->cursor))];
        };

        [$porDefecto, $valores] = $filas([]);
        $this->assertSame(1, $porDefecto, 'sin tocar el filtro: sólo la página concedida');
        $this->assertSame([1], array_values($valores));

        [$sinAcceso, $valores] = $filas(['filtertiene_acceso' => '1']);
        $this->assertSame([0], array_values($valores));

        [$todas] = $filas(['filtertiene_acceso' => '2']);
        $this->assertSame($porDefecto + $sinAcceso, $todas);
    }

    /**
     * @description
     * **El buscador casa por el nombre REAL de la página**, no por el girado que se pinta: quien busca
     * «ConfigEmail» —el que se concede en la ficha del rol— lo encuentra.
     */
    public function testElBuscadorCasaPorElNombreReal(): void
    {
        $vista = $this->ejecutar($this->nuevoUsuario('okopanel9', true),
            ['activetab' => 'ListPermisoEfectivo', 'query' => 'ConfigEmail', 'filternick' => 'okopanel9'])
            ->views['ListPermisoEfectivo'];
        $this->assertSame(['ConfigEmail'], array_values(array_unique(array_map(static fn ($f) => $f->pagina, $vista->cursor))));
        $this->assertSame('EmailConfig (ConfigEmail)', $vista->cursor[0]->pagina_nombre);
    }

    /**
     * @description
     * **El orden por defecto junta cada entidad con la lista configurable de prefijos**, y dentro los
     * prefijos por orden alfabético; la página sin prefijo va la primera. Se lee del panel de verdad
     * —la vista sin orden elegido—, no de un orden escrito en el test.
     */
    public function testElOrdenPorDefectoJuntaCadaEntidad(): void
    {
        $admin = $this->nuevoUsuario('okopanel10', true);
        foreach (['ListOkoPermisosPruebaOrden', 'ReportOkoPermisosPruebaOrden', 'EditOkoPermisosPruebaOrden',
                     'OkoPermisosPruebaOrden', 'NewsletterOkoPermisosPrueba'] as $nombre) {
            $pagina = new Page();
            $pagina->name = $nombre;
            $pagina->title = $nombre;
            $pagina->menu = 'okopermprueba';
            $pagina->showonmenu = str_starts_with($nombre, 'List');
            $this->assertTrue($pagina->save(), 'no se pudo sembrar ' . $nombre);
        }

        $vista = $this->ejecutar($admin, ['activetab' => 'ListPermisoEfectivo', 'filternick' => 'okopanel10',
            'query' => 'OkoPermisosPrueba'])->views['ListPermisoEfectivo'];
        $paginas = array_map(static fn ($f) => $f->pagina, $vista->cursor);

        $this->assertSame(['OkoPermisosPruebaOrden', 'EditOkoPermisosPruebaOrden', 'ListOkoPermisosPruebaOrden',
            'ReportOkoPermisosPruebaOrden'], array_values(array_filter($paginas,
            static fn ($p) => str_ends_with($p, 'PruebaOrden'))), 'la entidad junta: sin prefijo, Edit, List, Report');
        $this->assertContains('NewsletterOkoPermisosPrueba', $paginas, 'control positivo: la otra entidad sale');
    }

    /**
     * @description
     * **Las entidades van en orden ALFABÉTICO, no por el título de su página.** `pages.title` es la clave
     * de traducción sin traducir, y con el `ordernum` empatado mandaba ella: aquí la entidad «Zeta» lleva
     * el título que va antes («aaa») y «Alfa» el que va después, para que los dos criterios discrepen.
     */
    public function testLasEntidadesVanPorOrdenAlfabetico(): void
    {
        $admin = $this->nuevoUsuario('okopanel11', true);
        foreach (['ListOkoPermisosPruebaZeta' => 'aaa', 'EditOkoPermisosPruebaZeta' => 'aab',
                     'ListOkoPermisosPruebaAlfa' => 'zzz', 'EditOkoPermisosPruebaAlfa' => 'zzy'] as $nombre => $titulo) {
            $pagina = new Page();
            $pagina->name = $nombre;
            $pagina->title = $titulo;
            $pagina->menu = 'okopermprueba';
            $pagina->ordernum = 100;
            $pagina->showonmenu = str_starts_with($nombre, 'List');
            $this->assertTrue($pagina->save(), 'no se pudo sembrar ' . $nombre);
        }

        $vista = $this->ejecutar($admin, ['activetab' => 'ListPermisoEfectivo', 'filternick' => 'okopanel11',
            'query' => 'OkoPermisosPrueba'])->views['ListPermisoEfectivo'];
        $this->assertSame(['EditOkoPermisosPruebaAlfa', 'ListOkoPermisosPruebaAlfa',
            'EditOkoPermisosPruebaZeta', 'ListOkoPermisosPruebaZeta'],
            array_map(static fn ($f) => $f->pagina, $vista->cursor));
    }

    /**
     * @description
     * **El panel no escribe nada**, desde ninguna pestaña y con ninguna acción: ni borrar, ni editar, ni
     * crear. Lo prueba quien más puede —un usuario no admin con un rol que le da el panel con «modificar»
     * y «borrar»—, apuntando a víctimas de cada modelo: un admin, una página, un rol y su acceso.
     *
     * Se mide en la base, con la huella de las cuatro tablas antes y después de cada petición, y no en la
     * configuración de la vista: `PanelController` del core sólo mira `btnDelete` al borrar, y al editar
     * o crear no mira ningún ajuste de la pestaña. Control positivo de que la petición llega a la acción:
     * el aviso del panel sale una vez por cada una.
     */
    public function testElPanelNoEscribeDesdeNingunaPestana(): void
    {
        $quien = $this->nuevoUsuario('okopanel20', false);
        $rol = $this->nuevoRol('okopanelrolescribe');
        $this->darAcceso($rol, 'AdminPermisos');
        $vinculo = new RoleUser();
        $vinculo->codrole = $rol->codrole;
        $vinculo->nick = $quien->nick;
        $this->assertTrue($vinculo->save(), 'no se pudo dar el rol');

        $permisos = new ControllerPermissions($quien, 'AdminPermisos');
        $this->assertTrue($permisos->allowDelete && $permisos->allowUpdate,
            'control positivo: el rol tiene que dar «modificar» y «borrar» sobre el panel');

        $cambios = [];
        $sinAviso = [];
        foreach (array_keys(self::ENTRADAS) as $pestana) {
            foreach (['delete', 'edit', 'insert'] as $accion) {
                $victimas = $this->sembrarVictimas();
                $codigo = $victimas[$this->modeloDe($pestana)] ?? $victimas['User'];
                $antes = $this->huella();
                $avisos = $this->avisosDelPanel();

                $this->ejecutar($quien, ['action' => $accion, 'activetab' => $pestana, 'code' => $codigo]);

                if ($avisos + 1 !== $this->avisosDelPanel()) {
                    $sinAviso[] = $accion . ' en ' . $pestana;
                }
                $despues = $this->huella();
                foreach ($antes as $tabla => $filas) {
                    $quitadas = count(array_diff($filas, $despues[$tabla]));
                    $puestas = count(array_diff($despues[$tabla], $filas));
                    if ($quitadas || $puestas) {
                        $cambios[] = sprintf('%s en %s: %s -%d +%d', $accion, $pestana, $tabla, $quitadas, $puestas);
                    }
                }
            }
        }

        $this->assertSame([], $cambios, 'el panel escribió en la base');
        $this->assertSame([], $sinAviso, 'la petición no llegó a la acción del panel');
    }

    /**
     * @description
     * **Ninguna pestaña ofrece «nuevo» ni «borrar».** Lo que cierra la escritura es el panel; esto es
     * para que la interfaz no enseñe un botón que no hace nada. Las `HtmlView` los traen encendidos por
     * omisión, así que hay que apagarlos a mano.
     */
    public function testNingunaPestanaOfreceNuevoNiBorrar(): void
    {
        $controlador = $this->ejecutar($this->nuevoUsuario('okopanel22', true), []);

        foreach (array_keys(self::ENTRADAS) as $vista) {
            $this->assertFalse($controlador->views[$vista]->settings['btnNew'], $vista . ' ofrece «nuevo»');
            $this->assertFalse($controlador->views[$vista]->settings['btnDelete'], $vista . ' ofrece «borrar»');
        }
    }

    /** Cuántas veces ha dicho el panel que no escribe: una por petición que llegó a la acción. */
    private function avisosDelPanel(): int
    {
        $total = 0;
        foreach (MiniLog::read() as $mensaje) {
            if ('okopermisos-no-escribe' === $mensaje['original']) {
                $total += $mensaje['count'];
            }
        }

        return $total;
    }

    private function darAcceso(Role $rol, string $pagina): void
    {
        $acceso = new RoleAccess();
        $acceso->codrole = $rol->codrole;
        $acceso->pagename = $pagina;
        $acceso->allowdelete = true;
        $acceso->allowupdate = true;
        $this->assertTrue($acceso->save(), 'no se pudo dar acceso a ' . $pagina);
    }

    /**
     * Las cuatro tablas que el panel enseña, fila a fila. Se compara el contenido, no el recuento: una
     * edición que vuelve a guardar el registro con otro valor tiene que verse igual que un borrado.
     *
     * @return array<string, string[]>
     */
    private function huella(): array
    {
        $db = new DataBase();
        $huella = [];
        foreach (['users', 'pages', 'roles', 'roles_access'] as $tabla) {
            $huella[$tabla] = array_map('json_encode', $db->select('SELECT * FROM ' . $tabla));
        }

        return $huella;
    }

    /** El modelo de cada pestaña, por el nombre corto de su clase: `User`, `Page`, `Role`… */
    private function modeloDe(string $pestana): string
    {
        $controlador = new AdminPermisos('AdminPermisos');
        $metodo = new \ReflectionMethod($controlador, 'createViews');
        $metodo->invoke($controlador);
        $clase = get_class($controlador->views[$pestana]->model);

        return substr($clase, strrpos($clase, '\\') + 1);
    }

    /**
     * Una víctima por modelo, que se vuelve a sembrar si una petición anterior se la llevó: así cada
     * acción se mide sobre el mismo punto de partida.
     *
     * @return array<string, string> el código de cada víctima, por modelo
     */
    private function sembrarVictimas(): array
    {
        $usuario = new User();
        if (false === $usuario->loadFromCode('okopanel21')) {
            $this->nuevoUsuario('okopanel21', true);
        }

        $pagina = new Page();
        if (false === $pagina->loadFromCode('OkoPermisosPruebaVictima')) {
            $pagina->name = 'OkoPermisosPruebaVictima';
            $pagina->title = 'OkoPermisosPruebaVictima';
            $pagina->menu = 'admin';
            $this->assertTrue($pagina->save(), 'no se pudo sembrar la página víctima');
        }

        $rol = new Role();
        if (false === $rol->loadFromCode('okopanelrolvictima')) {
            $rol = $this->nuevoRol('okopanelrolvictima');
        }

        $acceso = new RoleAccess();
        if (false === $acceso->loadWhere([Where::eq('codrole', $rol->codrole), Where::eq('pagename', 'OkoPermisosPruebaVictima')])) {
            $this->darAcceso($rol, 'OkoPermisosPruebaVictima');
            $acceso->loadWhere([Where::eq('codrole', $rol->codrole), Where::eq('pagename', 'OkoPermisosPruebaVictima')]);
        }

        return ['User' => 'okopanel21', 'Page' => 'OkoPermisosPruebaVictima', 'Role' => 'okopanelrolvictima',
            'RoleAccess' => (string)$acceso->id];
    }

    /**
     * Ejecuta el panel como lo haría el kernel: la petición en las superglobales ANTES de construirlo, y
     * después `privateCore`.
     *
     * Antes, no después: el controlador lee la petición en su constructor (`Request::createFromGlobals`) y
     * `BaseController` fija ahí mismo la pestaña activa. Cambiarla luego por reflexión deja `activetab` en
     * el valor por defecto, y con él la búsqueda, que sólo se procesa en la pestaña activa.
     *
     * El token se genera con la misma semilla que tendrá la petición: `MultiRequestProtection` la guarda
     * estática y `privateCore` le AÑADE el nick, así que se vacía, se genera con el nick y se vuelve a
     * vaciar para que `privateCore` la deje igual.
     */
    private function ejecutar(User $usuario, array $post): AdminPermisos
    {
        $proteccion = new MultiRequestProtection();
        $proteccion->clearSeed();
        $proteccion->addSeed($usuario->nick);
        $post['multireqtoken'] = $proteccion->newToken();
        $proteccion->clearSeed();

        $antes = [$_POST, $_GET, $_SERVER['REQUEST_METHOD'] ?? null];
        $_POST = $post;
        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        try {
            $controlador = new AdminPermisos('AdminPermisos');
            $respuesta = new Response();
            $controlador->privateCore($respuesta, $usuario, new ControllerPermissions($usuario, 'AdminPermisos'));
        } finally {
            [$_POST, $_GET] = $antes;
            if (null === $antes[2]) {
                unset($_SERVER['REQUEST_METHOD']);
            } else {
                $_SERVER['REQUEST_METHOD'] = $antes[2];
            }
        }

        return $controlador;
    }

    private function nuevoRol(string $codrole): Role
    {
        $rol = new Role();
        $rol->codrole = $codrole;
        $rol->descripcion = 'Rol de prueba ' . $codrole;
        $this->assertTrue($rol->save(), 'no se pudo sembrar el rol ' . $codrole);
        return $rol;
    }

    /** Los nicks llevan un dígito: `setPassword()` lo exige y sin él el `save()` falla. */
    private function nuevoUsuario(string $nick, bool $admin): User
    {
        $usuario = new User();
        $usuario->nick = $nick;
        $usuario->email = $nick . '@okopermisos.test';
        $usuario->setPassword('okopermisos-' . $nick);
        $usuario->admin = $admin;
        $this->assertTrue($usuario->save(), 'no se pudo sembrar el usuario ' . $nick);
        return $usuario;
    }

    protected function tearDown(): void
    {
        $db = new DataBase();
        $db->connect();
        $db->exec('DELETE FROM roles_users WHERE nick LIKE \'okopanel%\';');
        $db->exec('DELETE FROM roles_access WHERE codrole LIKE \'okopanelrol%\';');
        $db->exec('DELETE FROM roles WHERE codrole LIKE \'okopanelrol%\';');
        $db->exec('DELETE FROM users WHERE nick LIKE \'okopanel%\';');
        $db->exec('DELETE FROM pages WHERE name LIKE \'%OkoPermisosPrueba%\';');

        $this->logErrors();
    }
}
