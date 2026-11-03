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

use FacturaScripts\Core\Cache;
use FacturaScripts\Core\Session;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\LogMessage;
use FacturaScripts\Dinamic\Model\User;

/**
 * **Esqueleto de la API retirada de permisos especiales.** Lo que la sustituye es `PermisoEspecial`.
 *
 * La API vieja guardaba los permisos en un registro en memoria que cada plugin llenaba desde su `Init`.
 * Se retiró, y un plugin que todavía la llama desde su `Init` tumbaba la instalación entera con un
 * `Class not found`, porque `Plugins::init()` no recoge ese error. Esta clase existe sólo para que eso
 * no pase: conserva las firmas públicas y no hace nada de lo que hacía.
 *
 * - `registrar()` no registra nada y `registrados()` devuelve vacío.
 * - `puede()`, `exigir()` y `motivo()` **niegan a todos salvo al administrador**, que en la API vieja
 *   tenía siempre el permiso. Nadie más puede hacer lo que protegían hasta que se actualice el plugin.
 * - **Avisa** de qué plugin la sigue usando, por dos vías: en el **gestor de plugins**, siempre que lo
 *   abra un usuario con sesión, para poder corregirlo desde ahí (`avisarEnElGestor()`); y en el canal
 *   `okopermisos-obsoleto` del registro, una vez al día por plugin.
 *
 * @deprecated se retira tres versiones después de la que la reintroduce. Usa `PermisoEspecial`.
 */
class PermisosEspeciales
{
    public const AJUSTES = 'okopermisos';

    /** El canal del registro donde se apunta quién sigue usando esta API. */
    public const CANAL = 'okopermisos-obsoleto';

    /** Dónde se recuerda, por plugin, el último día en que se avisó. Relativo a `FS_FOLDER`. */
    public const FICHERO_AVISOS = '/MyFiles/okopermisos-obsoleto.json';

    /** Los controladores del gestor de plugins: en ellos se avisa en pantalla siempre. */
    public const GESTORES = ['AdminPlugins', 'Updater'];

    /** @var array<string, true> los plugins ya comprobados en esta petición */
    private static array $vistos = [];

    /**
     * Pinta en el gestor de plugins el aviso de los plugins que han llamado a esta API en la petición.
     *
     * La llama la vista `Extension/View/MenuTemplate_HeadEnd`, con la función Twig que registra el `Init`.
     * Sólo la pintan las páginas con menú, que el core renderiza después de autenticar, y el login no la
     * lleva: por eso un anónimo no ve qué plugin está desactualizado. `HeadEnd` se pinta antes que el
     * bloque de mensajes, así que el aviso sale con los demás. Va como `warning` de `master`: sale en
     * pantalla y no se guarda en `logs`, que si no tendría una fila por visita.
     *
     * @internal no es parte de la API: la llama la vista
     */
    public static function avisarEnElGestor(string $controlador): string
    {
        if (in_array($controlador, self::GESTORES, true)) {
            foreach (array_keys(self::$vistos) as $plugin) {
                Tools::log()->warning('okopermisos-obsoleto-aviso', ['%plugin%' => $plugin]);
            }
        }

        return '';
    }

    /** @deprecated ver la clase */
    public static function exigir(string $codigo, ?User $user = null): bool
    {
        self::avisar();
        $motivo = self::motivoSinAviso($codigo, $user);
        if ('' === $motivo) {
            return true;
        }

        Tools::log()->warning($motivo);
        return false;
    }

    /** @deprecated ver la clase */
    public static function motivo(string $codigo, ?User $user = null): string
    {
        self::avisar();
        return self::motivoSinAviso($codigo, $user);
    }

    /** @deprecated ver la clase */
    public static function olvidar(string $codigo): void
    {
        self::avisar();
    }

    /**
     * Sólo para los tests: que la siguiente llamada vuelva a mirar el fichero.
     *
     * @internal no es parte de la API, ni de la vieja ni de la nueva
     */
    public static function olvidarAvisos(): void
    {
        self::$vistos = [];
    }

    /** @deprecated ver la clase. Conserva lo que hacía: es un cálculo sin efectos. */
    public static function plugin(string $clase): string
    {
        self::avisar();
        return self::pluginDe($clase);
    }

    /** @deprecated ver la clase */
    public static function puede(string $codigo, ?User $user = null): bool
    {
        self::avisar();
        return '' === self::motivoSinAviso($codigo, $user);
    }

    /**
     * @deprecated ver la clase
     * @return array<string, array{codigo: string, clase: string, descripcion: string}>
     */
    public static function registrados(): array
    {
        self::avisar();
        return [];
    }

    /** @deprecated ver la clase */
    public static function registrar(string $codigo, string $clase, string $descripcion): bool
    {
        self::avisar(self::pluginDe($clase));
        return false;
    }

    /** @deprecated ver la clase */
    public static function rolDe(string $codigo): string
    {
        self::avisar();
        return '';
    }

    /**
     * Si `logs` ya tiene hoy un apunte de este canal para `$plugin`. Es el último freno cuando no se puede
     * escribir el fichero: `Cache` va antes, pero vive en `MyFiles` igual que él, y sin disco `Cache::set()`
     * falla sin avisar. Vigila justo lo que protege: si `logs` no se escribe, tampoco crece. Es un recorrido
     * de `logs` (el LIKE no usa índice), y por eso sólo se pregunta cuando `Cache` no recuerda.
     *
     * No es exacto con peticiones simultáneas: `MiniLog` guarda al final de la petición, así que las que
     * lleguen antes de que se guarde la primera también apuntan. Deja de crecer en cuanto esa queda escrita.
     */
    private static function avisadoHoyEnElRegistro(string $plugin, string $hoy): bool
    {
        // el nombre se busca tal como se guarda: `MiniLogStorage` hace `json_encode` del contexto (un nombre no
        // ASCII queda como `\uXXXX`) y `LogMessage::test()` lo pasa por `noHtml` (las comillas, como entidades).
        // Y se escapan los comodines de LIKE: un `_` en el nombre casaría con cualquier carácter.
        $valor = addcslashes(Tools::noHtml(json_encode($plugin)), '\\%_');

        return LogMessage::count([
            Where::eq('channel', self::CANAL),
            Where::gte('time', $hoy . ' 00:00:00'),
            Where::like('context', '%' . $valor . '%'),
        ]) > 0;
    }

    /**
     * Anota que `$plugin` usa esta API, para el gestor, y lo deja en el registro una vez al día. Sin
     * plugin, sale de la pila. Una vez por petición y plugin.
     *
     * El día se guarda en un fichero y no en `Cache`, porque las entradas de `Cache` se dan por caducadas
     * a la hora (`Cache::EXPIRATION`). Si el fichero no se puede escribir, no se sabría nunca que hoy ya
     * se avisó y cada petición dejaría una fila en `logs`. Entonces se mira primero `Cache`, que ahorra la
     * consulta durante una hora, y si no recuerda, el propio `logs` (`avisadoHoyEnElRegistro()`), que es
     * lo que mantiene «una vez al día» y lo único que frena sin disco. Y se dice que el fichero no se
     * puede escribir.
     *
     * Sin disco, `Cache` tampoco se escribe y la consulta a `logs` se hace una vez por petición y plugin:
     * lo acotado es el crecimiento del registro, no las consultas. Cerrarlo exigiría un esquema propio.
     */
    private static function avisar(string $plugin = ''): void
    {
        $plugin = '' === $plugin ? self::llamante() : $plugin;
        if (isset(self::$vistos[$plugin])) {
            return;
        }
        self::$vistos[$plugin] = true;

        $fichero = FS_FOLDER . self::FICHERO_AVISOS;
        $hoy = date('Y-m-d');
        $leido = is_readable($fichero) ? @file_get_contents($fichero) : false;
        $avisos = false === $leido ? [] : json_decode($leido, true);
        $avisos = is_array($avisos) ? $avisos : [];
        if (($avisos[$plugin] ?? '') === $hoy) {
            return;
        }

        $avisos[$plugin] = $hoy;
        if (false === @file_put_contents($fichero, json_encode($avisos, JSON_PRETTY_PRINT), LOCK_EX)) {
            // el nombre va como hash: Cache lo usa de nombre de fichero, y uno largo pasaría de NAME_MAX y no se
            // guardaría; además Cache cambia `/` y `\` por `_`, así que A/B y A_B compartirían fichero
            $clave = 'okopermisos-obsoleto-' . $hoy . '-' . md5($plugin);
            if (Cache::has($clave)) {
                return;
            }
            $avisado = self::avisadoHoyEnElRegistro($plugin, $hoy);
            Cache::set($clave, true);
            if ($avisado) {
                return;
            }
            Tools::log(self::CANAL)->warning('okopermisos-obsoleto-sin-fichero', ['%fichero%' => self::FICHERO_AVISOS]);
        }

        Tools::log(self::CANAL)->warning('okopermisos-obsoleto-aviso', ['%plugin%' => $plugin]);
    }

    /** El plugin de quien llamó a esta clase, por el primer marco de la pila que no sea de ella. */
    private static function llamante(): string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $marco) {
            $clase = $marco['class'] ?? '';
            if ('' === $clase || $clase === self::class) {
                continue;
            }

            $plugin = self::pluginDe($clase);
            if ('' !== $plugin) {
                return $plugin;
            }
        }

        return Tools::trans('okopermisos-especial-origen-desconocido');
    }

    private static function motivoSinAviso(string $codigo, ?User $user): string
    {
        $user = $user ?? Session::user();
        if ($user->admin) {
            return '';
        }

        return Tools::trans('okopermisos-obsoleto-denegado', ['%codigo%' => $codigo]);
    }

    /**
     * El plugin del que viene una clase, o `Core`. Cadena vacía si no se sabe.
     *
     * Sale del namespace de la clase o de sus padres, no de la ruta del fichero: la que se instancia es
     * la de `Dinamic`, que extiende a la del plugin (`Dinamic\Model\X extends Plugins\<P>\Model\X`).
     */
    private static function pluginDe(string $clase): string
    {
        $actual = ltrim($clase, '\\');
        while ($actual) {
            if (preg_match('/^FacturaScripts\\\\Plugins\\\\([^\\\\]+)\\\\/', $actual, $m)) {
                return $m[1];
            }
            if (str_starts_with($actual, 'FacturaScripts\\Core\\')) {
                return 'Core';
            }
            $actual = class_exists($actual) ? get_parent_class($actual) : false;
        }

        return '';
    }
}
