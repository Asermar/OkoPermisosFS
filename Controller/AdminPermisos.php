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

namespace FacturaScripts\Plugins\OkoPermisos\Controller;

use FacturaScripts\Core\DbQuery;
use FacturaScripts\Core\Lib\ExtendedController\PanelController;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\Role;
use FacturaScripts\Dinamic\Model\User;
use FacturaScripts\Plugins\OkoPermisos\Lib\MenuDelUsuario;
use FacturaScripts\Plugins\OkoPermisos\Lib\PermisoEspecial;

/**
 * **Explica los permisos. No los cambia.**
 *
 * Decidido así a propósito (ago-2026): la poda se hace con el panel de roles del core, que funciona, y
 * una pantalla que escriba permisos puede dejar a su dueño fuera de su propia instalación. Si algún
 * día actúa, será sobre esto ya rodado.
 *
 * Tres preguntas, y ninguna la responde el core hoy:
 *
 * 1. **Qué puede hacer un usuario de verdad**, y por qué rol le llega cada cosa.
 * 2. **Quién pierde un acceso si lo quito de un rol** — y quién no, porque lo tiene por otro.
 * 3. **Qué pasa si a alguien le quito el flag `admin`**, que es el único permiso que no viene de roles.
 *
 * ## Un solo panel, una entrada lateral por listado
 *
 * Es un `PanelController` hecho sólo de listas y plantillas, el mismo patrón que `AdminCRM`, y de ahí
 * el nombre: en el framework `List…` es un `ListController` y `Admin…` un panel de administración.
 * La vista del menú era un controlador suelto y entra como `addHtmlView`; los usuarios y roles son
 * los modelos del core, y su edición sigue en `EditUser` y
 * `EditRole` porque la fila lleva a `$model->url()`.
 *
 * ## Por qué no hay una rejilla páginas × roles
 *
 * Con cientos de páginas y decenas de roles serían miles de celdas, casi todas vacías: impresiona y no
 * se usa. Las dos listas
 * filtrables responden lo mismo y se pueden buscar, ordenar y exportar, que es lo que hace falta para
 * podar.
 */
class AdminPermisos extends PanelController
{
    /** @var array<string, mixed> el árbol de la vista del menú */
    public array $arbol = [];

    /** si el usuario elegido en la vista del menú es admin: invalida el árbol, y hay que decirlo */
    public bool $esAdmin = false;

    /** @var array<int, array<string, mixed>> los permisos especiales, con su estado para el usuario elegido */
    public array $especiales = [];

    public string $nickElegido = '';

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'admin';
        $data['title'] = 'okopermisos-permisos';
        $data['icon'] = 'fa-solid fa-user-shield';
        return $data;
    }

    /** @return array<int, array{pagina: string, titulo: string, plugin: string}> */
    public function permisosEspeciales(): array
    {
        return PermisoEspecial::instalados();
    }

    /**
     * Para la vista del menú: todos los usuarios, no sólo los que tienen rol. Uno sin ninguno también
     * es una respuesta —«no ve nada»— y es justo la que se quiere comprobar tras una poda.
     *
     * @return array<int, array{code: string, description: string}>
     */
    public function usuarios(): array
    {
        $valores = [];
        foreach (DbQuery::table('users')->select('nick, admin')->orderMulti(['nick' => 'ASC'])->get() as $fila) {
            $nick = (string)$fila['nick'];
            $valores[] = [
                'code' => $nick,
                // el «(admin)» va en la propia lista: es lo que invalida el árbol de abajo
                'description' => $fila['admin'] ? $nick . ' (admin)' : $nick,
            ];
        }

        return $valores;
    }

    /** El orden de las entradas laterales es el de estas llamadas. */
    protected function createViews(): void
    {
        $this->createViewsEfectivos();
        $this->createViewsImpacto();
        $this->createViewsAdmins();
        $this->createViewsEspeciales();
        $this->createViewsMenu();
        $this->createViewsUsuarios();
        $this->createViewsRoles();
    }

    /**
     * Los admin y qué les quedaría sin el flag: el único permiso que no viene de roles.
     *
     * `ListUser-admins` y no `ListUser`: ese nombre es el de la pestaña de todos los usuarios, y el core
     * busca el XMLView por lo que va antes del guion (`BaseView`), así que las dos usan el `ListUser` del
     * core.
     */
    protected function createViewsAdmins(string $viewName = 'ListUser-admins'): void
    {
        $this->addListView($viewName, 'User', 'okopermisos-admins', 'fa-solid fa-user-gear')
            ->addOrderBy(['nick'], 'nick', 1)
            ->addSearchFields(['nick', 'email'])
            ->setSettings('btnNew', false)
            ->setSettings('btnDelete', false);
    }

    /**
     * El índice de los permisos especiales instalados, con un enlace a la hoja de cada uno.
     *
     * Es una pestaña más, sin condición de administrador: quién entra en este panel lo decide el admin
     * con los roles, como en cualquier página. La plantilla lleva el prefijo del plugin porque los nombres
     * de plantilla son un espacio global (`Plugins::deploy()` las junta todas en `Dinamic/View/`).
     */
    protected function createViewsEspeciales(string $viewName = 'PermisosEspeciales'): void
    {
        $this->addHtmlView($viewName, 'OkoPermisosIndice', 'Page', 'okopermisos-especiales', 'fa-solid fa-star')
            ->setSettings('btnNew', false)
            ->setSettings('btnDelete', false);
    }

    /** El menú de FacturaScripts tal como lo vería un usuario, con lo que no ve al lado. */
    protected function createViewsMenu(string $viewName = 'VistaDelMenu'): void
    {
        $this->addHtmlView($viewName, 'OkoPermisosVistaDelMenu', 'User', 'okopermisos-vista-menu', 'fa-solid fa-sitemap')
            ->setSettings('btnNew', false)
            ->setSettings('btnDelete', false);
    }

    /**
     * Los roles del core, con su búsqueda y sus órdenes, copiados de `ListUser::createViewsRoles`. Sin
     * nuevo ni borrar: la edición se queda en el core, y la fila ya lleva allí.
     */
    protected function createViewsRoles(string $viewName = 'ListRole'): void
    {
        $this->addListView($viewName, 'Role', 'roles', 'fa-solid fa-address-card')
            ->addSearchFields(['codrole', 'descripcion'])
            ->addOrderBy(['descripcion'], 'description', 1)
            ->addOrderBy(['codrole'], 'code')
            ->setSettings('btnNew', false)
            ->setSettings('btnDelete', false);
    }

    /**
     * Los usuarios del core. Búsqueda, órdenes y el filtro admin / no admin, como en
     * `ListUser::createViewsUsers`; los filtros de empresa, almacén, serie y agente se dejan fuera,
     * porque aquí se audita quién puede qué, no dónde trabaja.
     */
    protected function createViewsUsuarios(string $viewName = 'ListUser'): void
    {
        $this->addListView($viewName, 'User', 'users', 'fa-solid fa-users')
            ->addSearchFields(['nick', 'email'])
            ->addOrderBy(['nick'], 'nick', 1)
            ->addOrderBy(['email'], 'email')
            ->addOrderBy(['creationdate'], 'creation-date')
            ->addOrderBy(['lastactivity'], 'last-activity')
            ->addFilterSelectWhere('type', [
                ['label' => Tools::trans('all'), 'where' => []],
                ['label' => Tools::trans('admin'), 'where' => [Where::eq('admin', true)]],
                ['label' => Tools::trans('no-admin'), 'where' => [Where::eq('admin', false)]],
            ])
            ->setSettings('btnNew', false)
            ->setSettings('btnDelete', false);
    }

    protected function createViewsEfectivos(string $viewName = 'ListPermisoEfectivo'): void
    {
        $this->addListView($viewName, 'Join\PermisoEfectivo', 'okopermisos-efectivos', 'fa-solid fa-user-check')
            // El orden por omisión: por menú y, dentro, lo que trata de lo mismo junto y en orden
            // ALFABÉTICO. Se ordena por la ENTIDAD —el nombre sin el prefijo de acción, con la lista
            // configurable de `Lib/NombreDePagina`— y dentro de ella por el prefijo, también alfabético:
            // Edit antes que List, como en la columna «Página». La página sin prefijo va la primera de su
            // entidad. Sin esto, `ListCliente` y `EditCliente` caen lejos siendo lo mismo.
            //
            // No entran el `ordernum` ni el título: casi todas las páginas comparten `ordernum`, y entonces
            // mandaba `pages.title`, que es la clave de traducción SIN traducir —`city`, `countries`—, no lo
            // que se lee. El orden de la barra de FacturaScripts queda como alternativo, a un clic.
            //
            // `LOWER()` no es adorno: sin él las mayúsculas se agrupan aparte y `GestionAlmacen` cae lejos
            // de `gestionalmacen`, que es justo lo que despista al leer.
            ->addOrderBy(['LOWER(p.menu)', 'LOWER(p.submenu)', 'LOWER(entidad)', 'LOWER(prefijo)'],
                'okopermisos-menu-pagina', 1)
            ->addOrderBy(['LOWER(p.menu)', 'LOWER(p.submenu)', 'p.ordernum', 'LOWER(p.title)'],
                'okopermisos-orden-del-menu')
            ->addOrderBy(['cuantos_roles', 'nick'], 'okopermisos-cuantos-roles')
            ->addOrderBy(['nick', 'LOWER(pagina_nombre)'], 'okopermisos-usuario')
            // por el nombre girado, que es el que se lee: «EmailConfig» junto a «EmailSentEdit»
            ->addOrderBy(['LOWER(pagina_nombre)', 'nick'], 'okopermisos-pagina')
            // Los campos buscables son además los que BuscadorAcumulado ofrece en su selector: ese
            // plugin cruza `searchFields` con las columnas de la vista, así que declarar sólo el usuario
            // y la página dejaba fuera lo demás. Con el menú dentro se pueden acumular búsquedas y ver
            // DOS menús a la vez, que es justo lo que no hace el filtro de un solo valor.
            //
            // Los flags no entran: son booleanos y ya tienen su filtro sí/no. Buscarlos como texto
            // daría «1» y «0», que no se parece a nada que alguien vaya a teclear.
            ->addSearchFields(['e.nick', 'e.pagename', 'p.menu', 'p.submenu', 'p.title', 'e.roles'])
            ->addFilterSelect('nick', 'okopermisos-usuario', 'e.nick', $this->usuarios())
            ->addFilterSelect('menu', 'okopermisos-menu', 'p.menu', $this->menusQueExisten())
            // Por grupo: aquí los roles vienen agregados en un GROUP_CONCAT, así que no vale un
            // `addFilterSelect` sobre una columna. Se busca el código CERCADO POR COMAS —«,ventas,»—
            // porque `Where::like` envuelve en `%…%` y sin las comas «ventas» casaría también con un rol
            // «ventasconsulta», que es otro rol y puede conceder otras cosas.
            ->addFilterSelectWhere('grupo', $this->opcionesDeGrupo())
            // La lista tiene una fila por usuario × página, con acceso o sin él, así que este filtro es el
            // que decide qué se ve. ARRANCA en «sí» —la opción 0 es la que aplica `SelectWhereFilter` sin
            // valor—, que es la lista de siempre; lo que falta se pide a propósito con «no» o «todos».
            ->addFilterSelectWhere('tiene_acceso', [
                ['label' => Tools::trans('okopermisos-tiene-acceso') . ': ' . Tools::trans('yes'),
                    'where' => [Where::eq('e.tiene_acceso', 1)]],
                ['label' => Tools::trans('okopermisos-tiene-acceso') . ': ' . Tools::trans('no'),
                    'where' => [Where::eq('e.tiene_acceso', 0)]],
                ['label' => Tools::trans('okopermisos-tiene-acceso') . ': ' . Tools::trans('all'), 'where' => []],
            ])
            // el filtro que separa lo que se ve del menú de lo que sólo se alcanza pinchando
            ->addFilterSelectWhere('en_el_menu', [
                ['label' => Tools::trans('all'), 'where' => []],
                ['label' => Tools::trans('okopermisos-solo-menu'), 'where' => [Where::eq('p.showonmenu', 1)]],
                ['label' => Tools::trans('okopermisos-solo-fichas'), 'where' => [Where::eq('p.showonmenu', 0)]],
            ])
            ->setSettings('btnNew', false)
            ->setSettings('btnDelete', false)
            // **Sin clic.** Una fila de esta vista no es un registro: es el cruce de un usuario con una
            // página, agregado sobre varios roles, y no hay ficha que abrir. `JoinModel::url()` devuelve
            // cadena vacía cuando no hay modelo maestro, y con el destino vacío el clic **saca al usuario
            // de la pantalla y lo lleva a su página de inicio**: no recarga, se pierde el trabajo y
            // encima parece que la aplicación ha hecho algo raro. Esa página es `users.homepage` y, si
            // está vacía —lo normal—, el ajuste global `default.homepage`, que el core pone por omisión a
            // `AdminPlugins`: el clic puede acabar en la pantalla de plugins. Sin `clickable` la
            // plantilla no pone la clase `clickableListRow`
            // —de donde `ListView.js` engancha el `mousedown`— y además retira el botón de «abrir en
            // pestaña» y la columna del enlace externo, que sin destino sobran igual.
            ->setSettings('clickable', false);

        $this->filtrosSiNo($viewName);

        // Un botón que salta a la vista del menú **con el usuario que esté filtrado**. Es una acción y no
        // un enlace fijo porque el destino depende del filtro, y el filtro sólo se conoce al servir la
        // petición: un `href` compuesto en `createViews` llevaría siempre el valor de la primera carga.
        $this->addButton($viewName, [
            'action' => 'ver-el-menu',
            'color' => 'info',
            'icon' => 'fa-solid fa-sitemap',
            'label' => 'okopermisos-vista-menu',
            'type' => 'action',
        ]);

        // El menú, con su nombre traducido: en la base está el código y en la barra de FacturaScripts
        // se lee «Administrador», no «admin». `setValuesFromArrayKeys` con `translate = true` es la vía
        // del core para esto, así que no hay que traducir a mano en la vista.
        // `columnForField`, no `getColumn`: ese método no existe en `ListView` y el primer intento
        // reventaba la pantalla con un fatal —lo cazó una comprobación, no la lectura del código—.
        $columna = $this->views[$viewName]->columnForField('menu');
        if ($columna && $columna->widget) {
            $claves = [];
            foreach ($this->menusQueExisten() as $menu) {
                $claves[$menu['code']] = $menu['code'];
            }
            $columna->widget->setValuesFromArrayKeys($claves, true);
        }
    }

    /** Qué se pierde de verdad al quitar un acceso de un rol. */
    protected function createViewsImpacto(string $viewName = 'ListImpactoDeQuitar'): void
    {
        $this->addListView($viewName, 'Join\ImpactoDeQuitar', 'okopermisos-impacto', 'fa-solid fa-scissors')
            // los que más gente pierde, primero: son las decisiones que hay que pensar
            ->addOrderBy(['lo_perderian', 'codrole'], 'okopermisos-lo-perderian', 2)
            ->addOrderBy(['codrole', 'LOWER(pagina_nombre)'], 'okopermisos-rol')
            ->addOrderBy(['LOWER(pagina_nombre)'], 'okopermisos-pagina')
            ->addSearchFields(['ra.codrole', 'ra.pagename'])
            ->addFilterSelect('codrole', 'okopermisos-rol', 'ra.codrole', $this->rolesConAccesos())
            ->setSettings('btnNew', false)
            ->setSettings('btnDelete', false)
            // igual que la otra: el cruce de un rol con una página no es un registro que se abra
            ->setSettings('clickable', false);
    }

    /**
     * **El panel no borra nada**, sea cual sea la pestaña. Ver `noEscribe()`.
     */
    protected function deleteAction()
    {
        return $this->noEscribe();
    }

    /** **El panel no modifica nada**, sea cual sea la pestaña. Ver `noEscribe()`. */
    protected function editAction()
    {
        return $this->noEscribe();
    }

    /** **El panel no crea nada**, sea cual sea la pestaña. Ver `noEscribe()`. */
    protected function insertAction()
    {
        return $this->noEscribe();
    }

    /**
     * Las tres acciones de escritura del panel, cerradas aquí y no en cada pestaña.
     *
     * Apagar `btnDelete` y `btnNew` en la vista no basta: sólo quita el botón. En `PanelController` del
     * core, `deleteAction` mira el `btnDelete` de la pestaña activa —que en una `HtmlView` vale `true` por
     * omisión, y así se podían borrar usuarios desde la vista del menú y páginas desde los permisos
     * especiales—, y `editAction` e `insertAction` no miran ningún ajuste: sólo «modificar» y el token.
     * Hoy no escriben porque ninguna pestaña lee el formulario, y eso dejaría de ser cierto con el primer
     * `modalInsert`. La pestaña la elige la petición (`activetab`), no la interfaz.
     */
    private function noEscribe(): bool
    {
        Tools::log()->warning('okopermisos-no-escribe');
        return false;
    }

    /**
     * El botón «Vista del menú» salta a esa pestaña llevándose el usuario filtrado.
     *
     * Si no hay filtro puesto va sin usuario y allí se elige, que es mejor que no dejar saltar: la vista
     * sirve igual empezando desde cero.
     */
    protected function execPreviousAction($action)
    {
        if ('ver-el-menu' === $action) {
            // El usuario se lee de la PETICIÓN, no del filtro, y no es una preferencia: aquí el filtro
            // todavía está vacío. `PanelController::privateCore` llama a `execPreviousAction` **antes** del
            // `processFormData` que puebla los filtros, así que preguntarle su valor devolvería vacío
            // siempre y el botón nunca llevaría a nadie. (Y `->value` es propiedad protegida de
            // `BaseFilter`: el primer intento reventó la pantalla con `Cannot access protected property`.)
            //
            // `filternick` es el nombre con el que el filtro `nick` viaja —`BaseFilter::name()` prefija
            // `filter`— y el botón de acción reenvía el mismo formulario, así que llega con él.
            $nick = (string)$this->request->inputOrQuery('filternick', '');

            $this->redirect('AdminPermisos?activetab=VistaDelMenu' . ('' === $nick ? '' : '&nick=' . rawurlencode($nick)));
            return false;
        }

        return parent::execPreviousAction($action);
    }

    /**
     * Un filtro de tres estados —todos / sí / no— por cada permiso.
     *
     * Se puede filtrar por ellos **porque la agregación vive en una subconsulta derivada**: son
     * columnas normales del `FROM`, no agregados. Con el `GROUP BY` en la consulta principal esto no
     * era posible —`JoinModel` no genera `HAVING`— y el «no» habría salido mal: filtrar antes de
     * agrupar da los usuarios con *algún* rol sin el permiso, aunque otro se lo conceda.
     */
    protected function filtrosSiNo(string $viewName): void
    {
        $flags = [
            'puede_escribir' => 'okopermisos-puede-escribir',
            'puede_borrar' => 'okopermisos-puede-borrar',
            'puede_exportar' => 'okopermisos-puede-exportar',
            'puede_importar' => 'okopermisos-puede-importar',
            'solo_lo_suyo' => 'okopermisos-solo-lo-suyo',
        ];
        foreach ($flags as $campo => $etiqueta) {
            $this->views[$viewName]->addFilterSelectWhere($campo, [
                ['label' => Tools::trans($etiqueta), 'where' => []],
                ['label' => Tools::trans($etiqueta) . ': ' . Tools::trans('yes'),
                    'where' => [Where::eq('e.' . $campo, 1)]],
                ['label' => Tools::trans($etiqueta) . ': ' . Tools::trans('no'),
                    'where' => [Where::eq('e.' . $campo, 0)]],
            ]);
        }
    }

    /**
     * La pestaña de administradores lista **sólo** a los que tienen el flag `admin`.
     *
     * Sin esto cae en la vista del core, que lista la tabla `users` entera. La acotación se pasa como
     * `$where` a `ListView::loadData()`, que la **suma** a los filtros y la búsqueda del usuario
     * (`array_merge`): se puede seguir buscando dentro.
     */
    protected function loadData($viewName, $view)
    {
        // Sin esto el panel sólo enseña la primera entrada: `PanelController::privateCore` desactiva el
        // resto si la vista principal no «tiene datos», y lo mide con `$model->exists()`, que en una
        // lista es siempre falso. Aquí no hay registro principal; es el mismo caso que `AdminCRM`.
        $this->hasData = true;

        switch ($viewName) {
            case 'ListUser-admins':
                // Sólo los que tienen el flag. Sin esto la vista lista la tabla `users` entera. El `$where`
                // se SUMA a los filtros y la búsqueda del usuario (`ListView::loadData` hace
                // `array_merge`), así que se puede seguir buscando dentro.
                $view->loadData('', [Where::eq('admin', true)]);
                break;

            case 'VistaDelMenu':
                $this->cargarVistaDelMenu();
                break;

            default:
                $view->loadData();
                break;
        }
    }

    /** Lo que puede hacer cada usuario, y por qué rol. */
    /**
     * Los menús que de verdad existen, para el filtro.
     *
     * Se leen de `pages` en vez de escribirlos a mano: un plugin puede añadir su propio menú, y una lista
     * fija dejaría fuera justo lo que se acaba de instalar.
     */
    protected function menusQueExisten(): array
    {
        $valores = [];
        foreach (DbQuery::table('pages')->select('menu')->groupBy('menu')->orderBy('menu')->get() as $fila) {
            $menu = (string)($fila['menu'] ?? '');
            if ('' === $menu) {
                continue;
            }
            $valores[] = ['code' => $menu, 'description' => Tools::trans($menu)];
        }

        return $valores;
    }

    /**
     * Las opciones del filtro por grupo, una por rol con accesos.
     *
     * Va como `addFilterSelectWhere` y no como `addFilterSelect` porque el campo es un `GROUP_CONCAT`:
     * no se compara, se busca dentro. Cada opción lleva su propio `Where` con el código cercado.
     */
    protected function opcionesDeGrupo(): array
    {
        $opciones = [['label' => Tools::trans('okopermisos-rol'), 'where' => []]];
        foreach ($this->rolesConAccesos() as $rol) {
            $opciones[] = [
                'label' => $rol['description'],
                'where' => [Where::like('e.roles_cercado', '%,' . $rol['code'] . ',%')],
            ];
        }

        return $opciones;
    }

    /**
     * Los roles que **tienen algún acceso**, para el filtro: un rol sin accesos no aparece en ninguna
     * fila, así que ofrecerlo sería ofrecer una lista vacía.
     *
     * **La descripción va delante y el código detrás**, y la lista se ordena por ella: es lo que uno
     * recuerda al buscar, y ordenar por código deja lejos lo parecido —`gestionAlmacen` y
     * `GestionCompras` se separan por una mayúscula—. Se muestran los dos porque ninguno basta solo: el
     * código es lo que hay en `roles_access`, y la descripción puede engañar —un rol llamado «Consulta»
     * puede conceder escritura—.
     */
    protected function rolesConAccesos(): array
    {
        $valores = [];
        foreach (DbQuery::table('roles_access')
                     ->select('codrole')->groupBy('codrole')->orderBy('codrole')->get() as $fila) {
            $codrole = (string)($fila['codrole'] ?? '');
            if ('' === $codrole) {
                continue;
            }
            $rol = new Role();
            $descripcion = $rol->loadFromCode($codrole) ? $rol->descripcion : '';
            $valores[] = [
                'code' => $codrole,
                'description' => '' === $descripcion ? $codrole : $descripcion . ' — ' . $codrole,
            ];
        }

        // se ordena por la descripción, que es lo que ahora se lee primero
        usort($valores, static fn (array $a, array $b): int => strcasecmp($a['description'], $b['description']));

        return $valores;
    }

    private function cargarVistaDelMenu(): void
    {
        $this->nickElegido = (string)$this->request->query('nick', '');
        if ('' === $this->nickElegido) {
            return;
        }

        // un nick que no existe no es un usuario elegido: su árbol sería todo denegado, y el nick de la URL
        // llegaría tal cual a los puntos de extensión, que lo reciben como «el usuario elegido»
        $elegido = new User();
        if (false === $elegido->loadFromCode($this->nickElegido)) {
            $this->nickElegido = '';
            return;
        }

        // el flag de administrador se lee aparte: invalida el árbol, así que hay que decirlo
        $this->esAdmin = (bool)$elegido->admin;
        $this->arbol = MenuDelUsuario::arbol($this->nickElegido);
        $this->especiales = MenuDelUsuario::especiales($this->nickElegido);
    }
}
