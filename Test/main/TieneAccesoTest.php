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
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\Join\PermisoEfectivo;
use FacturaScripts\Dinamic\Model\Page;
use FacturaScripts\Dinamic\Model\Role;
use FacturaScripts\Dinamic\Model\RoleAccess;
use FacturaScripts\Dinamic\Model\RoleUser;
use FacturaScripts\Dinamic\Model\User;
use FacturaScripts\Plugins\OkoPermisos\Lib\MenuDelUsuario;
use FacturaScripts\Test\Traits\LogErrorsTrait;
use PHPUnit\Framework\TestCase;

/**
 * **«Permisos efectivos» tiene una fila por usuario × página, y `tiene_acceso` dice si entra.**
 *
 * El caso que lo pidió: una ficha concedida por un rol, sin «modificar» y fuera del menú, se ENTRA y no
 * se puede guardar; antes la fila no decía por qué estaba ahí.
 */
final class TieneAccesoTest extends TestCase
{
    use LogErrorsTrait;

    private const CON_ACCESO = 'EditOkoPermisosPruebaFicha';

    private const SIN_ACCESO = 'EditOkoPermisosPruebaAjena';

    /**
     * @description
     * **Acceso sin «modificar» y fuera del menú: tiene acceso, y lo dice.** «Visible en el menú» sigue
     * siendo la propiedad de la página.
     */
    public function testAccesoSinModificarYFueraDelMenuTieneAcceso(): void
    {
        $usuario = $this->conRol('okotiene1', 'okotienerol1');

        $fila = $this->fila($usuario->nick, self::CON_ACCESO);
        $this->assertSame(1, (int)$fila->tiene_acceso);
        $this->assertSame(0, (int)$fila->puede_escribir);
        $this->assertSame(0, (int)$fila->en_el_menu, 'una ficha fuera del menú no es visible aunque se tenga');
        $this->assertSame('okotienerol1', $fila->roles);
    }

    /**
     * @description
     * **«Visible en el menú» es del usuario**: una página de menú SIN acceso sale «no» —no la ve—, con
     * acceso «sí», y para el admin coincide con la página. El caso que lo pidió: una lista de menú sin
     * acceso salía «visible: sí».
     */
    public function testVisibleEnElMenuEsDelUsuario(): void
    {
        $conAcceso = $this->conRol('okotiene5', 'okotienerol5', 'ListOkoPermisosPruebaMenu');
        $sinAcceso = $this->conRol('okotiene6', 'okotienerol6');
        $admin = $this->nuevoUsuario('okotiene7', true);

        $this->assertSame(1, (int)$this->fila($conAcceso->nick, 'ListOkoPermisosPruebaMenu')->en_el_menu, 'control positivo');
        $fila = $this->fila($sinAcceso->nick, 'ListOkoPermisosPruebaMenu');
        $this->assertSame(0, (int)$fila->tiene_acceso);
        $this->assertSame(0, (int)$fila->en_el_menu, 'una página de menú sin acceso no es visible para él');
        $this->assertSame(1, (int)$this->fila($admin->nick, 'ListOkoPermisosPruebaMenu')->en_el_menu, 'el admin la ve');
        $this->assertSame(0, (int)$this->fila($admin->nick, self::SIN_ACCESO)->en_el_menu, 'ni el admin ve una ficha en el menú');
    }

    /** **El admin tiene acceso a todo**, como en `User::can()`, y la columna de roles lo dice. */
    public function testElAdminTieneAccesoATodo(): void
    {
        $admin = $this->nuevoUsuario('okotiene3', true);

        $this->assertSame([], $this->filas($admin->nick, [Where::eq('e.tiene_acceso', 0)]));
        $fila = $this->fila($admin->nick, self::SIN_ACCESO);
        $this->assertSame(1, (int)$fila->tiene_acceso);
        $this->assertStringStartsWith('(admin)', (string)$fila->roles);
        $this->assertSame(0, (int)$fila->cuantos_roles, 'los roles siguen siendo los suyos: ninguno');
    }

    /**
     * @description
     * **Una página sin rol sale, con «no»**, flags a 0 y sin roles. Y sólo la trae el filtro «no» o
     * «todos»: con `tiene_acceso = 1`, que es el filtro con el que arranca el panel, no sale.
     */
    public function testLaPaginaSinAccesoSaleConNo(): void
    {
        $usuario = $this->conRol('okotiene2', 'okotienerol2');

        $fila = $this->fila($usuario->nick, self::SIN_ACCESO);
        $this->assertSame(0, (int)$fila->tiene_acceso);
        $this->assertSame(0, (int)$fila->puede_escribir);
        $this->assertSame('', (string)$fila->roles);

        $conAcceso = array_column($this->filas($usuario->nick, [Where::eq('e.tiene_acceso', 1)]), 'pagina');
        $this->assertContains(self::CON_ACCESO, $conAcceso, 'control positivo');
        $this->assertNotContains(self::SIN_ACCESO, $conAcceso);
        $this->assertCount(1, $conAcceso, 'con «sí» sale lo mismo que antes: sólo lo concedido');

        $sinAcceso = array_column($this->filas($usuario->nick, [Where::eq('e.tiene_acceso', 0)]), 'pagina');
        $this->assertContains(self::SIN_ACCESO, $sinAcceso);
        $this->assertNotContains(self::CON_ACCESO, $sinAcceso);

        $paginas = (new Page())->count();
        $this->assertCount($paginas, $this->filas($usuario->nick, []), 'con «todos», una fila por página');
    }

    /**
     * @description
     * **Quien reutiliza la vista no cuenta páginas sin acceso**: la Vista del menú de un usuario con UNA
     * página del menú concedida da 1, no todas las del menú.
     */
    public function testLaVistaDelMenuNoCuentaLoQueNoTiene(): void
    {
        $usuario = $this->conRol('okotiene4', 'okotienerol4', 'ListOkoPermisosPruebaMenu');

        $tiene = 0;
        foreach (MenuDelUsuario::arbol($usuario->nick) as $menu) {
            $tiene += $menu['tiene'];
        }
        $this->assertSame(1, $tiene);
    }

    /** Un usuario con un rol que da acceso sin «modificar» a una página. */
    private function conRol(string $nick, string $codrole, string $pagina = self::CON_ACCESO): User
    {
        $usuario = $this->nuevoUsuario($nick, false);

        $rol = new Role();
        $rol->codrole = $codrole;
        $rol->descripcion = 'Rol de prueba ' . $codrole;
        $this->assertTrue($rol->save());

        $acceso = new RoleAccess();
        $acceso->codrole = $codrole;
        $acceso->pagename = $pagina;
        $acceso->allowupdate = false;
        $this->assertTrue($acceso->save());

        $pertenencia = new RoleUser();
        $pertenencia->codrole = $codrole;
        $pertenencia->nick = $nick;
        $this->assertTrue($pertenencia->save());
        return $usuario;
    }

    private function fila(string $nick, string $pagina): PermisoEfectivo
    {
        $filas = $this->filas($nick, [Where::eq('e.pagename', $pagina)]);
        $this->assertCount(1, $filas, 'no hay una fila para ' . $nick . ' × ' . $pagina);
        return $filas[0];
    }

    private function filas(string $nick, array $where): array
    {
        return (new PermisoEfectivo())->all(array_merge([Where::eq('e.nick', $nick)], $where), [], 0, 0);
    }

    private function nuevoUsuario(string $nick, bool $admin): User
    {
        $usuario = new User();
        $usuario->nick = $nick;
        $usuario->email = $nick . '@okopermisos.test';
        $this->assertTrue($usuario->setPassword('okopermisos1-' . $nick));
        $usuario->admin = $admin;
        $this->assertTrue($usuario->save());
        return $usuario;
    }

    private function pagina(string $nombre, bool $enMenu): void
    {
        $pagina = new Page();
        $pagina->name = $nombre;
        $pagina->title = $nombre;
        $pagina->menu = 'okopermprueba';
        $pagina->showonmenu = $enMenu;
        $this->assertTrue($pagina->save(), 'no se pudo sembrar la página ' . $nombre);
    }

    protected function setUp(): void
    {
        $this->pagina(self::CON_ACCESO, false);
        $this->pagina(self::SIN_ACCESO, false);
        $this->pagina('ListOkoPermisosPruebaMenu', true);
    }

    protected function tearDown(): void
    {
        $db = new DataBase();
        $db->connect();
        $db->exec('DELETE FROM roles_users WHERE nick LIKE \'okotiene%\';');
        $db->exec('DELETE FROM roles_access WHERE codrole LIKE \'okotienerol%\';');
        $db->exec('DELETE FROM roles WHERE codrole LIKE \'okotienerol%\';');
        $db->exec('DELETE FROM users WHERE nick LIKE \'okotiene%\';');
        $db->exec('DELETE FROM pages WHERE name LIKE \'%OkoPermisosPrueba%\';');

        $this->logErrors();
    }
}
