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
use FacturaScripts\Core\Base\ControllerPermissions;
use FacturaScripts\Dinamic\Controller\AdminPermisos;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\Join\ImpactoDeQuitar;
use FacturaScripts\Dinamic\Model\Join\PermisoEfectivo;
use FacturaScripts\Dinamic\Model\Role;
use FacturaScripts\Dinamic\Model\RoleAccess;
use FacturaScripts\Dinamic\Model\RoleUser;
use FacturaScripts\Dinamic\Model\User;
use FacturaScripts\Test\Traits\LogErrorsTrait;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * **Que la agregación diga la verdad**, que es lo único que hace este plugin.
 *
 * Sin datos sembrados las dos consultas devuelven cero filas, y cero es exactamente lo que devuelve
 * también una consulta mal escrita. Así que cada aserción va precedida de su control positivo: se
 * siembra el caso y se comprueba que aparece, no sólo que no aparece lo que no está.
 *
 * Lo que se prueba es la aritmética que decide una poda:
 *
 * - un acceso por **un** rol → `cuantos_roles = 1` y quitarlo lo pierde el usuario;
 * - el mismo acceso por **dos** roles → `cuantos_roles = 2` y quitarlo de uno **no lo pierde nadie**;
 * - el flag efectivo es el **más permisivo** de los roles, no el del último ni el del primero.
 */
final class PermisosEfectivosTest extends TestCase
{
    use LogErrorsTrait;

    private const PAGINA = 'ListPaisPruebaOkoPermisos';

    /**
     * @description
     * **El caso que justifica el plugin**: el mismo acceso por dos roles. Quitarlo de uno **no deja a
     * nadie sin él**, y la interfaz del core no lo dice — de ahí que una poda se haga a ciegas.
     */
    public function testConDosRolesQuitarUnoNoLoPierdeNadie(): void
    {
        [$usuario, $rolA] = $this->sembrar('okoperm2', 'okopermrolA');
        $rolB = $this->nuevoRol('okopermrolB');
        $this->darAcceso($rolB, false);
        $this->asignar($usuario, $rolB);

        $filas = (new PermisoEfectivo())->all([Where::eq('e.nick', $usuario->nick), Where::eq('e.tiene_acceso', 1)], [], 0, 0);
        $this->assertCount(1, $filas, 'sigue siendo UNA fila: se agrupa por usuario y página');
        $this->assertSame(2, (int)$filas[0]->cuantos_roles, 'ahora lo conceden dos roles');
        $this->assertStringContainsString($rolA->codrole, (string)$filas[0]->roles);
        $this->assertStringContainsString($rolB->codrole, (string)$filas[0]->roles);

        foreach ([$rolA, $rolB] as $rol) {
            $impacto = (new ImpactoDeQuitar())->all([Where::eq('ra.codrole', $rol->codrole)], [], 0, 0);
            $this->assertCount(1, $impacto);
            $this->assertSame(0, (int)$impacto[0]->lo_perderian,
                'quitarlo de ' . $rol->codrole . ' no deja a nadie sin el acceso: lo da el otro rol');
        }

        $this->limpiar($usuario, [$rolA, $rolB]);
    }

    /**
     * @description
     * **El filtro «no puede escribir» tiene que mirar el permiso AGREGADO, no las filas.**
     *
     * Con dos roles —uno que concede escritura y otro que no— el permiso efectivo es que **sí** puede,
     * así que el filtro «no» no debe traerlo. Filtrando antes de agrupar sí lo traería, porque existe
     * una fila con `allowupdate = 0`: es el error exacto que la subconsulta derivada evita, y por el que
     * hubo que rehacer el modelo.
     */
    public function testElFiltroDeNoEscribirMiraElPermisoAgregado(): void
    {
        [$usuario, $rolRO] = $this->sembrar('okoperm6', 'okopermrol6RO');
        $rolRW = $this->nuevoRol('okopermrol6RW');
        $this->darAcceso($rolRW, true);
        $this->asignar($usuario, $rolRW);

        $mio = [Where::eq('e.nick', $usuario->nick), Where::eq('e.tiene_acceso', 1)];

        $siEscribe = (new PermisoEfectivo())->all(array_merge($mio, [Where::eq('e.puede_escribir', 1)]), [], 0, 0);
        $this->assertCount(1, $siEscribe, 'su permiso efectivo SÍ es de escritura: un rol se la concede');

        $noEscribe = (new PermisoEfectivo())->all(array_merge($mio, [Where::eq('e.puede_escribir', 0)]), [], 0, 0);
        $this->assertSame([], $noEscribe,
            'el filtro «no» no puede traerlo: tiene un rol sin escritura, pero el efectivo es que sí puede');

        $this->limpiar($usuario, [$rolRO, $rolRW]);
    }

    /**
     * @description
     * **El filtro por grupo no casa de más.** Con dos roles cuyo nombre es prefijo del otro, filtrar por
     * el corto no debe traer al largo.
     *
     * Los dos comparten el prefijo `okopermrol` de todo lo que siembra este test, y no es cosmético: el
     * `tearDown` limpia por ese patrón, y ampliarlo a `oko%` para cubrir otros nombres borraría roles
     * reales de otros plugins en cualquier entorno con datos.
     *
     * No es un caso inventado: dos roles donde uno es prefijo del otro —«ventas» y «ventasconsulta»— son
     * habituales, y un `LIKE` suelto sobre el `GROUP_CONCAT` traía las filas del otro rol. Si ése concede
     * cosas distintas, mezclarlos haría inservible la herramienta para lo que se hizo.
     */
    public function testElFiltroPorGrupoNoCasaDeMas(): void
    {
        $corto = $this->nuevoRol('okopermrolpfj');
        $largo = $this->nuevoRol('okopermrolpfjx');
        $this->darAcceso($corto, false);
        $this->darAcceso($largo, false);

        $delCorto = $this->nuevoUsuario('okoperm7corto');
        $this->asignar($delCorto, $corto);
        $delLargo = $this->nuevoUsuario('okoperm7largo');
        $this->asignar($delLargo, $largo);

        $filas = (new PermisoEfectivo())->all([Where::like('e.roles_cercado', '%,okopermrolpfj,%')], [], 0, 0);
        $this->assertCount(1, $filas, 'sólo el usuario del rol corto');
        $this->assertSame('okoperm7corto', $filas[0]->nick,
            'filtrar por «okopermrolpfj» no puede traer al de «okopermrolpfjx»');

        $delLargoFiltrado = (new PermisoEfectivo())->all([Where::like('e.roles_cercado', '%,okopermrolpfjx,%')], [], 0, 0);
        $this->assertCount(1, $delLargoFiltrado, 'y el rol largo trae el suyo');
        $this->assertSame('okoperm7largo', $delLargoFiltrado[0]->nick);

        $this->limpiar($delCorto, []);
        $this->limpiar($delLargo, [$corto, $largo]);
    }

    /**
     * @description
     * **El filtro por menú y los cuatro flags.** La página sembrada no está en `pages`, así que su
     * `menu` es NULL y el filtro por un menú concreto NO debe traerla: es el control negativo del
     * filtro. Y se comprueban las cuatro columnas de permisos, no sólo la de escritura, porque
     * `allowexport` y `allowimport` se agregan igual y se olvidan igual.
     */
    public function testElFiltroPorMenuYLosCuatroFlags(): void
    {
        [$usuario, $rol] = $this->sembrar('okoperm5', 'okopermrol5');

        $todas = (new PermisoEfectivo())->all([Where::eq('e.nick', $usuario->nick), Where::eq('e.tiene_acceso', 1)], [], 0, 0);
        $this->assertCount(1, $todas, 'sin filtro de menú, la fila está');
        $this->assertNull($todas[0]->menu, 'la página de prueba no está en `pages`: su menú es nulo');

        // el control negativo: filtrando por un menú real, esta fila no puede aparecer
        $delMenu = (new PermisoEfectivo())->all(
            [Where::eq('e.nick', $usuario->nick), Where::eq('p.menu', 'admin'), Where::eq('e.tiene_acceso', 1)], [], 0, 0);
        $this->assertSame([], $delMenu, 'el filtro por menú tiene que excluir lo que no es de ese menú');

        // Los cuatro flags, y aquí hay una sorpresa del core que este test fija a propósito:
        // `RoleAccess::clear()` pone `allowexport` y `allowimport` a TRUE por omisión, así que un
        // acceso creado sin tocarlos permite exportar e importar. La siembra sólo fija escritura y
        // borrado, y aun así los otros dos salen a 1: un acceso de «sólo lectura» puede permitir exportar.
        $this->assertSame(0, (int)$todas[0]->puede_escribir, 'no se concedió escritura');
        $this->assertSame(0, (int)$todas[0]->puede_borrar, 'no se concedió borrado');
        $this->assertSame(1, (int)$todas[0]->puede_exportar,
            'el core concede exportación por omisión: sólo lectura NO impide sacar los datos');
        $this->assertSame(1, (int)$todas[0]->puede_importar, 'e importación, por el mismo motivo');

        $this->limpiar($usuario, [$rol]);
    }

    /**
     * @description
     * **El flag efectivo es el más permisivo.** Un rol que concede escritura y otro que no dan
     * escritura: un rol restrictivo no restringe si otro permite. Es la misma trampa que el solape de
     * páginas, pero por columna, y es la razón de que la vista muestre los flags agregados y no los
     * de un rol cualquiera.
     */
    public function testElFlagEfectivoEsElMasPermisivo(): void
    {
        [$usuario, $rolSoloLectura] = $this->sembrar('okoperm3', 'okopermrolRO');

        $filas = (new PermisoEfectivo())->all([Where::eq('e.nick', $usuario->nick), Where::eq('e.tiene_acceso', 1)], [], 0, 0);
        $this->assertSame(0, (int)$filas[0]->puede_escribir, 'de partida, sólo lectura');

        $rolEscritura = $this->nuevoRol('okopermrolRW');
        $this->darAcceso($rolEscritura, true);
        $this->asignar($usuario, $rolEscritura);

        $filas = (new PermisoEfectivo())->all([Where::eq('e.nick', $usuario->nick), Where::eq('e.tiene_acceso', 1)], [], 0, 0);
        $this->assertSame(1, (int)$filas[0]->puede_escribir,
            'con un rol que concede escritura, el efectivo es que SÍ puede escribir');

        $this->limpiar($usuario, [$rolSoloLectura, $rolEscritura]);
    }

    /**
     * @description
     * **La pestaña de administradores sólo lista administradores.** Sin acotar caía en la vista del
     * core, que lista la tabla `users` entera, y la pestaña presentaba como admin a quien no lo es.
     *
     * Se carga con una búsqueda puesta —la que haría el usuario dentro de la pestaña— para comprobar
     * que la acotación se SUMA a lo que filtre él y no la sustituye.
     */
    public function testLaPestanaDeAdminsSoloListaAdmins(): void
    {
        $admin = $this->nuevoUsuario('okopermadm1');
        $admin->admin = true;
        $this->assertTrue($admin->save(), 'no se pudo marcar como admin');
        $this->nuevoUsuario('okopermnoadm1');

        $controlador = new AdminPermisos('AdminPermisos');
        $controlador->permissions = new ControllerPermissions();
        $crear = new ReflectionMethod($controlador, 'createViews');
        $crear->setAccessible(true);
        $crear->invoke($controlador);

        $vista = $controlador->views['ListUser-admins'];
        $vista->where = [Where::like('nick', 'okoperm%')];
        $cargar = new ReflectionMethod($controlador, 'loadData');
        $cargar->setAccessible(true);
        $cargar->invoke($controlador, 'ListUser-admins', $vista);

        $nicks = array_map(static fn ($usuario) => $usuario->nick, $vista->cursor);
        $this->assertContains('okopermadm1', $nicks, 'control positivo: el admin sembrado tiene que salir');
        $this->assertNotContains('okopermnoadm1', $nicks, 'un usuario sin el flag admin no es administrador');
        foreach ($vista->cursor as $usuario) {
            $this->assertTrue((bool)$usuario->admin, $usuario->nick . ' sale en la pestaña sin ser admin');
        }

        // La megabúsqueda cargaba la vista sin pasar por `loadData()` y listaba a todos. En un panel no
        // llega: `MegaSearch` sólo consulta las páginas cuyo nombre empieza por `List`. Esto fija que siga
        // siendo así, porque volver a llamarlo `List…` reabriría ese agujero.
        $this->assertStringStartsNotWith('List', $controlador->getPageData()['name'],
            'una página List… la consulta MegaSearch, que se salta la acotación a admin');
    }

    /**
     * @description
     * **Las vistas de cruce no son clicables.** Sus filas no son registros —son el cruce de un usuario o
     * un rol con una página, agregado sobre varios roles— así que no hay ficha que abrir, y
     * `JoinModel::url()` devuelve cadena vacía. Con el destino vacío el clic **saca al usuario de la
     * pantalla y lo deja en su página de inicio** —por omisión, `AdminPlugins`—. Se encontró pinchando
     * una fila.
     *
     * Se comprueba sobre el controlador construido, no con un `grep`: una línea comentada pasaría igual.
     */
    public function testLasVistasDeCruceNoSonClicables(): void
    {
        $controlador = new AdminPermisos('AdminPermisos');
        $crear = new ReflectionMethod($controlador, 'createViews');
        $crear->setAccessible(true);
        $crear->invoke($controlador);

        foreach (['ListPermisoEfectivo', 'ListImpactoDeQuitar'] as $vista) {
            $this->assertArrayHasKey($vista, $controlador->views, 'falta la vista ' . $vista);
            $this->assertFalse($controlador->views[$vista]->settings['clickable'],
                $vista . ' no tiene destino: pinchar saca al usuario a su página de inicio');
            $this->assertSame('', $controlador->views[$vista]->model->url(),
                'y su url() es vacía, que es la causa: por eso hay que desactivar el clic');
        }
    }

    /**
     * @description
     * **Los admin no cuentan en el impacto.** Para ellos el acceso es total pase lo que pase en los
     * roles, así que incluirlos inflaría «usuarios afectados» con gente a la que no le cambia nada.
     */
    public function testLosAdminNoCuentanEnElImpacto(): void
    {
        [$usuario, $rol] = $this->sembrar('okoperm4', 'okopermrol4');
        $usuario->admin = true;
        $this->assertTrue($usuario->save());

        $impacto = (new ImpactoDeQuitar())->all([Where::eq('ra.codrole', $rol->codrole)], [], 0, 0);
        $this->assertSame([], $impacto,
            'siendo su único usuario un admin, quitar el acceso no afecta a nadie que se cuente');

        $this->limpiar($usuario, [$rol]);
    }

    /**
     * @description
     * Un acceso concedido por **un solo rol**: la vista lo ve, dice qué rol es, y el impacto dice que
     * el usuario lo perdería.
     */
    public function testUnAccesoPorUnRolSeVeYSeguiriaPerdiendo(): void
    {
        [$usuario, $rol] = $this->sembrar('okoperm1', 'okopermrol1');

        $filas = (new PermisoEfectivo())->all([Where::eq('e.nick', $usuario->nick), Where::eq('e.tiene_acceso', 1)], [], 0, 0);
        $this->assertCount(1, $filas, 'la vista tiene que ver el acceso que acabo de sembrar');
        $this->assertSame(self::PAGINA, $filas[0]->pagina);
        $this->assertSame(1, (int)$filas[0]->cuantos_roles, 'lo concede un solo rol');
        $this->assertSame($rol->codrole, $filas[0]->roles, 'y tiene que decir cuál');

        $impacto = (new ImpactoDeQuitar())->all([Where::eq('ra.codrole', $rol->codrole)], [], 0, 0);
        $this->assertCount(1, $impacto);
        $this->assertSame(1, (int)$impacto[0]->lo_perderian, 'con un solo rol, quitarlo se nota');
        $this->assertStringContainsString($usuario->nick, (string)$impacto[0]->quienes);

        $this->limpiar($usuario, [$rol]);
    }

    private function asignar(User $usuario, Role $rol): void
    {
        $ru = new RoleUser();
        $ru->codrole = $rol->codrole;
        $ru->nick = $usuario->nick;
        $this->assertTrue($ru->save(), 'no se pudo asignar el rol al usuario');
    }

    private function darAcceso(Role $rol, bool $escritura): void
    {
        $acceso = new RoleAccess();
        $acceso->codrole = $rol->codrole;
        $acceso->pagename = self::PAGINA;
        $acceso->allowupdate = $escritura;
        $acceso->allowdelete = false;
        $this->assertTrue($acceso->save(), 'no se pudo dar el acceso');
    }

    /** En orden inverso: primero lo que apunta, después lo apuntado. */
    private function limpiar(User $usuario, array $roles): void
    {
        foreach ((new RoleUser())->all([Where::eq('e.nick', $usuario->nick)], [], 0, 0) as $ru) {
            $ru->delete();
        }
        foreach ($roles as $rol) {
            // `RoleAccess` es un modelo normal, no el Join: aquí la columna NO lleva alias
            foreach ((new RoleAccess())->all([Where::eq('codrole', $rol->codrole)], [], 0, 0) as $ra) {
                $ra->delete();
            }
            $rol->delete();
        }
        $usuario->delete();
    }

    private function nuevoRol(string $codrole): Role
    {
        $rol = new Role();
        $rol->codrole = $codrole;
        $rol->descripcion = 'rol de prueba de OkoPermisos';
        $this->assertTrue($rol->save(), 'no se pudo sembrar el rol ' . $codrole);
        return $rol;
    }

    private function nuevoUsuario(string $nick): User
    {
        $usuario = new User();
        $usuario->nick = $nick;
        $usuario->email = $nick . '@okopermisos.test';
        $usuario->setPassword('okopermisos-' . $nick);
        $usuario->admin = false;
        $this->assertTrue($usuario->save(), 'no se pudo sembrar el usuario ' . $nick);
        return $usuario;
    }

    /** Siembra un usuario con un rol que le da acceso de sólo lectura a la página de prueba. */
    private function sembrar(string $nick, string $codrole): array
    {
        $usuario = new User();
        $usuario->nick = $nick;
        $usuario->email = $nick . '@okopermisos.test';
        $usuario->setPassword('okopermisos-' . $nick);
        $usuario->admin = false;
        $this->assertTrue($usuario->save(), 'no se pudo sembrar el usuario');

        $rol = $this->nuevoRol($codrole);
        $this->darAcceso($rol, false);
        $this->asignar($usuario, $rol);

        return [$usuario, $rol];
    }

    /**
     * **Limpia siempre**, aunque el test haya muerto a mitad.
     *
     * La limpieza estaba sólo al final de cada prueba, así que un fallo dejaba el rol y el usuario
     * sembrados y la siguiente corrida fallaba por duplicado — con un mensaje que no señalaba la causa
     * real. Se descubrió saboteando el modelo a propósito: los sabotajes fallaban bien, y después el
     * código restaurado seguía en rojo por la basura de las corridas anteriores.
     */
    protected function tearDown(): void
    {
        $db = new DataBase();
        $db->connect();
        $db->exec('DELETE FROM roles_users WHERE nick LIKE \'okoperm%\';');
        $db->exec('DELETE FROM roles_access WHERE codrole LIKE \'okopermrol%\';');
        $db->exec('DELETE FROM roles WHERE codrole LIKE \'okopermrol%\';');
        $db->exec('DELETE FROM users WHERE nick LIKE \'okoperm%\';');
        // `OkoPermisosPrueba` y no `Oko`: el barrido anterior era `ListOko%`/`EditOko%`, que casa con las
        // páginas REALES de los demás plugins propios que estén registradas en la base de pruebas. Es el mismo error que ya se cometió con los roles.
        $db->exec('DELETE FROM pages WHERE name LIKE \'%OkoPermisosPrueba%\';');

        $this->logErrors();
    }
}
