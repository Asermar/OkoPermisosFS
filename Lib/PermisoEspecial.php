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

use FacturaScripts\Core\KernelException;
use FacturaScripts\Core\Model\Page as PageDelCore;
use FacturaScripts\Core\Session;
use FacturaScripts\Core\Template\Controller;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\Page;
use FacturaScripts\Dinamic\Model\User;
use FacturaScripts\Plugins\OkoPermisos\Model\Join\PermisoEfectivo;

/**
 * **Un permiso especial ES una página de FacturaScripts.**
 *
 * FacturaScripts ya sabe concederla —en la ficha del rol—, comprobarla —`User::can()`— y ocultarla
 * —`showonmenu`—, así que un privilegio sobre una operación concreta («desbloquear un servicio cerrado»)
 * se declara como un controlador que hereda de esta clase, y ya está:
 *
 * - **Tener el permiso es tener `update` en esa página.** Con sólo el acceso se ve la hoja, en lectura.
 * - **La página no sale en el menú de nadie** (`showonmenu = false`), y va bajo el menú `MENU`, que es
 *   lo que la distingue de las demás. La pestaña «Permisos especiales» de `AdminPermisos` las lista.
 * - **La página es la HOJA del permiso**: qué es, de qué plugin viene, quién lo tiene y por qué rol, y
 *   cómo se concede. **Se abre con acceso a `AdminPermisos`**, no con el permiso: ver `run()`.
 * - El punto a controlar hace **una llamada**: `if (!PermisoEspecial::exigir(MiPermiso::class)) return false;`
 *   `puede()` pregunta sin avisar, `motivo()` da el texto de por qué no, y `existe()` dice si están
 *   instalados los permisos de los que depende un plugin. Las cuatro aceptan la clase de `Dinamic`, la del
 *   plugin o el nombre corto. En un `Init` antes del primer despliegue se usa `existe()`: las otras
 *   niegan sin sesión, y con un `$user` explícito necesitan `Dinamic` (`User::can()` del core la usa).
 * - **La clase del permiso se nombra por el namespace de su plugin**, y con varios, con un `use` por plugin
 *   y alias: `use FacturaScripts\Plugins\MiPlugin\Controller as MiPlugin;` y `MiPlugin\MiPermiso::class`.
 *   `::class` no carga la clase, y una errata la marca el análisis estático. Ejemplos de cada variante, en
 *   el README.
 *
 * Por qué un controlador y no una fila suelta en `pages`: el deploy borra las páginas sin controlador
 * (`PluginsDeploy::removeOldPages`). Y por qué está en `Lib/` y no en `Controller/`: el deploy instancia
 * cada clase de `Controller/` para leer su página, y una abstracta reventaría ahí.
 *
 * El plugin dueño sólo pone el título —una clave de traducción, que va a `pages.title`— y, si quiere,
 * una descripción y unos detalles. El nombre de la clase es el de la página: cabe en 40 caracteres.
 */
abstract class PermisoEspecial extends Controller
{
    /** `pages.submenu` es `varchar(20)`, como `pages.menu`: una familia más larga se descarta. */
    public const FAMILIA_MAX = 20;

    /** `pages.menu` es `varchar(20)`: un código más largo hace fallar el alta de la página. */
    public const MENU = 'permisos-especiales';

    /** La clave de traducción del título del permiso. Va a `pages.title` (50 caracteres). */
    abstract protected function titulo(): string;

    /** La hoja decide su acceso en `run()`: es la de `AdminPermisos`, no la de esta página. */
    protected $requiresAuth = false;

    /** Qué permite el permiso, en una frase. Opcional. */
    public function descripcion(): string
    {
        return '';
    }

    /**
     * La clave de traducción de la familia del permiso, o cadena vacía si no tiene. Va a `pages.submenu`,
     * así que la ficha del rol (`EditRole`) ordena y prefija por ella, y el índice agrupa por ella.
     *
     * **Como mucho `FAMILIA_MAX` caracteres.** Una más larga no se recorta —una clave recortada ya no
     * traduce, y dos largas con el mismo principio caerían en el mismo grupo—: el permiso se da de alta
     * sin familia y se avisa (`familiaValida()`).
     */
    protected function familia(): string
    {
        return '';
    }

    /** La familia traducida, o cadena vacía si no tiene o no es válida. Es la que pinta la hoja. */
    public function familiaTraducida(): string
    {
        $familia = $this->familiaValida();
        return null === $familia ? '' : Tools::trans($familia);
    }

    /**
     * El hueco del plugin dueño en la hoja: filas «etiqueta → valor» que se pintan escapadas. Para algo
     * más elaborado, el dueño puede fijar su propia plantilla que extienda `OkoPermisosHoja` y rellene el
     * bloque `okopermisos_hoja_extra`.
     *
     * @return array<int, array{etiqueta: string, valor: string}>
     */
    public function detalles(): array
    {
        return [];
    }

    /** Como `puede()`, pero si niega lo avisa con el motivo. Es la llamada del punto a controlar. */
    public static function exigir(string $clase, ?User $user = null): bool
    {
        $motivo = self::motivo($clase, $user);
        if ('' === $motivo) {
            return true;
        }

        Tools::log()->warning($motivo);
        return false;
    }

    /**
     * **Si están instalados TODOS esos permisos**: su página existe y va bajo el menú `MENU`. Es para el
     * plugin que depende de permisos de otro, que así puede comprobarlo y avisar de lo que falta.
     *
     * Se le pasa lo mismo que a `exigir()`. Sin argumentos devuelve `true`: no falta ninguno.
     *
     * Sale de la tabla `pages`, que escribe el despliegue, y no de un registro hecho desde los `Init`: el
     * core los ejecuta por orden de activación, así que el de un plugin reactivado correría después del de
     * quien depende de él, y un registro en memoria diría que no está.
     */
    public static function existe(string ...$clases): bool
    {
        foreach ($clases as $clase) {
            if (null === self::instalada(self::pagina($clase))) {
                return false;
            }
        }

        return true;
    }

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = self::MENU;
        $data['submenu'] = $this->familiaValida();
        $data['title'] = $this->titulo();
        $data['icon'] = 'fa-solid fa-star';
        $data['showonmenu'] = false;
        return $data;
    }

    /**
     * Los permisos especiales instalados: las páginas del menú `MENU`.
     *
     * @return array<int, array{pagina: string, titulo: string, plugin: string, familia: string, familia_titulo: string}>
     */
    public static function instalados(): array
    {
        $permisos = [];
        foreach (Page::all([Where::eq('menu', self::MENU)], ['name' => 'ASC'], 0, 0) as $page) {
            $familia = (string)$page->submenu;
            $permisos[] = [
                'pagina' => $page->name,
                'titulo' => Tools::trans((string)$page->title),
                'plugin' => self::pluginDe('FacturaScripts\\Dinamic\\Controller\\' . $page->name),
                'familia' => $familia,
                'familia_titulo' => '' === $familia ? '' : Tools::trans($familia),
            ];
        }

        // en el orden en que se lee: por familia traducida, las que no tienen al final, y por título
        usort($permisos, static function (array $a, array $b): int {
            $sinFamilia = ('' === $a['familia_titulo']) <=> ('' === $b['familia_titulo']);
            return $sinFamilia ?: strcasecmp($a['familia_titulo'], $b['familia_titulo'])
                ?: strcasecmp($a['titulo'], $b['titulo']);
        });
        return $permisos;
    }

    /**
     * Por qué NO puede, o cadena vacía si puede.
     *
     * Una página que no está instalada se niega antes que nada, también al admin —`User::can()` ya lo
     * hace así—: una errata en la clase no debe dejar pasar a nadie.
     */
    public static function motivo(string $clase, ?User $user = null): string
    {
        $pagina = self::pagina($clase);
        $page = self::instalada($pagina);
        if (null === $page) {
            return Tools::trans('okopermisos-especial-no-instalado', ['%pagina%' => $pagina]);
        }

        $titulo = Tools::trans((string)$page->title);
        $user = $user ?? Session::user();
        if (empty($user->nick)) {
            return self::sinSesion($titulo);
        }

        if ($user->can($pagina, 'update')) {
            return '';
        }

        return Tools::trans('okopermisos-especial-denegado', ['%permiso%' => $titulo, '%pagina%' => $pagina]);
    }

    /** El nombre de página de una clase de permiso: el corto, sea la de `Dinamic` o la del plugin. */
    public static function pagina(string $clase): string
    {
        $partes = explode('\\', ltrim($clase, '\\'));
        return (string)end($partes);
    }

    /** El plugin que declara este permiso. */
    public function plugin(): string
    {
        return self::pluginDe(static::class);
    }

    /**
     * El plugin del que viene una clase, o `Core`. Cadena vacía si no se sabe.
     *
     * Sale del namespace de la clase o de sus padres, no de la ruta del fichero: la que se instancia es
     * la de `Dinamic`, que extiende a la del plugin. Se toma el primer ancestro que sea de un plugin o del
     * core, que es el fichero que `Dinamic` copió.
     */
    public static function pluginDe(string $clase): string
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

    /** Como `exigir()`, sin avisar: para decidir qué se pinta. */
    public static function puede(string $clase, ?User $user = null): bool
    {
        return '' === self::motivo($clase, $user);
    }

    /**
     * **La hoja se abre con acceso a `AdminPermisos`, igual que el resto de lo que explica los permisos.**
     *
     * Esta página ES la que da el permiso, así que el acceso propio no puede decidir quién ve la hoja: un
     * titular sin más accesos la abriría por URL. Por eso `$requiresAuth = false` —el `run()` del core
     * autentica igual, pero no exige acceso a ESTA página— y aquí se exige el de `AdminPermisos`, con la
     * misma excepción con la que el core deniega una página no concedida. El admin pasa (`User::can`).
     */
    public function run(): void
    {
        parent::run();
        $this->exigirAccesoAlPanel();
        $this->view('OkoPermisosHoja.html.twig');
    }

    /** La puerta de la hoja, aparte de `run()` para poder probarla sin pintar la página. */
    protected function exigirAccesoAlPanel(): void
    {
        if (false === $this->user instanceof User) {
            throw new KernelException('AuthenticationRequired', 'authentication-required');
        }
        if (false === $this->user->can('AdminPermisos')) {
            throw new KernelException('AccessDenied', 'access-denied');
        }
    }

    /**
     * Quién tiene el permiso, y por qué rol. Quien llega a la hoja ya tiene acceso a `AdminPermisos`, que
     * enseña lo mismo en «Permisos efectivos», así que aquí no se oculta nada.
     *
     * @return array<int, array{nick: string, roles: string, admin: bool}>
     */
    public function titulares(): array
    {
        return self::titularesDe($this->getClassName());
    }

    /**
     * Cómo se concede, con los MISMOS textos que la pantalla del grupo (`EditRole`): la pestaña `rules`,
     * las columnas `show` y `allow-update`, y el menú y el título de la página traducidos como allí. Así
     * el mensaje no se desfasa si el core cambia una traducción.
     *
     * Con familia la nombra, porque la ficha del rol pinta la fila como « » familia » página ».
     */
    public function comoSeConcede(): string
    {
        $familia = $this->familiaTraducida();
        $clave = '' === $familia ? 'okopermisos-especial-como-se-concede' : 'okopermisos-especial-como-se-concede-familia';
        return Tools::trans($clave, [
            '%grupos%' => Tools::trans('roles'),
            '%reglas%' => Tools::trans('rules'),
            '%menu%' => Tools::trans(self::MENU),
            '%familia%' => $familia,
            '%pagina%' => Tools::trans($this->titulo()),
            '%mostrar%' => Tools::trans('show'),
            '%modificar%' => Tools::trans('allow-update'),
        ]);
    }

    /**
     * Quién tiene `update` en una página por sus roles, más los administradores.
     *
     * **Reutiliza `Join\PermisoEfectivo`**, que ya agrega el valor más permisivo de todos los roles.
     *
     * @return array<int, array{nick: string, roles: string, admin: bool}>
     */
    public static function titularesDe(string $pagina): array
    {
        $titulares = [];
        $where = [Where::eq('e.pagename', $pagina), Where::eq('e.puede_escribir', 1)];
        foreach ((new PermisoEfectivo())->all($where, [], 0, 0) as $fila) {
            $titulares[(string)$fila->nick] = ['nick' => (string)$fila->nick, 'roles' => (string)$fila->roles_por_rol, 'admin' => false];
        }

        foreach (User::all([Where::eq('admin', true)], ['nick' => 'ASC'], 0, 0) as $admin) {
            $titulares[$admin->nick] = [
                'nick' => $admin->nick,
                'roles' => $titulares[$admin->nick]['roles'] ?? '',
                'admin' => true,
            ];
        }

        ksort($titulares, SORT_STRING | SORT_FLAG_CASE);
        return array_values($titulares);
    }

    /**
     * La familia tal como va a `pages.submenu`, o `null` si no tiene. **Una de más de `FAMILIA_MAX`
     * caracteres se descarta y se avisa**: el alta de la página no puede fallar por ella, y recortarla daría
     * una clave que no traduce. Avisa en cada llamada, también al abrir la hoja, que es donde lo ve quien
     * administra los permisos.
     *
     * Lleva el mismo `noHtml` que `Page::test()` aplica al submenu, para medir lo que de verdad se guarda.
     */
    private function familiaValida(): ?string
    {
        $familia = (string)Tools::noHtml(trim($this->familia()));
        if ('' === $familia) {
            return null;
        }

        if (mb_strlen($familia) > self::FAMILIA_MAX) {
            Tools::log()->warning('okopermisos-especial-familia-larga', [
                '%familia%' => $familia,
                '%pagina%' => $this->getClassName(),
                '%maximo%' => self::FAMILIA_MAX,
            ]);
            return null;
        }

        return $familia;
    }

    /**
     * La página del permiso si está instalada, o `null`. Es lo que significa «instalado» para `existe()` y
     * para `motivo()`: una página cualquiera que no vaya bajo `MENU` no es un permiso.
     *
     * **Lee con el `Page` del core, no con el de `Dinamic`**, para que `existe()` valga desde el `Init` de otro
     * plugin en una instalación sin desplegar —y `motivo()` conteste ahí sin usuario—: el core ejecuta los
     * `Init` antes que el controlador, y sin `Dinamic` la clase de `Dinamic` no existe. Aquí sólo se leen
     * `name`, `title` y `menu`, que no dependen de ninguna extensión.
     */
    private static function instalada(string $pagina): ?PageDelCore
    {
        $page = new PageDelCore();
        if ('' === $pagina || false === $page->load($pagina) || self::MENU !== $page->menu) {
            return null;
        }

        return $page;
    }

    /**
     * **Sin usuario en sesión —cron, workers, API— se NIEGA**, y lo dice.
     *
     * Es el lado seguro, y el consumidor previsto comprueba la TRANSICIÓN y no el estado, así que un
     * recálculo masivo no pasa por aquí. Si se decide lo contrario, el cambio es esta función —devolver
     * `''`— y su test.
     */
    private static function sinSesion(string $titulo): string
    {
        return Tools::trans('okopermisos-especial-sin-sesion', ['%permiso%' => $titulo]);
    }
}
