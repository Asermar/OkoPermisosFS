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
use FacturaScripts\Core\Lib\MultiRequestProtection;
use FacturaScripts\Core\Response;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Controller\AdminPermisos;
use FacturaScripts\Dinamic\Model\Join\ImpactoDeQuitar;
use FacturaScripts\Dinamic\Model\Join\PermisoEfectivo;
use FacturaScripts\Dinamic\Model\Page;
use FacturaScripts\Dinamic\Model\Role;
use FacturaScripts\Dinamic\Model\RoleAccess;
use FacturaScripts\Dinamic\Model\RoleUser;
use FacturaScripts\Dinamic\Model\User;
use FacturaScripts\Plugins\OkoPermisos\Lib\Motor;
use FacturaScripts\Test\Traits\LogErrorsTrait;
use PHPUnit\Framework\TestCase;

/**
 * **Las consultas que MySQL/MariaDB y PostgreSQL escriben distinto dan el mismo resultado exacto.**
 *
 * Es el escenario que se lanza en los dos motores. Cada caso tiene datos elegidos para que una versión de
 * la consulta que sólo valiera en uno de ellos —o que perdiera la semántica al portarla— dé otra cosa: un
 * prefijo en minúsculas que no tiene que girar, roles sembrados al revés de su orden, y un usuario que
 * conserva el acceso por otro rol. Se compara con `assertSame` contra el texto entero, no con un «contiene».
 */
final class DosMotoresTest extends TestCase
{
    use LogErrorsTrait;

    /**
     * @description
     * **Cada orden del panel se ejecuta y devuelve filas**, en las dos listas que ordenan por campos
     * calculados. En PostgreSQL un alias dentro de una expresión del ORDER BY —`LOWER(pagina_nombre)`— es
     * un error, y la lista sale vacía sin que la pantalla lo diga.
     */
    public function testCadaOrdenDelPanelDevuelveFilas(): void
    {
        $admin = $this->usuario('okomotor1', true);
        $this->accesos($this->rol('okomotorrola'), ['ListOkoPermisosPruebaMotor' => true]);
        $this->asignar('okomotor1', 'okomotorrola');
        // el impacto no cuenta a los admin: hace falta uno que no lo sea
        $this->usuario('okomotor2', false);
        $this->asignar('okomotor2', 'okomotorrola');

        foreach (['ListPermisoEfectivo' => ['filternick' => 'okomotor1'], 'ListImpactoDeQuitar' => []] as $vista => $filtro) {
            $opciones = $this->ejecutar($admin, ['activetab' => $vista])->views[$vista]->orderOptions;
            $this->assertNotEmpty($opciones, 'control positivo: la lista ' . $vista . ' tiene órdenes');
            foreach (array_keys($opciones) as $clave) {
                Tools::log()::clear();
                $cargada = $this->ejecutar($admin, ['activetab' => $vista, 'order' => (string)$clave] + $filtro)->views[$vista];
                $this->assertSame((string)$clave, $cargada->orderKey, 'no se aplicó el orden ' . $clave);
                $this->assertSame([], Tools::log()::read('', ['critical', 'error']), $vista . ', orden ' . $clave);
                $this->assertNotEmpty($cargada->cursor, $vista . ' vacía con el orden ' . $clave);
            }
        }
    }

    /**
     * @description
     * **El filtro «sólo menú / sólo fichas» separa por `pages.showonmenu`**, que es booleano. El filtro lo
     * compara con el entero 1 o 0: `var2str` lo escribe entre comillas, y PostgreSQL acepta '1' como
     * booleano, pero no lo probaba ningún caso en ningún motor.
     */
    public function testElFiltroDeMenuSeparaListasDeFichas(): void
    {
        $admin = $this->usuario('okomotor1', true);
        $this->pagina('ListOkoPermisosPruebaMotor');
        $this->pagina('EditOkoPermisosPruebaMotor');

        foreach (['1' => 'ListOkoPermisosPruebaMotor', '2' => 'EditOkoPermisosPruebaMotor'] as $opcion => $esperada) {
            Tools::log()::clear();
            $vista = $this->ejecutar($admin, ['activetab' => 'ListPermisoEfectivo', 'filternick' => 'okomotor1',
                'query' => 'OkoPermisosPruebaMotor', 'filteren_el_menu' => $opcion])->views['ListPermisoEfectivo'];
            $this->assertSame([], Tools::log()::read('', ['critical', 'error']), 'opción ' . $opcion);
            $this->assertSame([$esperada], array_map(static fn ($f) => $f->pagina, $vista->cursor), 'opción ' . $opcion);
        }
    }

    /**
     * @description
     * **El filtro de grupo distingue mayúsculas.** `Ventas` y `VENTAS` son roles distintos donde la base los
     * deja convivir —siempre en PostgreSQL; en MySQL/MariaDB sólo con una colación que distinga, como
     * `utf8_bin`—, y elegir uno no puede traer las filas del otro. Con la colación del instalador actual
     * (`utf8mb4_unicode_520_ci`) la clave primaria no los deja convivir, y el caso se salta diciéndolo.
     */
    public function testElFiltroDeGrupoDistingueMayusculas(): void
    {
        $mayus = $this->rol('okomotorrolC');
        // se mide ANTES de crear el otro: con una colación que no distingue, `save()` encontraría éste por
        // la clave y lo actualizaría en vez de insertar uno nuevo
        if ((new Role())->loadFromCode('okomotorrolc')) {
            $this->markTestSkipped('la colación de esta base no distingue mayúsculas en `roles.codrole`'
                . ' (`okomotorrolc` encuentra `okomotorrolC`): dos roles así no pueden convivir aquí');
        }

        $this->accesos($this->rol('okomotorrolc'), ['EditOkoPermisosPruebaMinus' => false]);
        $this->accesos($mayus, ['EditOkoPermisosPruebaMayus' => false]);
        $this->usuario('okomotor1', false);
        $this->asignar('okomotor1', 'okomotorrolC');
        $this->asignar('okomotor1', 'okomotorrolc');

        $this->assertSame(['EditOkoPermisosPruebaMayus'], $this->paginasDelGrupo('okomotorrolC', 'okomotor1'));
        $this->assertSame(['EditOkoPermisosPruebaMinus'], $this->paginasDelGrupo('okomotorrolc', 'okomotor1'));
    }

    /**
     * @description
     * **El filtro de grupo no toma el `_` de un código por un comodín.** Un `codrole` admite `_`, y en un
     * `LIKE` casa con cualquier carácter: elegir `okomotorrol_b` traía también las filas de `okomotorrolxb`.
     * Y `okomotorrol_bb` empieza igual: sin las comas del cercado, también entraría.
     */
    public function testElFiltroDeGrupoNoTomaElGuionBajoPorComodin(): void
    {
        $this->accesos($this->rol('okomotorrol_b'), ['EditOkoPermisosPruebaGuion' => false]);
        $this->accesos($this->rol('okomotorrolxb'), ['EditOkoPermisosPruebaEquis' => false]);
        $this->accesos($this->rol('okomotorrol_bb'), ['EditOkoPermisosPruebaDoble' => false]);
        $this->usuario('okomotor1', false);
        foreach (['okomotorrol_b', 'okomotorrolxb', 'okomotorrol_bb'] as $codrole) {
            $this->asignar('okomotor1', $codrole);
        }

        $this->assertSame(['EditOkoPermisosPruebaGuion'], $this->paginasDelGrupo('okomotorrol_b', 'okomotor1'));
        $this->assertSame(['EditOkoPermisosPruebaEquis'], $this->paginasDelGrupo('okomotorrolxb', 'okomotor1'),
            'control positivo: el otro rol sólo trae lo suyo');
    }

    /**
     * @description
     * **El impacto lista a quienes lo perderían, ordenados, y no a quien lo tiene por otro rol.** Los
     * usuarios se siembran fuera de orden y el del medio conserva el acceso: la lista exacta es la de los
     * otros dos, separados por comas.
     */
    public function testElImpactoListaQuienesLoPierden(): void
    {
        $pagina = 'EditOkoPermisosPruebaMotor';
        $this->accesos($this->rol('okomotorrola'), [$pagina => true]);
        $this->accesos($this->rol('okomotorrolb'), [$pagina => false]);
        foreach (['okomotor3', 'okomotor1', 'okomotor2'] as $nick) {
            $this->usuario($nick, false);
            $this->asignar($nick, 'okomotorrola');
        }
        $this->asignar('okomotor2', 'okomotorrolb');

        $filas = ImpactoDeQuitar::all([Where::eq('ra.codrole', 'okomotorrola'), Where::eq('ra.pagename', $pagina)]);
        $this->assertCount(1, $filas);
        $this->assertSame('okomotorrola|' . $pagina, $filas[0]->id);
        $this->assertSame('okomotor1,okomotor3', $filas[0]->quienes);
        $this->assertSame(3, (int)$filas[0]->usuarios_del_rol);
        $this->assertSame(2, (int)$filas[0]->lo_perderian);
        $this->assertSame(1, (int)$filas[0]->puede_escribir);
        $this->assertSame('OkoPermisosPruebaMotorEdit (' . $pagina . ')', $filas[0]->pagina_nombre);
        // a 0/1 y no el booleano crudo, que PostgreSQL devuelve como 't' o 'f'
        $this->assertSame('0', (string)$filas[0]->en_el_menu, 'la página sembrada no es de menú');

        $otro = ImpactoDeQuitar::all([Where::eq('ra.codrole', 'okomotorrolb'), Where::eq('ra.pagename', $pagina)]);
        $this->assertSame(0, (int)$otro[0]->puede_escribir, 'el rol b no concede escritura');
        $this->assertSame('', (string)$otro[0]->quienes, 'quitárselo a b no se lo quita a nadie');
    }

    /**
     * @description
     * **El prefijo gira sólo con su mayúscula y su minúscula exactas.** MySQL/MariaDB compara sin distinguir
     * mayúsculas, así que sin comparar en binario `listOko…` giraría como si fuera `List`; y un prefijo seguido de una
     * minúscula (`Editor…`) tampoco es una acción.
     */
    public function testElPrefijoDistingueMayusculas(): void
    {
        $this->usuario('okomotor1', true);
        foreach (['ListOkoPermisosPruebaMotor', 'listOkoPermisosPruebaMinus', 'EditorOkoPermisosPrueba'] as $nombre) {
            $this->pagina($nombre);
        }

        $gira = $this->fila('ListOkoPermisosPruebaMotor');
        $this->assertSame('OkoPermisosPruebaMotorList (ListOkoPermisosPruebaMotor)', $gira->pagina_nombre, 'control positivo');
        $this->assertSame('okopermisospruebamotor', $gira->entidad);
        $this->assertSame('list', $gira->prefijo);
        $this->assertSame('okopermisospruebamotorlist (listokopermisospruebamotor)', $gira->pagina_orden);

        foreach (['listOkoPermisosPruebaMinus', 'EditorOkoPermisosPrueba'] as $nombre) {
            $fila = $this->fila($nombre);
            $this->assertSame($nombre, $fila->pagina_nombre, $nombre . ' no tiene que girar');
            $this->assertSame(strtolower($nombre), $fila->entidad, 'sin prefijo, la entidad es el nombre entero');
            $this->assertSame('', (string)$fila->prefijo, 'sin prefijo, va la primera de su entidad');
        }
    }

    /**
     * @description
     * **La lista de usuarios del filtro marca «(admin)» sólo al admin.** PostgreSQL devuelve un booleano
     * leído en crudo como 'f', que en PHP es verdadero: leído así, todos salían como admin.
     */
    public function testLaListaDeUsuariosMarcaSoloAlAdmin(): void
    {
        $this->usuario('okomotor1', true);
        $this->usuario('okomotor2', false);

        $descripciones = array_column((new AdminPermisos('AdminPermisos'))->usuarios(), 'description', 'code');
        $this->assertSame('okomotor1 (admin)', $descripciones['okomotor1']);
        $this->assertSame('okomotor2', $descripciones['okomotor2']);
    }

    /**
     * @description
     * **`Motor::posicion()` es exacta en los dos motores, sea cual sea la colación.** Va sobre literales, así
     * que se ejerce también en una base cuya colación no distingue mayúsculas, donde el caso de los roles
     * `okomotorrolC`/`okomotorrolc` se salta: es lo que vigila la rama de MySQL/MariaDB.
     */
    public function testLaPosicionEsExacta(): void
    {
        $db = new DataBase();
        $db->connect();
        $casos = [
            ['Ventas,VENTAS', 'VENTAS', 8, 'control positivo: la encuentra, y en su sitio'],
            ['Ventas', 'VENTAS', 0, 'distingue mayúsculas'],
            ['aXb', 'a_b', 0, 'el _ no es un comodín'],
            ['a%b', 'a%b', 1, 'el % vale literalmente'],
        ];
        foreach ($casos as [$donde, $que, $esperada, $mensaje]) {
            $filas = $db->select('SELECT ' . Motor::posicion(Motor::texto($donde), $que) . ' AS p');
            $this->assertSame($esperada, (int)($filas[0]['p'] ?? -1), $mensaje);
        }
    }

    /**
     * @description
     * **Un texto entra en el SQL escapado por el motor.** Los prefijos son sólo letras, así que el escape no
     * se ejerce con ellos: se comprueba aparte, con una comilla, leyendo el literal de vuelta.
     */
    public function testUnTextoSeEscapa(): void
    {
        $db = new DataBase();
        $db->connect();
        $filas = $db->select('SELECT ' . Motor::texto("Edit' (") . ' AS t');
        $this->assertSame("Edit' (", $filas[0]['t'] ?? null);
    }

    /**
     * @description
     * **Varios roles se agregan en una lista ordenada y exacta**, y los flags por el más permisivo. Los roles
     * se asignan al revés de su orden, y el admin lleva la misma lista con «(admin)» delante.
     */
    public function testVariosRolesSeAgreganEnOrden(): void
    {
        $pagina = 'ListOkoPermisosPruebaMotor';
        $this->accesos($this->rol('okomotorrolb'), [$pagina => true], true);
        $this->accesos($this->rol('okomotorrola'), [$pagina => false], false);
        foreach (['okomotor1' => false, 'okomotor2' => true] as $nick => $esAdmin) {
            $this->usuario($nick, $esAdmin);
            $this->asignar($nick, 'okomotorrolb');
            $this->asignar($nick, 'okomotorrola');
        }

        $fila = $this->fila($pagina, 'okomotor1');
        $this->assertSame('okomotor1|' . $pagina, $fila->id);
        $this->assertSame('okomotorrola,okomotorrolb', $fila->roles, 'los dos, en orden y separados por una coma');
        $this->assertSame(',okomotorrola,okomotorrolb,', $fila->roles_cercado);
        $this->assertSame(2, (int)$fila->cuantos_roles);
        $this->assertSame(1, (int)$fila->puede_escribir, 'uno escribe: el más permisivo gana');
        $this->assertSame(0, (int)$fila->puede_borrar, 'ninguno borra');
        $this->assertSame(0, (int)$fila->solo_lo_suyo, 'uno no restringe a lo suyo: la restricción no aplica');
        $this->assertSame(1, (int)$fila->en_el_menu, 'es de menú y tiene acceso');

        $this->assertSame('(admin) okomotorrola,okomotorrolb', $this->fila($pagina, 'okomotor2')->roles);
    }

    /** Da a un rol acceso a cada página, con escritura según el valor y sin borrar. */
    private function accesos(Role $rol, array $paginas, bool $soloLoSuyo = false): void
    {
        foreach ($paginas as $nombre => $escribe) {
            if (false === (new Page())->loadFromCode($nombre)) {
                $this->pagina($nombre);
            }
            $acceso = new RoleAccess();
            $acceso->codrole = $rol->codrole;
            $acceso->pagename = $nombre;
            $acceso->allowupdate = $escribe;
            $acceso->allowdelete = false;
            $acceso->onlyownerdata = $soloLoSuyo;
            $this->assertTrue($acceso->save(), 'no se pudo dar acceso a ' . $nombre);
        }
    }

    private function asignar(string $nick, string $codrole): void
    {
        $asignacion = new RoleUser();
        $asignacion->codrole = $codrole;
        $asignacion->nick = $nick;
        $this->assertTrue($asignacion->save(), 'no se pudo asignar ' . $codrole . ' a ' . $nick);
    }

    /** Lo mismo que el panel al recibir el formulario, con el token de la sesión del usuario. */
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
            // la respuesta va por referencia: tiene que ser una variable
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

    private function fila(string $pagina, string $nick = 'okomotor1'): PermisoEfectivo
    {
        $filas = PermisoEfectivo::all([Where::eq('e.nick', $nick), Where::eq('e.pagename', $pagina)]);
        $this->assertCount(1, $filas, 'no hay fila para ' . $nick . ' en ' . $pagina);
        return $filas[0];
    }

    /**
     * Las páginas de `$nick` que deja pasar la opción del filtro de grupo de ese rol, tal como la arma el
     * panel: se toma su `Where` de `opcionesDeGrupo()` y no una copia.
     */
    private function paginasDelGrupo(string $codrole, string $nick): array
    {
        $metodo = new \ReflectionMethod(AdminPermisos::class, 'opcionesDeGrupo');
        $metodo->setAccessible(true);
        $deEseRol = array_values(array_filter($metodo->invoke(new AdminPermisos('AdminPermisos')),
            static fn (array $opcion): bool => str_ends_with($opcion['label'], '— ' . $codrole)));
        $this->assertCount(1, $deEseRol, 'el filtro no ofrece una opción para ' . $codrole);

        $where = array_merge($deEseRol[0]['where'], [Where::eq('e.nick', $nick), Where::eq('e.tiene_acceso', 1)]);
        $paginas = array_map(static fn ($f) => $f->pagina, PermisoEfectivo::all($where));
        sort($paginas);
        return $paginas;
    }

    /** Las de `List` son de menú, como las deja el deploy; las demás, fichas. */
    private function pagina(string $nombre): void
    {
        $pagina = new Page();
        $pagina->name = $nombre;
        $pagina->title = $nombre;
        $pagina->menu = 'okopermprueba';
        $pagina->showonmenu = str_starts_with($nombre, 'List');
        $this->assertTrue($pagina->save(), 'no se pudo sembrar la página ' . $nombre);
    }

    private function rol(string $codrole): Role
    {
        $rol = new Role();
        $rol->codrole = $codrole;
        $rol->descripcion = 'rol de prueba de OkoPermisos';
        $this->assertTrue($rol->save(), 'no se pudo sembrar el rol ' . $codrole);
        return $rol;
    }

    /** Limpia siempre, aunque el test haya muerto a mitad. */
    protected function tearDown(): void
    {
        $db = new DataBase();
        $db->connect();
        $db->exec('DELETE FROM roles_users WHERE nick LIKE \'okomotor%\';');
        $db->exec('DELETE FROM roles_access WHERE codrole LIKE \'okomotorrol%\';');
        $db->exec('DELETE FROM roles WHERE codrole LIKE \'okomotorrol%\';');
        $db->exec('DELETE FROM users WHERE nick LIKE \'okomotor%\';');
        $db->exec('DELETE FROM pages WHERE name LIKE \'%OkoPermisosPrueba%\';');

        $this->logErrors();
    }

    /** Los nicks llevan un dígito: `setPassword()` lo exige y sin él el `save()` falla. */
    private function usuario(string $nick, bool $admin): User
    {
        $usuario = new User();
        $usuario->nick = $nick;
        $usuario->email = $nick . '@okopermisos.test';
        $this->assertTrue($usuario->setPassword('okopermisos-' . $nick));
        $usuario->admin = $admin;
        $this->assertTrue($usuario->save(), 'no se pudo sembrar el usuario ' . $nick);
        return $usuario;
    }
}
