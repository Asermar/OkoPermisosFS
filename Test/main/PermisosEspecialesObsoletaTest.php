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
use FacturaScripts\Core\Base\MiniLog;
use FacturaScripts\Core\Cache;
use FacturaScripts\Core\Html;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\LogMessage;
use FacturaScripts\Dinamic\Model\User;
use FacturaScripts\Plugins\OkoPermisos\Lib\PermisosEspeciales;
use FacturaScripts\Test\Traits\LogErrorsTrait;
use PHPUnit\Framework\TestCase;

/**
 * **El esqueleto de la API retirada**: un plugin que todavía la llama no tumba nada, nadie salvo el admin
 * pasa, y se avisa una vez al día por plugin.
 *
 * Los avisos se cuentan sumando el `count` de `MiniLog`, no las entradas: `MiniLog` junta en una sola
 * entrada los mensajes idénticos de la misma petición, así que contar entradas no vería un aviso repetido.
 */
final class PermisosEspecialesObsoletaTest extends TestCase
{
    use LogErrorsTrait;

    /** La URL de antes del test, que el del gestor cambia. */
    private ?string $uri;

    /**
     * @description
     * **En el registro, un aviso al día por plugin**, en el canal propio; fuera del gestor no sale en
     * pantalla. Se olvida la memoria de la petición entre llamadas para que la segunda tenga que mirar el
     * fichero, que es lo que decide. Otro plugin avisa aparte, y un fichero con el día de ayer vuelve a
     * avisar.
     */
    public function testAvisaUnaVezPorPluginYDia(): void
    {
        $this->registrarDesde('SondaObsA');
        PermisosEspeciales::olvidarAvisos();
        $this->registrarDesde('SondaObsA');
        $this->assertSame(1, $this->avisos('SondaObsA'), 'la segunda vez del día no avisa');
        $this->assertSame(0, $this->avisosEnPantalla('SondaObsA'), 'fuera del gestor no sale en pantalla');

        $this->registrarDesde('SondaObsB');
        $this->assertSame(1, $this->avisos('SondaObsB'), 'otro plugin avisa aparte');

        file_put_contents($this->fichero(), json_encode(['SondaObsA' => date('Y-m-d', strtotime('-1 day'))]));
        PermisosEspeciales::olvidarAvisos();
        $this->registrarDesde('SondaObsA');
        $this->assertSame(2, $this->avisos('SondaObsA'), 'otro día vuelve a avisar');
    }

    /**
     * @description
     * **El aviso en pantalla lo pinta la vista del gestor, no la llamada a la API.** La API sólo anota el
     * plugin, aunque la URL sea la del gestor: el `Init` corre antes de autenticar, y avisar ahí se lo
     * enseñaría a un anónimo en el login. `avisarEnElGestor()` lo pinta cada vez que se renderiza
     * `AdminPlugins` o `Updater`, y en otra página no; el registro sigue siendo uno al día.
     */
    public function testElAvisoEnPantallaLoPintaElGestor(): void
    {
        $_SERVER['REQUEST_URI'] = '/AdminPlugins';
        $this->registrarDesde('SondaObsD');
        $this->assertSame(0, $this->avisosEnPantalla('SondaObsD'), 'la llamada a la API no pinta nada');
        $this->assertSame(1, $this->avisos('SondaObsD'), 'control: el registro del día');

        PermisosEspeciales::avisarEnElGestor('ListUser');
        PermisosEspeciales::avisarEnElGestor('');
        $this->assertSame(0, $this->avisosEnPantalla('SondaObsD'), 'otra página, o sin controlador, no');

        foreach (['AdminPlugins', 'AdminPlugins', 'Updater'] as $n => $controlador) {
            PermisosEspeciales::avisarEnElGestor($controlador);
            $this->assertSame($n + 1, $this->avisosEnPantalla('SondaObsD'), 'el gestor avisa siempre: ' . $controlador);
        }
        $this->assertSame(1, $this->avisos('SondaObsD'), 'y el registro sigue siendo uno al día');
    }

    /**
     * @description
     * **El freno busca el nombre exacto del plugin.** Un `_` en el nombre no hace de comodín de LIKE —el
     * aviso de `SondaXObsG` no frena el de `Sonda_ObsG`, que es otro plugin—, y un nombre no ASCII, que el
     * registro guarda como `\uXXXX`, también se encuentra: si no, ese plugin avisaría en cada petición.
     */
    public function testElFrenoBuscaElNombreExacto(): void
    {
        mkdir($this->fichero());
        $this->registrarDesde('SondaXObsG');
        MiniLog::save(PermisosEspeciales::CANAL);
        PermisosEspeciales::olvidarAvisos();
        $this->registrarDesde('Sonda_ObsG');
        $this->assertSame(1, $this->avisos('Sonda_ObsG'), 'el _ no es comodín: es otro plugin');

        $this->registrarDesde('SondaÑObsG');
        $this->assertSame(1, $this->avisos('SondaÑObsG'), 'control: la primera vez avisa');
        MiniLog::save(PermisosEspeciales::CANAL);
        PermisosEspeciales::olvidarAvisos();
        // como si hubiera caducado la entrada de Cache, que si no frenaría antes de mirar logs
        Cache::deleteMulti('okopermisos-obsoleto-');
        $this->registrarDesde('SondaÑObsG');
        $this->assertSame(0, $this->avisos('SondaÑObsG'), 'un nombre no ASCII se encuentra en el registro');
    }

    /**
     * @description
     * **El gancho no rompe la petición que activa OkoPermisos.** En ella el core ya lo cuenta como activo y
     * pinta el gancho, pero su `Init` no ha corrido: ni la función Twig ni el espacio de nombres
     * `@OkoPermisosAviso` existen. Se quitan los dos de `Html` por reflexión y se borra la caché de Twig,
     * como hace el despliegue de esa misma petición; si no, una plantilla ya compilada lo taparía. Sin el
     * `Init` no hay aviso, pero tampoco error.
     */
    public function testElGanchoNoFallaSinElInit(): void
    {
        $this->registrarDesde('SondaObsF');
        $functions = new \ReflectionProperty(Html::class, 'functions');
        $paths = new \ReflectionProperty(Html::class, 'paths');
        $functions->setAccessible(true);
        $paths->setAccessible(true);
        $antesFunciones = $functions->getValue();
        $antesRutas = $paths->getValue();

        try {
            $functions->setValue(null, array_values(array_filter(
                $antesFunciones,
                fn($funcion) => $funcion->getName() !== 'okopermisosAvisarObsoletos'
            )));
            $paths->setValue(null, array_diff_key($antesRutas, ['OkoPermisosAviso' => true]));
            Tools::folderDelete(Tools::folder('MyFiles', 'Cache', 'Twig'));
            $salida = Html::render('@PluginExtensionOkoPermisos/MenuTemplate_HeadEnd.html.twig', ['controllerName' => 'AdminPlugins']);
        } finally {
            $functions->setValue(null, $antesFunciones);
            $paths->setValue(null, $antesRutas);
        }

        $this->assertSame('', trim($salida));
        $this->assertSame(0, $this->avisosEnPantalla('SondaObsF'), 'sin Init no hay aviso');
    }

    /**
     * @description
     * **Sin clase, el plugin sale de la pila**: quien llama a `puede()` desde una clase de un plugin es
     * ese plugin. La clase sonda se define con `eval` porque tiene que vivir en el namespace de un plugin.
     */
    public function testElPluginSaleDeLaPila(): void
    {
        if (false === class_exists('FacturaScripts\\Plugins\\SondaObsPila\\Llamante')) {
            eval('namespace FacturaScripts\\Plugins\\SondaObsPila; class Llamante { public static function llama(): bool { return \\FacturaScripts\\Plugins\\OkoPermisos\\Lib\\PermisosEspeciales::puede("okoobs-a"); } }');
        }

        \FacturaScripts\Plugins\SondaObsPila\Llamante::llama();
        $this->assertSame(1, $this->avisos('SondaObsPila'));
    }

    /**
     * @description
     * **Toda la API vieja se puede llamar sin que lance**, con las firmas de antes, y devuelve lo neutro:
     * no registra, no hay registrados ni rol, y `plugin()` sigue sabiendo de qué plugin es una clase.
     */
    public function testLaApiViejaSePuedeLlamar(): void
    {
        $this->assertSame('okopermisos', PermisosEspeciales::AJUSTES);
        $this->assertFalse(PermisosEspeciales::registrar('okoobs-a', User::class, 'prueba'), 'no registra');
        $this->assertSame([], PermisosEspeciales::registrados());
        $this->assertSame('', PermisosEspeciales::rolDe('okoobs-a'));
        $this->assertSame('OkoPermisos', PermisosEspeciales::plugin(PermisosEspeciales::class));
        PermisosEspeciales::olvidar('okoobs-a');
        PermisosEspeciales::puede('okoobs-a');
        PermisosEspeciales::exigir('okoobs-a');
        PermisosEspeciales::motivo('okoobs-a');
    }

    /**
     * @description
     * **La vista del gancho llama al aviso con el controlador que se renderiza**, y sin él no rompe. Se
     * renderiza la vista real, con la función Twig que registra el `Init`.
     */
    public function testLaVistaDelGestorAvisa(): void
    {
        $this->registrarDesde('SondaObsE');
        $vista = '@PluginExtensionOkoPermisos/MenuTemplate_HeadEnd.html.twig';
        Html::render($vista, ['controllerName' => 'ListUser']);
        Html::render($vista);
        $this->assertSame(0, $this->avisosEnPantalla('SondaObsE'), 'fuera del gestor, nada');
        Html::render($vista, ['controllerName' => 'AdminPlugins']);
        $this->assertSame(1, $this->avisosEnPantalla('SondaObsE'));
    }

    /**
     * @description
     * **Niega a todos salvo al administrador**, con usuario explícito y con el de la sesión (vacía en
     * PHPUnit, así que niega). La negativa de `exigir()` se avisa y `motivo()` la explica.
     */
    public function testNiegaATodosSalvoAlAdmin(): void
    {
        $admin = new User();
        $admin->admin = true;
        $otro = new User();
        $otro->nick = 'okoobs1';
        $otro->admin = false;

        $this->assertTrue(PermisosEspeciales::puede('okoobs-a', $admin));
        $this->assertTrue(PermisosEspeciales::exigir('okoobs-a', $admin));
        $this->assertSame('', PermisosEspeciales::motivo('okoobs-a', $admin));

        $this->assertFalse(PermisosEspeciales::puede('okoobs-a', $otro));
        $this->assertFalse(PermisosEspeciales::puede('okoobs-a'), 'sin sesión niega');
        MiniLog::clear();
        $this->assertFalse(PermisosEspeciales::exigir('okoobs-a', $otro));
        $this->assertNotEmpty(MiniLog::read('master', ['warning']), 'la negativa se avisa');
        $this->assertStringContainsString('okoobs-a', PermisosEspeciales::motivo('okoobs-a', $otro));
    }

    /**
     * @description
     * **Sin fichero pero con `Cache`, la segunda petición no consulta `logs`**: `Cache` recuerda que hoy ya
     * se avisó, y la consulta a `logs`, que lo recorre entero, no se repite en cada petición. Es el caso de
     * un fichero que no se puede escribir en un `MyFiles` que sí (p. ej. creado por otro usuario). Se
     * cuentan las consultas mismas, con el SQL que `DataBase` apunta en `MiniLog` (canal `database`, con
     * `FS_DEBUG`): mirar sólo si avisa no basta, porque `Cache` frenaría igual aunque se hubiera consultado
     * antes. La primera petición es el control de que el instrumento ve la consulta.
     */
    public function testSinFicheroRecuerdaEnCache(): void
    {
        mkdir($this->fichero());
        MiniLog::clear(DataBase::CHANNEL);
        $this->registrarDesde('SondaObsH');
        $this->assertSame(1, $this->avisos('SondaObsH'), 'control: la primera vez avisa');
        $this->assertSame(1, $this->consultasALogs(), 'control: sin Cache, la primera consulta logs una vez');
        MiniLog::save(PermisosEspeciales::CANAL);

        foreach ([2, 3] as $peticion) {
            PermisosEspeciales::olvidarAvisos();
            MiniLog::clear(DataBase::CHANNEL);
            $this->registrarDesde('SondaObsH');
            $this->assertSame(0, $this->consultasALogs(), 'petición ' . $peticion . ': con Cache no consulta logs');
            $this->assertSame(0, $this->avisos('SondaObsH'), 'petición ' . $peticion . ': no vuelve a avisar');
            $this->assertSame(0, $this->avisosSinFichero(), 'petición ' . $peticion . ': ni a decir lo del fichero');
        }
    }

    /**
     * @description
     * **Si el fichero no se puede escribir, el registro no crece**: el freno es el propio `logs`, que ya
     * tiene el aviso de hoy, porque `Cache` sin disco tampoco se escribe. La primera petición avisa y
     * dice que el fichero no se puede escribir; las siguientes, nada. Cada petición se cierra con
     * `MiniLog::save()`, que es lo que hace el core al terminarla. Un directorio en lugar del fichero hace
     * fallar la lectura y la escritura; un aviso de ayer en `logs` deja volver a avisar.
     */
    public function testSinFicheroNoLlenaElRegistro(): void
    {
        mkdir($this->fichero());
        $cache = FS_FOLDER . Cache::FILE_PATH;
        $modo = fileperms($cache) & 0777;
        chmod($cache, 0555);
        // una petición real no muere por los warnings de escritura del core (Cache::set), y aquí tampoco
        set_error_handler(fn() => true);

        try {
            Cache::set('okopermisos-sonda-sin-disco', true);
            $this->assertFalse(Cache::has('okopermisos-sonda-sin-disco'), 'control: Cache tampoco puede escribir');

            $this->registrarDesde('SondaObsC');
            $this->assertSame(1, $this->avisos('SondaObsC'), 'la primera vez avisa');
            $this->assertSame(1, $this->avisosSinFichero(), 'y dice que no puede escribir el fichero');
            MiniLog::save(PermisosEspeciales::CANAL);
            $filas = $this->filasEnLogs();
            $this->assertSame(2, $filas, 'control: el aviso y el de sin fichero quedan en logs');

            foreach ([2, 3] as $peticion) {
                PermisosEspeciales::olvidarAvisos();
                $this->registrarDesde('SondaObsC');
                $this->assertSame(0, $this->avisos('SondaObsC'), 'petición ' . $peticion . ': no vuelve a avisar');
                $this->assertSame(0, $this->avisosSinFichero(), 'petición ' . $peticion . ': ni a decir lo del fichero');
                MiniLog::save(PermisosEspeciales::CANAL);
                $this->assertSame($filas, $this->filasEnLogs(), 'petición ' . $peticion . ': logs no crece');
            }

            foreach (LogMessage::all([Where::eq('channel', PermisosEspeciales::CANAL)]) as $fila) {
                $fila->time = Tools::dateTime('-1 day');
                $fila->save();
            }
            PermisosEspeciales::olvidarAvisos();
            $this->registrarDesde('SondaObsC');
            $this->assertSame(1, $this->avisos('SondaObsC'), 'otro día vuelve a avisar');
        } finally {
            restore_error_handler();
            chmod($cache, $modo);
        }
    }

    /** Lo que se avisó en el canal propio para un plugin, contando los repetidos. */
    private function avisos(string $plugin): int
    {
        return $this->contar(MiniLog::read(PermisosEspeciales::CANAL, ['warning']), $plugin);
    }

    /** Lo que se avisó en pantalla: `master`, cualquier nivel. */
    private function avisosEnPantalla(string $plugin): int
    {
        return $this->contar(MiniLog::read('master'), $plugin);
    }

    private function avisosSinFichero(): int
    {
        $total = 0;
        foreach (MiniLog::read(PermisosEspeciales::CANAL, ['warning']) as $entrada) {
            $total += $entrada['original'] === 'okopermisos-obsoleto-sin-fichero' ? $entrada['count'] : 0;
        }
        return $total;
    }

    /** Las consultas del freno a `logs` que ha apuntado `DataBase` en `MiniLog`, contando las repetidas. */
    private function consultasALogs(): int
    {
        $total = 0;
        foreach (MiniLog::read(DataBase::CHANNEL, ['debug']) as $entrada) {
            $sql = $entrada['original'];
            if (preg_match('/^SELECT COUNT\(.*\bFROM `?logs`? WHERE/i', $sql) && str_contains($sql, PermisosEspeciales::CANAL)) {
                $total += $entrada['count'];
            }
        }
        return $total;
    }

    private function contar(array $entradas, string $plugin): int
    {
        $total = 0;
        foreach ($entradas as $entrada) {
            if ($entrada['original'] === 'okopermisos-obsoleto-aviso' && ($entrada['context']['%plugin%'] ?? '') === $plugin) {
                $total += $entrada['count'];
            }
        }
        return $total;
    }

    private function fichero(): string
    {
        return FS_FOLDER . PermisosEspeciales::FICHERO_AVISOS;
    }

    /** Las filas del canal propio guardadas en `logs`. La base de pruebas no tiene otras: `limpiar()` las borra. */
    private function filasEnLogs(): int
    {
        return LogMessage::count([Where::eq('channel', PermisosEspeciales::CANAL)]);
    }

    private function limpiar(): void
    {
        $fichero = $this->fichero();
        if (is_dir($fichero)) {
            rmdir($fichero);
        } elseif (file_exists($fichero)) {
            unlink($fichero);
        }
        foreach (LogMessage::all([Where::eq('channel', PermisosEspeciales::CANAL)]) as $fila) {
            $fila->delete();
        }
        Cache::deleteMulti('okopermisos-obsoleto-');
        PermisosEspeciales::olvidarAvisos();
        MiniLog::clear();
    }

    /** `registrar()` toma el plugin de la clase que recibe, que no tiene por qué existir. */
    private function registrarDesde(string $plugin): void
    {
        PermisosEspeciales::registrar('okoobs-a', 'FacturaScripts\\Plugins\\' . $plugin . '\\Model\\Algo', 'prueba');
    }

    protected function setUp(): void
    {
        $this->uri = $_SERVER['REQUEST_URI'] ?? null;
        unset($_SERVER['REQUEST_URI']);
        $this->limpiar();
    }

    protected function tearDown(): void
    {
        $this->logErrors();
        $this->limpiar();
        if (null === $this->uri) {
            unset($_SERVER['REQUEST_URI']);
        } else {
            $_SERVER['REQUEST_URI'] = $this->uri;
        }
    }
}
