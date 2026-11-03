<p align="center">
  <a href="#changelog"><img alt="Versión" src="https://img.shields.io/badge/Versi%C3%B3n-1.06-blue?style=for-the-badge"/></a>
  <img src="https://img.shields.io/badge/FacturaScripts-≥%20v2026-1f4e79?style=for-the-badge" alt="FacturaScripts"/>
  <img src="https://img.shields.io/badge/PHP-≥%208.1-777bb4?style=for-the-badge" alt="PHP"/>
  <a href="#licencia"><img src="https://img.shields.io/badge/licencia-LGPL--3.0--or--later-green?style=for-the-badge" alt="licencia"/></a>
</p>

# OkoPermisos

**Explica los permisos de FacturaScripts. No los cambia.**

En FacturaScripts los permisos vienen **sólo** de roles —no hay tabla usuario–página— y la interfaz
muestra los accesos *de un rol* o los roles *de un usuario*, nunca el cruce. Eso deja sin responder la
pregunta que bloquea cualquier limpieza: **si quito este acceso de este rol, ¿alguien lo pierde de
verdad, o lo tiene también por otro?**

## Funcionalidades

Todo vive en **un solo panel**, *Administración → Permisos* (`AdminPermisos`), con una entrada lateral
por listado: permisos efectivos, impacto de quitar, administradores, permisos especiales, vista del
menú, usuarios y roles.

### Permisos efectivos
- Lo que un usuario puede hacer de verdad, con **qué roles** se lo conceden y **cuántos**.
- Una fila por usuario y página, y la columna **«Tiene acceso»** dice si entra. Arranca filtrando las
  que sí —lo concedido—; con «no» o «todos» sale también lo que le falta. «Visible en el menú» dice si
  la página le sale en **su** menú: hace falta que sea de menú y que tenga acceso.
- Un administrador tiene acceso a todo, como en FacturaScripts, y su columna de roles lo marca con
  «(admin)»; los demás permisos de la fila siguen siendo los de sus roles.
- Los flags agregados por su valor **más permisivo**: un rol restrictivo no restringe si otro concede.
- Distingue **entrar** de **escribir**, que en el core se confunden con facilidad.
- Incluye las páginas que no salen en ningún menú —las fichas—, que son la mayoría y las que cortan la
  navegación cuando faltan.

> **Sólo lectura no impide exportar.** `RoleAccess::clear()` pone `allowexport` e `allowimport` a
> `true`, así que un acceso creado sin tocar esos flags deja sacar los datos aunque no deje
> modificarlos, y es fácil que haya accesos así sin que nadie lo decidiera. Por eso las cuatro columnas
> se muestran por separado y no se resumen en «lectura / escritura».

### Nombres de página legibles
- En «Permisos efectivos» e «Impacto de quitar», la página se muestra con el prefijo de acción al final
  y el controlador real entre paréntesis: `ConfigEmail` se lee «EmailConfig (ConfigEmail)» y `ListUser`
  «UserList (ListUser)». Así lo que trata de lo mismo sale junto al ordenar, y el nombre que se concede en
  la ficha del rol sigue a la vista. El buscador casa por el nombre real.
- Los prefijos que se giran son **una lista de la instalación**, en *Administración → Panel de control →
  OkoPermisos*: por defecto `List, Edit, Config, Report, Admin, Send, New, Export, Import`. Añadir uno es
  una opción de configuración, no una versión nueva. Un prefijo sólo gira si detrás viene una mayúscula,
  y un valor que no sea sólo letras se ignora y se avisa.
- El orden por defecto de «Permisos efectivos» usa la misma lista: por menú y, dentro, por entidad —el
  nombre sin el prefijo— y sus prefijos en orden alfabético.

### Vista del menú
- El menú **tal como lo vería un usuario**, con el aspecto del menú real: la barra, los desplegables y
  el icono de cada página. Usa las clases del propio FacturaScripts, así que sigue al tema instalado.
- En el **orden real**, que no es el evidente: el core ordena por el título ya *traducido*, no por la
  clave de la página.
- Se abre desde «Permisos efectivos» llevándose el usuario que esté filtrado.
- Los paneles **quedan fijados** y caben varios a lo ancho, para comparar dos menús de un vistazo. Es
  lo único que se aparta del menú real, donde abrir uno cierra el anterior.
- Lo que **no** tiene sale atenuado al lado: la pregunta que trae aquí es doble, «qué ve» y «qué le
  falta», y un árbol con sólo lo concedido responde la mitad.
- Cada entrada lleva sus etiquetas —lectura, exportar, importar, escritura, borrado, sólo lo suyo— y
  los roles por los que le llega.
- Si el usuario es administrador lo avisa: el árbol no describe lo que ve —lo ve todo— sino lo que le
  quedaría **sin** el flag.

### Impacto de quitar
- Por cada acceso de cada rol: cuántos usuarios lo tienen y **cuántos lo perderían**, con sus nombres.
- Un `0` en «lo perderían» señala un acceso **redundante**: se puede quitar sin que nadie lo note.
- Los administradores no cuentan: para ellos el acceso es total pase lo que pase en los roles.

### Administradores
- Quiénes tienen el flag `admin` y qué les quedaría sin él, que es el único permiso ajeno a los roles.

### Usuarios y roles
- Los listados de usuarios y roles del core, dentro del mismo panel, con su búsqueda y sus órdenes. La
  **edición sigue en el core**: una fila lleva a `EditUser` o `EditRole`, y las páginas del core no se
  tocan ni se retiran de su menú.

### Permisos especiales
- Para lo que un permiso por página no puede expresar —«desbloquear un servicio cerrado»—: **un permiso
  especial es una página de FacturaScripts** que no sale en el menú. Se concede como cualquier otra, en
  la **ficha del rol**: acceso a su página con «modificar». Con el acceso sin «modificar» sólo se ve su
  hoja. El administrador lo tiene siempre.
- Cada plugin declara los suyos con un controlador que hereda de `Lib/PermisoEspecial`; aparecen y
  desaparecen con el plugin. El plugin de origen **se deriva** de la clase, no se guarda.
- La página del permiso es su **hoja**: qué permite, de qué plugin viene, quién lo tiene y por qué rol,
  y cómo se concede, con los mismos textos de la pantalla del grupo. **Se abre con acceso a
  Administración → Permisos**, no con el permiso: tenerlo no deja ver la hoja.
- La entrada **Permisos especiales** del panel los lista con un enlace a cada hoja, agrupados por la
  **familia** que declare cada uno; la ficha del rol del core también los agrupa por ella.
- El punto a controlar hace una sola llamada, y una negativa **dice qué permiso falta** y que lo concede
  un administrador en la ficha del rol. Sin usuario en sesión —cron, API— se niega.
- Como son páginas, **Permisos efectivos** e **Impacto de quitar** los enseñan sin más, y la **Vista del
  menú** los agrupa en un nodo propio, con su estado para el usuario elegido.

- Cómo se registra uno y cómo se usa, con ejemplos: ver «Permisos especiales en un plugin», abajo.

## Permisos especiales en un plugin

### Registrar un permiso

1. El plugin declara la dependencia en su `facturascripts.ini`:

   ```ini
   require = 'OkoPermisos'
   ```

2. Un controlador por permiso, en su `Controller/`, que hereda de la clase **del plugin** OkoPermisos —no
   de la de `Dinamic`, que el despliegue aún no habría copiado—. El nombre de la clase es el de la página:
   cabe en 40 caracteres.

   ```php
   namespace FacturaScripts\Plugins\MiPlugin\Controller;

   use FacturaScripts\Core\Tools;
   use FacturaScripts\Plugins\OkoPermisos\Lib\PermisoEspecial;

   class PermisoReabrirParte extends PermisoEspecial
   {
       // obligatorio: la clave de traducción del título, que va a pages.title (50 caracteres)
       protected function titulo(): string
       {
           return 'permiso-reabrir-parte';
       }

       // opcional: la clave de traducción de su familia, que va a pages.submenu (20 caracteres)
       protected function familia(): string
       {
           return 'partes';
       }

       // opcional: qué permite, en una frase
       public function descripcion(): string
       {
           return Tools::trans('permiso-reabrir-parte-desc');
       }

       // opcional: filas «etiqueta → valor» para la hoja del permiso
       public function detalles(): array
       {
           return [['etiqueta' => Tools::trans('plazo'), 'valor' => '30 días']];
       }
   }
   ```

3. Las claves en el `Translation/es_ES.json` del plugin: `permiso-reabrir-parte` y, si las hay, la de la
   descripción (`permiso-reabrir-parte-desc`) y la de la familia (`partes`).
   - **La familia agrupa los permisos**: en la entrada **Permisos especiales** del panel, por su traducción
     y con los que no tienen familia al final; en la hoja del permiso sale como una fila más; y en la
     ficha del rol (`EditRole`, del core) las filas de una familia salen seguidas y con el prefijo
     « » familia » ». El core ordena allí por la **clave**, no por la traducción, y los que no tienen
     familia van primero, así que el orden de los grupos puede no ser el mismo que en el panel.
   - **Como mucho 20 caracteres** (`PermisoEspecial::FAMILIA_MAX`), que es lo que cabe en `pages.submenu`.
     Una clave más larga **no se recorta**, porque recortada ya no traduce: el permiso se da de alta sin
     familia y sale un aviso, en el despliegue y al abrir su hoja, que dice qué clave hay que acortar.
4. **El despliegue da de alta la página** (Reconstruir, o al activar o actualizar el plugin), fuera de todo
   menú y bajo el de permisos especiales. Desactivar el plugin la retira.
5. Se concede en la **ficha del rol**, pestaña de reglas: acceso a la página **con «modificar»**. Con sólo el
   acceso se ve la hoja del permiso, en lectura, pero no se tiene.

### Nombrar el permiso

Las cuatro formas dan lo mismo al ejecutar: OkoPermisos se queda con lo que va tras la última `\`. La
clase del permiso se nombra por el namespace **de su plugin**, nunca por `Dinamic`. Y `::class` sólo
compone el nombre, **no carga la clase**: nombrar un permiso de otro plugin no crea dependencia al
ejecutar.

```php
// (a) un use por permiso
use FacturaScripts\Plugins\MiPlugin\Controller\PermisoReabrirParte;
PermisoEspecial::exigir(PermisoReabrirParte::class);

// (b) un use por PLUGIN, con alias: la recomendada cuando se usan varios
use FacturaScripts\Plugins\MiPlugin\Controller as MiPlugin;
PermisoEspecial::exigir(MiPlugin\PermisoReabrirParte::class);
PermisoEspecial::exigir(MiPlugin\PermisoAnularParte::class);

// (c) el nombre completo, sin use
PermisoEspecial::exigir(\FacturaScripts\Plugins\MiPlugin\Controller\PermisoReabrirParte::class);

// (d) el nombre corto, como texto
PermisoEspecial::exigir('PermisoReabrirParte');
```

| forma | ¿marca phpstan una errata? |
|---|---|
| (a) `use` de la clase | sí, donde se usa |
| (b) `use` del namespace con alias | sí |
| (c) nombre completo | sí |
| (d) texto | **no**: la errata sólo aparece al ejecutar, como permiso no instalado |

Con una errata, las cuatro **niegan** al ejecutar, también al administrador: un permiso que no está
instalado no deja pasar a nadie.

### Comprobar el permiso

La clase se llama por `Dinamic`, como cualquier otra del ERP:

```php
use FacturaScripts\Dinamic\Lib\PermisoEspecial;
use FacturaScripts\Plugins\MiPlugin\Controller as MiPlugin;
```

- **`exigir()`** — niega y deja el aviso con el motivo. Es la del punto a controlar, en el modelo antes de
  escribir:

  ```php
  if ($reabre && false === PermisoEspecial::exigir(MiPlugin\PermisoReabrirParte::class)) {
      return false;
  }
  ```

- **`puede()`** — sólo pregunta, sin avisar: para decidir qué se pinta.

  ```php
  $mostrarAnular = PermisoEspecial::puede(MiPlugin\PermisoAnularParte::class);
  ```

- **`motivo()`** — el texto de por qué no, o cadena vacía si puede: para ponerlo en la propia pantalla.
- **`$user`** — las tres aceptan un usuario como último argumento; sin él, preguntan por el de la sesión.
  **Sin usuario en sesión —cron, workers, API— niegan.**

  ```php
  PermisoEspecial::puede(MiPlugin\PermisoReabrirParte::class, $otroUsuario);
  ```

- **`existe()`** — si están instalados **todos** los permisos que se le pasan: para el plugin que depende
  de permisos de otro. Sin argumentos da `true`.

  ```php
  // Init.php del plugin que usa permisos de MiPlugin
  use FacturaScripts\Core\Tools;
  use FacturaScripts\Plugins\OkoPermisos\Lib\PermisoEspecial;
  use FacturaScripts\Plugins\MiPlugin\Controller as MiPlugin;

  public function update(): void
  {
      if (false === PermisoEspecial::existe(MiPlugin\PermisoReabrirParte::class, MiPlugin\PermisoAnularParte::class)) {
          Tools::log()->warning('faltan-permisos-de-miplugin');
      }
  }
  ```

**Dentro de un `Init`** se llama a la clase **del plugin** (`Plugins\OkoPermisos\Lib`), no a la de
`Dinamic`: el core ejecuta los `Init` antes que el controlador, y en una instalación sin desplegar
`Dinamic` todavía no existe. **En un `Init` se usa `existe()`**, que funciona ahí. `puede()` y `exigir()`
niegan por falta de sesión; y con un `$user` explícito necesitan `Dinamic`, porque `User::can()` del core
la usa: sin desplegar lanzan un `Error` en vez de contestar.

## Puntos de extensión

Otro plugin puede pintar algo suyo **junto a cada permiso** en las vistas de OkoPermisos, sin que
OkoPermisos lo conozca. Usa el mecanismo del core, `getIncludeViews()`: basta con poner un fichero
en el `Extension/View/` del plugin, con el nombre del hueco. No hace falta declarar dependencia, y si
el plugin se desactiva, su fichero deja de pintarse.

| fichero en `Extension/View/` | dónde sale | `hueco.nick` | `hueco.tiene` |
|---|---|---|---|
| `OkoPermisosVistaDelMenu_pagina.html.twig` | en cada página del menú del usuario elegido, concedida o no | el usuario elegido | si tiene acceso a la página |
| `OkoPermisosVistaDelMenu_especial.html.twig` | en cada permiso especial del mismo usuario | el usuario elegido | si tiene el permiso (`update` en su página) |
| `OkoPermisosIndice_permiso.html.twig` | en cada fila del índice de permisos especiales, junto al título | `null` | `null` |
| `OkoPermisosHoja_titular.html.twig` | en cada usuario de «quién lo tiene», en la hoja del permiso | ese usuario | `true` |
| `OkoPermisosHoja_permiso.html.twig` | una vez por hoja, debajo de cómo se concede | `null` | `null` |

**El contrato es la variable `hueco`**, y en todos los huecos tiene las mismas tres claves:

- **`hueco.pagina`** — el nombre de la página del permiso (`EditCliente`, `PermisoAnularParte`…), siempre
  como cadena.
- **`hueco.nick`** — el usuario del que habla esa línea, o `null` donde la vista no habla de ninguno.
- **`hueco.tiene`** — si ese usuario lo tiene, o `null` donde no hay usuario.

**Lo que pinte un hueco lo ve quien tenga acceso a `AdminPermisos`**, que no tiene por qué ser
administrador: el acceso al panel se concede por rol, como cualquier otra página.

El fichero ve también las variables de la propia vista, porque Twig hereda el contexto en un `include`.
**No forman parte del contrato**: sus nombres y su forma son internos y pueden cambiar sin aviso.

Un ejemplo, que marca en el menú del usuario las páginas que tiene:

```twig
{# Plugins/MiPlugin/Extension/View/OkoPermisosVistaDelMenu_pagina.html.twig #}
{% if hueco.tiene %}
    <span class="badge bg-info">{{ trans('mi-plugin-marca') }}</span>
{% endif %}
```

Los huecos son **por permiso**, así que sale una vez por línea. Dos plugins que rellenen el mismo hueco
salen los dos, en el orden del core: un tercer trozo numérico en el nombre lo cambia
(`OkoPermisosHoja_titular_20.html.twig`; sin él vale 10).

**El plugin dueño de un permiso tiene además su propia vía**: `detalles()` o el bloque
`okopermisos_hoja_extra` (ver «Permisos especiales en un plugin»). Los huecos son para los demás
plugins.

Las listas de las demás pestañas —«Permisos efectivos», «Impacto de quitar»— son vistas XML del core y
**no tienen huecos**.

## Lo que NO hace, a propósito

- **No escribe permisos.** La limpieza se hace con el panel de roles del core; una pantalla que
  escriba puede dejar a su dueño fuera de su instalación. Los permisos especiales tampoco: se conceden
  en la ficha del rol. Y no es sólo una intención: las tres acciones de escritura del panel —borrar,
  editar y crear— están cerradas **en el controlador**, no únicamente ocultas en la interfaz, porque el
  core decide la escritura por la pestaña que nombra la petición y no por lo que la pantalla enseñe.
- **No dice «esto no se usa».** No se puede saber: `logs` registra escrituras, logins y errores, no
  navegación, así que medir «páginas visitadas» ahí cuenta como sin usar cualquier permiso de lectura
  ejercido con normalidad. Esta herramienta responde por **redundancia**, que es exacta.
- **No crea tablas.** Lee `roles`, `roles_access`, `roles_users`, `users` y `pages`, todas del core. Su
  único ajuste es la lista de prefijos de acción (ver «Nombres de página legibles»), que se guarda con la
  pantalla de ajustes del core, en un grupo propio.

## Obsoleto

### `Lib/PermisosEspeciales` — el registro de permisos desde el `Init`

- **Qué**: la API vieja de permisos especiales (`registrar()` en el `Init`, `puede()`, `exigir()`,
  `motivo()`, `registrados()`, `rolDe()`, `plugin()`, `olvidar()` y la constante `AJUSTES`). Se retiró en
  la 1.04, y un plugin que todavía la llamaba desde su `Init` tumbaba la instalación entera.
- **Desde**: la 1.06. Conserva las firmas y ya no hace lo que hacía: `registrar()` no registra,
  `registrados()` está vacío, `rolDe()` devuelve `''`, y `puede()`, `exigir()` y `motivo()` **niegan a
  todos salvo al administrador**. Lo que protegía, mientras no se actualice el plugin que la usa,
  sólo lo hace el administrador.
- **Aviso**, nombrando el plugin que hay que actualizar, por dos vías:
  - **en el gestor de plugins, siempre** que lo abra un usuario con sesión (`AdminPlugins` o `Updater`),
    para poder corregirlo desde ahí. Lo pinta la vista `Extension/View/MenuTemplate_HeadEnd`, que sólo
    llevan las páginas con menú: el login no, así que un anónimo no ve qué plugin está desactualizado.
    Sale en pantalla y no se guarda en `logs`;
  - **en el registro, una vez al día** por plugin, en el canal `okopermisos-obsoleto`. El día se recuerda
    en `MyFiles/okopermisos-obsoleto.json`, que sólo se lee cuando alguien llama a esta API: un despliegue
    o un `Cache::clear()` no lo repiten. Si el fichero no se puede escribir, el aviso se limita a uno por
    hora con `Cache`, y el registro dice que el fichero no se puede escribir. Dos peticiones simultáneas
    pueden avisar las dos el mismo día.
- **Se retira**: la 1.09, tres versiones después de la que la reintroduce.
- **La sustituye**: `Lib/PermisoEspecial`. Cada permiso pasa a ser un controlador que hereda de ella, y
  el punto a controlar llama a `PermisoEspecial::exigir(MiPermiso::class)`; ver «Permisos especiales en
  un plugin». Las concesiones del diseño anterior —el ajuste `okopermisos`, un rol por código— las
  convierte el plugin dueño con una migración propia.

## Changelog

### v1.06
- **La API retirada en la 1.04 vuelve como esqueleto obsoleto.** `Lib/PermisosEspeciales` conserva las
  firmas, ya no registra nada y **niega a todos salvo al administrador**, y avisa —nombrando el plugin que
  hay que actualizar— en el gestor de plugins y una vez al día en el registro, canal `okopermisos-obsoleto`.
  **Por qué**: el registro se hacía desde el `Init`, y `Plugins::init()` del core no recoge los errores del
  `init()` de un plugin, así que en la 1.04 un plugin que todavía la llamara no se caía solo: tumbaba la
  instalación entera. **Se retira en la 1.09**; ver «Obsoleto».
- **Puntos de extensión en las vistas.** Otro plugin puede pintar algo suyo **junto a cada permiso** con el
  mecanismo del core (`getIncludeViews()`), sin declarar dependencia y sin que OkoPermisos lo conozca: basta
  un fichero en su `Extension/View/` con el nombre del hueco —`OkoPermisosVistaDelMenu_pagina` y
  `_especial`, `OkoPermisosIndice_permiso`, `OkoPermisosHoja_titular` y `OkoPermisosHoja_permiso`—. El
  contrato del hueco (`pagina`, `nick`, `tiene`) está en «Puntos de extensión».
- **Un permiso especial puede declarar su familia**, con `protected function familia(): string` y una clave
  de traducción de **20 caracteres como máximo**, que es lo que mide `pages.submenu`. El índice y la hoja la
  muestran, y la ficha del rol del core agrupa por ella. **Por qué**: un plugin puede declarar decenas de
  permisos especiales, y caían todos en un único grupo.
- **Se publica con licencia LGPL-3.0-or-later**: `COPYING`, la cabecera LGPL en los PHP como las del core, y
  la sección «Licencia» en el README.
- **`min_php = 8.1`** en `facturascripts.ini`, que el core sí comprueba al activar.
- **El README deja de prometer un aviso que no existe.** El changelog de la 1.04 decía que
  `require_min = 'OkoPermisos:1.04'` avisaría antes de instalar; es falso, porque el core no lee
  `require_min`. Ahora dice lo que hay que hacer de verdad: desactivar los plugins que usen la API vieja
  **antes** de actualizar.

  **Al actualizar**: no hay migración ni dependencia nueva —este plugin no crea tablas—. Si algún plugin
  todavía llama a `Lib/PermisosEspeciales`, actualizar ya no es urgente para que la instalación arranque,
  pero sigue siéndolo: mientras no se actualice, lo que ese permiso protegía sólo lo puede hacer el
  administrador, y en la 1.09 el esqueleto desaparece.

### v1.05
- **Seguridad: `AdminPermisos` ya no escribe nada desde ninguna pestaña.** Hasta la 1.04, un usuario con
  un rol que le diera acceso a la página y permiso de **borrar** podía, con una sola petición, borrar
  usuarios —**administradores incluidos**— desde «Vista del menú», y páginas desde «Permisos especiales».
  El panel explica los permisos y no los cambia: ahora rechaza borrar, editar y crear, lo avisa en
  pantalla y no ofrece los botones.

  **Por qué pasaba**: `PanelController` del core decide la escritura por la pestaña que **nombra la
  petición** (`activetab`), no por lo que enseña la interfaz. `deleteAction` sólo exige «borrar», el token
  y el `btnDelete` de la pestaña activa —y en una `HtmlView` ese ajuste vale `true` por omisión—, mientras
  que `editAction` e `insertAction` no miran ningún ajuste de la vista: sólo «modificar» y el token. Que
  esas dos no llegaran a escribir era una coincidencia —ninguna pestaña lee el formulario—, y dejaría de
  serlo con el primer `modalInsert`. Por eso el cierre está en las tres acciones del controlador y no en
  los ajustes de cada vista.

  **Al actualizar**: no hay migración ni dependencia nueva. Mientras no se instale, la vía es quitar
  «borrar» a los roles que tengan acceso a `AdminPermisos`.

### v1.04
- **Aviso de actualización, y rompe compatibilidad**: desaparecen `Lib/PermisosEspeciales` con su
  `registrar()`, el ajuste «okopermisos» —el rol por permiso— y su pestaña. Un plugin que los use tiene
  que pasar a declarar controladores. Y no degrada: como el registro se hacía **desde el `Init`**, al
  activar los plugins salta un `Error: Class "…\Lib\PermisosEspeciales" not found` que **nadie
  recoge** —`Plugins::init()` no envuelve el `init()` de cada plugin—, así que no se cae sólo el plugin
  que llama. FacturaScripts no comprueba la versión mínima de una dependencia, así que nada lo avisa
  antes de instalar: hay que **desactivar los plugins que usen la API vieja ANTES de actualizar**.
- **Un permiso especial es una página de FacturaScripts.** El plugin lo declara con un controlador que
  hereda de `Lib/PermisoEspecial`, se concede en la **ficha del rol** con «modificar», y se comprueba
  con `PermisoEspecial::exigir()`, `puede()` o `motivo()`. El cambio de fondo es dejar de tener
  mecanismo propio: como es una página, el core ya sabe concederla, comprobarla y ocultarla, y todas
  las vistas la enseñan sin código especial.
- **`PermisoEspecial::existe()`** comprueba que estén instalados los permisos especiales de los que
  depende un plugin. Funciona también desde el `Init` de otro plugin antes del primer despliegue, que
  es donde `Dinamic` todavía no existe y las otras dos formas de preguntar no sirven.
- **Índice «Permisos especiales»** dentro del panel Permisos, sólo para administradores, y una **hoja
  por permiso**: qué permite, de qué plugin viene, quién lo tiene y por qué rol, y cómo se concede. La
  hoja se abre con acceso al panel, no con el permiso: tenerlo no deja verla.
- **«Tiene acceso»**, columna y filtro en «Permisos efectivos», arrancando en «sí» y delante de
  «Visible en el menú». Con «no» o «todos» sale también lo que **falta**, que es la otra mitad de la
  pregunta. Y «Visible en el menú» pasa a ser del **usuario**: que la página salga en su menú y que
  además tenga acceso.
- **La columna «Página» enseña el nombre que se lee y el técnico**, «EmailConfig (ConfigEmail)», en
  efectivos y en impacto. Los prefijos que se separan son **configurables** —por defecto `List`,
  `Edit`, `Config`, `Report`, `Admin`, `Send`, `New`, `Export` e `Import`—, así que añadir uno es una
  opción de la instalación y no una versión nueva. Un prefijo no válido **se avisa** en vez de
  descartarse en silencio.
- **El README explica cómo se registra un permiso especial**, con ejemplos de cada forma de nombrarlo y
  de comprobarlo.
- **Orden por defecto «Menú y página»**: menú, submenú, entidad y prefijo. Se retira el orden
  alternativo, que duplicaba éste.
- **La «Vista del menú» enseña los permisos especiales** con su estado para el usuario elegido.
- **El mensaje de cómo se concede un permiso** usa los textos de la pantalla del grupo, para que la
  negativa y lo que se ve al conceder digan la misma frase.
- **`min_version` sube a 2026**: el plugin usa `Core\Template\JoinModel`.

**Comprobado antes de etiquetar, en una copia con base de datos propia y el entorno de pruebas
rehecho desde cero**: `main` **49 de 49**, 499 aserciones, en 11,6 s. **Control del cambio**: con
`PermisoEspecial::existe()` devolviendo siempre `true` caen **5**, y son justo las suyas
—`testNoExisteUnPermisoSinInstalar`, `testUnaPaginaCualquieraNoExisteComoPermiso`,
`testExisteExigeQueEstenTodos`, `testFuncionanSinDinamic` y `testLasCuatroFormasDeNombrarUnPermiso`—;
al deshacer la mutación vuelven las 49. Y la **incompatibilidad de arriba está medida, no supuesta**:
con un plugin que todavía llame a `PermisosEspeciales::registrar()` desde su `Init`, construir el
esquema de pruebas muere con ese `Error` y no llega a montarse nada.

### v1.03
- **Aviso de actualización**: las páginas `ListPermisoEfectivo`, `PermisosEspeciales` y `VistaDelMenu`
  dejan de existir. Los accesos de rol concedidos a ellas quedan **huérfanos** —`roles_access` no tiene
  clave ajena a `pages`, así que nada lo avisa— y hay que volver a concederlos sobre `AdminPermisos`. A
  los administradores no les afecta.
- **Un solo panel, `AdminPermisos`**, con una entrada lateral por listado: permisos efectivos, impacto
  de quitar, administradores, permisos especiales, vista del menú, usuarios y roles. Las tres entradas
  de menú anteriores desaparecen. Se llama `Admin…` y no `List…` por la convención del framework: un
  panel de administración hecho de listas es `Admin…`, como `AdminCRM` o `AdminPlugins`.
- **Usuarios y roles** del core se listan dentro del panel, con su búsqueda y sus órdenes. La edición
  sigue en `EditUser` y `EditRole`, adonde lleva cada fila, y las páginas del core no se tocan.
- **Arreglo**: la pestaña de administradores listaba **todos** los usuarios y presentaba como
  administrador a quien no lo es. Ahora lista sólo los que tienen el flag `admin`, y se puede seguir
  buscando dentro de ella. Hacían falta dos cosas y no una: acotar en `loadData()` y desactivar la
  megabúsqueda, que llama al `loadData()` de la vista directamente y se salta el del controlador. Con
  el panel, la megabúsqueda ya no alcanza la página, y un test fija que no vuelva a llamarse `List…`.
- **Cambios de comportamiento** por ser panel, para que nadie los dé por iguales:
  - `onlyOwnerData` deja de aplicarse a estas listas: el `PanelController` del core tampoco lo aplica
    a las suyas, porque el filtro de propietario es de `ListController`.
  - La megabúsqueda ya no las alcanza: sólo consulta páginas que empiezan por `List`.
  - Quien tenga acceso al panel sin ser administrador ve **todos** los usuarios y roles, con sus
    emails, aunque no pueda editarlos sin el permiso correspondiente del core.

### v1.02
- **Permisos especiales**: un rol sin accesos cuya pertenencia **es** el permiso. Cada plugin lo
  registra desde su `Init.php` declarando el modelo o controlador al que afecta, y el punto que hay que
  controlar hace una sola llamada. Nace porque FacturaScripts no tiene permisos por campo: son por
  página, y el administrador los pasa todos.
- Página **«Permisos especiales»**: qué rol tiene cada uno. Sólo la cambia un administrador —elegir el
  rol **es** conceder el privilegio— y marca en rojo lo que no tiene rol asignado o apunta a uno
  borrado.
- El plugin de cada permiso se **deriva** del namespace de la clase, no se guarda: un nombre almacenado
  envejecería al renombrar el plugin.
- En la **Vista del menú** cada permiso especial sale junto a la página de su entidad, y los que no
  tienen página, al pie. Toda negativa dice **qué rol** hace falta, o que no hay ninguno asignado.

### v1.01
- Primera versión publicada.
- **Permisos efectivos**: el cruce usuario × página con los roles que lo conceden y cuántos son, los
  cuatro flags agregados por su valor más permisivo y `sólo lo suyo` por el más restrictivo. Nueve
  filtros, entre ellos los cuatro permisos por sí/no y el filtro por rol, que casa el código **cercado
  por comas** para que `ventas` no arrastre a `ventasconsulta`.
- **Impacto de quitar**: por cada acceso de cada rol, cuántos usuarios lo perderían de verdad. Es la
  pregunta que la interfaz del core no responde y la que decide si un acceso es redundante.
- **Vista del menú**: el menú de un usuario con el aspecto del real, con lo que no ve atenuado en su
  sitio y las etiquetas de lo que puede hacer en cada entrada.
- Las listas y las fichas salen **juntas y en el orden del menú**: una ficha se ordena por el sitio de
  su lista, no por el suyo, porque en el menú sólo aparecen las listas.
- Las tres vistas son de **sólo lectura y no clicables**: sus filas no son registros, así que no hay
  ficha que abrir.

## Licencia

Copyright (C) 2026 Oko Digital Experts, S.L.L. (Okodex)
@author Alexis Serafín <alexis@okodex.com>

Software libre bajo la **GNU Lesser General Public License v3.0 o posterior** (`LGPL-3.0-or-later`),
la misma que el core de FacturaScripts. El texto completo está en [`COPYING`](COPYING).
