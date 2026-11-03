# OkoPermisos — guía para Claude Code

Plugin de **FacturaScripts** que **explica** los permisos y no los cambia: el cruce usuario × página
con los roles que lo conceden, qué se pierde de verdad al quitar un acceso, y el menú de un usuario
tal como lo vería. Qué hace y por qué, en el `README.md`; aquí lo que hace falta para **tocarlo**.

## Lo que hay que saber antes de escribir una línea

- **Los permisos vienen sólo de roles.** No hay tabla usuario–página, así que la única «asignación
  directa» que existe es el flag `users.admin`, que salta todo. Esta pantalla no distingue dos orígenes
  —no los hay— sino **cuántos y cuáles** roles conceden lo mismo.
- **`allowAccess` (entrar) y `allowupdate` (guardar) son cosas distintas.** Confundirlas es lo que
  llevó a buscar restricciones inexistentes en el core cuando lo que faltaba era el acceso a la ficha.
- **`RoleAccess::clear()` pone `allowexport` e `allowimport` a `true`.** Un acceso creado sin tocarlos
  deja sacar los datos aunque no deje modificarlos.
- **Nada aquí escribe permisos**, a propósito: la poda se hace con el panel de roles del core. Una
  pantalla que escriba puede dejar a su dueño fuera de su instalación. Tampoco los especiales: se
  conceden en la ficha del rol, como cualquier página.
- **Quién ABRE el panel lo decide el sistema de permisos de FacturaScripts**, como con cualquier otra
  página: se concede por rol. Es el diseño, no un descuido —decisión de Alexis: el panel va dentro del
  sistema de permisos de FS, y cómo se maneja es cosa de los administradores—. **No le pongas un
  candado de administrador**: está descartado a conciencia. La consecuencia, para que nadie la descubra
  tarde: quien reciba acceso al panel sin ser administrador **ve todos los usuarios y roles, con sus
  emails**, aunque no pueda editarlos sin el permiso del core. Eso lo decide quien concede el acceso.
- **No crea tablas.** Lee `roles`, `roles_access`, `roles_users`, `users` y `pages`, todas del core. Su
  **único ajuste** es la lista de prefijos de acción de `Lib/NombreDePagina` (ver abajo), y lo guarda la
  pantalla de ajustes del core, no el plugin.

## Prefijos de acción configurables (`Lib/NombreDePagina`)

- **La columna «Página»** de efectivos e impacto pinta `pagina_nombre`, que gira el prefijo de acción al
  final y deja el controlador real entre paréntesis («EmailConfig (ConfigEmail)»). El campo `pagina`
  sigue siendo el real: lo leen `MenuDelUsuario`, las hojas y el buscador.
- **La misma lista da la `entidad` y el `prefijo`** del orden por defecto, con una sola condición para
  las tres expresiones (`segun()`): empieza por el prefijo y detrás viene una mayúscula. Sin eso
  `Newsletter` se leería «sletterNew».
- **La lista es cerrada y de la instalación**: girar la primera palabra de cualquier nombre rompe los que
  no empiezan por una acción (`EmployeePanel`, `UserChat`). Pestaña `XMLView/SettingsOkoPermisosPrefijos.xml`
  en Panel de control; vacío = `POR_DEFECTO`, que no se siembra en la base.
- **Grupo propio, `okopermisosprefijos`**: `EditSettings` toma el grupo del sufijo del fichero en
  minúsculas, y el grupo `okopermisos` lo leen otros plugins (la migración de los permisos especiales del
  diseño anterior). Esta pestaña no puede tocarlo por construcción.
- **Sólo letras**: los prefijos van DENTRO del SQL de las vistas, así que lo demás se descarta, y se
  avisa con `Tools::log()->warning` para que no desaparezca en silencio.

## Permisos especiales (`Lib/PermisoEspecial`)

**Un permiso especial es una página de FacturaScripts**, y tenerlo es tener **`update`** en ella: el
core ya sabe concederla (ficha del rol), comprobarla (`User::can`) y ocultarla (`showonmenu`). El plugin
dueño declara un controlador que hereda de `Lib/PermisoEspecial` y pone el título (clave de traducción);
el punto a controlar llama a `PermisoEspecial::exigir(MiPermiso::class)`.

- **La base está en `Lib/` y no en `Controller/`**: `PluginsDeploy::initControllers` instancia cada
  clase de `Controller/` para leer su página, y una abstracta revienta ahí. `Dinamic` conserva el
  `abstract` (`PluginsDeploy.php`, la detección de `abstract class`).
- **Un controlador y no una fila suelta en `pages`**: el deploy borra las páginas sin controlador
  (`PluginsDeploy::removeOldPages`).
- **El menú propio es `permisos-especiales`** (`PermisoEspecial::MENU`), con `showonmenu = false`: es lo
  que distingue una página de permiso de las demás, y `instalados()` lista por él. `pages.menu` es
  `varchar(20)`: `okopermisos-especiales` no cabía. El código lleva traducción porque el filtro de menú
  de «Permisos efectivos» lo traduce.
- **El índice es una pestaña más de `AdminPermisos`** (`PermisosEspeciales`, plantilla
  `OkoPermisosIndice`), sin condición de admin: quién entra en el panel lo decide el admin con los
  roles, como en cualquier página. Hubo una página suelta, `AdminPermisosEspeciales`, sin conceder para
  que sólo la viera el admin; se retiró en la revisión de Alexis por eso mismo.
- **La familia va a `pages.submenu`** (`familia()`, opcional, clave de traducción). Es lo único que
  agrupa en la ficha del rol del core: `EditRole` ordena por `menu, submenu, title` con la **clave**, no
  la traducción, deja los `null` primero y prefija « » familia » ». El índice del panel ordena por la
  traducción, así que los grupos pueden salir en otro orden; está dicho en el README. Medido pintando
  `EditRole` en una copia con una sonda (oct-2026). **Más de 20 caracteres se niega y se avisa, no se
  recorta** (decidido con el asistente): con la base en modo estricto el `INSERT` falla
  (`Page::save()` da `false`, medido) y recortada no traduciría. El aviso sale en cada `getPageData()`,
  también al abrir la hoja, a propósito. **La familia no entra en el contrato `hueco`**: es del permiso
  y sale de `hueco.pagina`.
- **La hoja** es el propio controlador (`Template\Controller` + `OkoPermisosHoja.html.twig`). El dueño
  añade filas con `detalles()` o una plantilla que extienda ésta y rellene `okopermisos_hoja_extra`.
- **La hoja se abre con acceso a `AdminPermisos`, no con el permiso.** La página ES la que da el
  permiso, así que su acceso propio no puede decidir quién la ve: un titular sin más accesos la abría
  por URL. `PermisoEspecial` pone `$requiresAuth = false` —el `run()` del core autentica igual, pero no
  exige acceso a esta página— y `exigirAccesoAlPanel()` lanza `KernelException('AccessDenied',
  'access-denied')`, lo mismo que `Template\Controller`. Quien llega lo ve todo, titulares incluidos
  (`titularesDe()`, sobre `Join\PermisoEfectivo`).
- **El mensaje de cómo se concede** (`comoSeConcede()`) se compone con las claves de la pantalla del
  grupo —`roles`, `rules`, `show`, `allow-update`, el código del menú y el título de la página—, no con
  texto escrito a mano: sigue lo que la instalación pinta allí. Si una instalación traduce `roles` como
  «Roles» y no como «Grupos», el mensaje lo sigue.
- **`motivo()`**: página no instalada (o que no es del menú de permisos) → niega, también al admin;
  sin sesión → niega (`sinSesion()`, ver Frontera); si no, `can(update)`. La negativa nombra el permiso
  y la página que lo concede.
- **`existe()` sale de `pages`, no de un registro en memoria.** El core ejecuta los `Init` por `order`
  (`Core/Plugins.php`, `Plugins::init`), y ese `order` es el de activación: al activar, `maxOrder() + 1`.
  Si el plugin dueño se desactiva y se reactiva, su `Init` corre después del de quien depende de él, y un
  registro hecho desde los `Init` diría «no está». `pages` lo escribe el despliegue y no tiene orden.
  Comparte con `motivo()` el predicado `instalada()`, para que «instalado» signifique lo mismo en los dos.
- **`instalada()` lee con `Core\Model\Page`, no con el de `Dinamic`** (decidido por Alexis, oct-2026).
  Medido con un plugin sonda cuyo `Init` llamaba a `existe()`: con `Dinamic` construido funcionaba en
  cualquier orden de `Init`, y sin él moría con `Class "FacturaScripts\Dinamic\Model\Page" not found`,
  porque `index.php` ejecuta `Plugins::init()` antes que `Kernel::run()`. Con el del core, `existe()` vale
  en un `Init` antes del primer despliegue (sólo se leen `name`, `title` y `menu`, que no dependen de
  extensiones), y `motivo()`, `puede()` y `exigir()` contestan **sin usuario**: no hay sesión, así que
  niegan. **Con un `$user` explícito no**: `User::can()` del core usa `Dinamic\Model\Page` (admin) y
  `Dinamic\Model\RoleAccess` (los demás), así que sin `Dinamic` lanzan un `Error`. Falla cerrado, pero no
  contesta; en un `Init`, `existe()`. Y sin `Dinamic` no hay traducciones: los avisos salen con la clave.
  `instalados()` sigue con el de `Dinamic`: sólo lo llama la pestaña del panel.
- **La clase del permiso se nombra por el namespace de su plugin** en los ejemplos del README, nunca por
  `Dinamic` (decidido por Alexis): el análisis estático la encuentra en el código fuente y `pagina()` sólo
  usa el nombre corto. Medido con el phpstan del test-env (0.12, nivel 0): una errata en `::class` la
  marca con `use` de la clase, con `use` del namespace con alias y con el nombre completo; en un texto,
  no. Lo de un IDE concreto no se mide aquí, y cualquier solución propia de un IDE (`.phpstorm.meta.php`)
  está descartada por Alexis.
- **`User::can()` del admin exige que la página exista** (`Page::load`), así que un permiso sin
  desplegar se niega incluso al admin, que es lo que se quiere.
- **Una concesión sobrevive a la página… hasta que alguien guarda las Reglas de un rol.** `roles_access`
  sólo tiene FK a `roles`, no a `pages`, así que si el plugin dueño se desactiva y el deploy retira su
  página, la fila sigue ahí. Pero `EditRole::removeOrphanAccess()` del core, que corre al guardar la
  pestaña **Reglas** de **cualquier** rol (acción `edit-rules`), borra todas las filas de `roles_access`
  cuya página ya no existe —de todos los roles, no sólo del que se guarda—, y si un rol se queda sin
  ninguna regla, **borra el rol**. Así que con el plugin dueño desactivado, la concesión vuelve a valer
  al reactivarlo sólo si nadie ha guardado las Reglas de un rol entretanto; si no, hay que concederla
  otra vez.
- **El dueño extiende la clase del plugin** (`Plugins\OkoPermisos\Lib\PermisoEspecial`), no la de
  `Dinamic`, para no depender de que el deploy ya la haya copiado: el `Init` de un consumidor corre antes
  del deploy, y con OkoPermisos recién actualizado la de `Dinamic` todavía no existe. Y declara `require = '…,OkoPermisos'`.
- **Vista del menú**: las páginas de permiso no están en el menú real, así que
  `MenuDelUsuario::especiales()` arma un nodo aparte, evaluado **por roles** (un admin, sin el flag),
  con «sólo ve la hoja» para el acceso sin `update`.
- **Lo que había antes y ya no**: registro en memoria desde `Init` (`PermisosEspeciales::registrar`), el
  ajuste `okopermisos` con un rol por permiso y la pestaña que lo guardaba. Convertir ese ajuste en
  concesiones es cosa del plugin dueño —es quien sabe qué página corresponde a cada código viejo—, con
  una migración propia que lea `Tools::settings('okopermisos', <código>)`.

## Las cicatrices del framework, que aquí ya se pagaron

- **`JoinModel` no genera `HAVING`.** Construye `WHERE … GROUP BY`, así que un campo agregado **no se
  puede filtrar** —`WHERE MAX(...) = 0` es SQL inválido— y `Where` no admite SQL crudo con el que
  esquivarlo. De ahí que la agregación de `PermisoEfectivo` viva en una **subconsulta derivada**: los
  flags son columnas normales de la derivada y se filtran con un `Where::eq` cualquiera. Filtrar
  `allowupdate = 0` *antes* de agrupar daría los usuarios con *algún* rol sin escritura, aunque otro se
  la conceda, que es lo contrario del permiso efectivo.
- **Los `Where` sobre estas vistas van CUALIFICADOS** (`e.nick`, `ra.codrole`): el WHERE se aplica
  sobre las tablas del FROM, no sobre los alias del SELECT, y `codrole` está en tres sitios. En un
  modelo normal (`RoleAccess`) la columna **no** lleva alias — el `sed` que cualificó de más lo rompió.
- **`execPreviousAction` corre ANTES de que se pueblen los filtros.** `PanelController::privateCore`
  lo llama antes del `processFormData`, así que preguntarle su valor a un filtro devuelve vacío
  siempre. El botón de la vista del menú lee la **petición** (`filternick`, con el prefijo que pone
  `BaseFilter::name()`). Y `BaseFilter::$value` es **protegido**: leerlo revienta la pantalla.
- **`addButton()` no hace nada sin `<row type="actions"/>` en el XMLView** (`if ($row)` en el core), y
  falla en silencio. Los botones se guardan en `RowActions::$children`, no en un `buttons`.
- **Es un solo `PanelController`, `AdminPermisos`**, con una entrada lateral por listado. Las listas
  van con `addListView` y las dos plantillas —permisos especiales y vista del menú— con `addHtmlView`,
  que **sólo existe en `PanelController`**; antes eran controladores sueltos por eso mismo.
- **Un panel hecho sólo de listas enseña una sola entrada si no se le dice lo contrario.**
  `PanelController::privateCore` desactiva todas las vistas menos la primera cuando `hasData` es falso,
  y sólo lo pone a verdadero si `$model->exists()` de la primera, que en una lista nunca lo es. De ahí
  el `$this->hasData = true` de `loadData()`, igual que `AdminCRM` y `ConfigEmail`.
- **La plantilla de un `addHtmlView` es un FRAGMENTO**: el panel la pinta con `include(view.template)`,
  así que no extiende ninguna maestra. Sus formularios vuelven a `AdminPermisos` con un `activetab`
  oculto, y sus acciones se procesan en `execPreviousAction`. Los nombres de plantilla llevan el prefijo
  `OkoPermisos` porque son globales: `Plugins::deploy()` las junta todas en `Dinamic/View/`.
- **`PanelController` no aplica `onlyOwnerData` a sus listas**: `getOwnerFilter` es de `ListController`.
- **La megabúsqueda no llega a este panel**: `MegaSearch` sólo consulta páginas que empiezan por `List`.
- **`DbQuery` no tiene `join()`**, y `orderBy` toma un solo campo: para varios, `orderMulti`.
- **El menú lo pinta `Core/View/Macro/Menu.html.twig`** (macro `showMenu`), y su aspecto sale de clases
  **propias de FS**, no sólo de Bootstrap: `.dropdown-submenu` y `.fs-menu-item` las define
  `Core/Assets/CSS/custom.css`. Aquí se copian esas clases en vez de reutilizar la macro, porque
  recibe `MenuItem` y su `title` se escapa: no hay por dónde meter las etiquetas de permisos.
- **El orden del menú NO es el de la consulta.** El core ordena por `lower(menu)`… y después
  `sortMenu()` lo rehace con `strcasecmp` sobre el título **ya traducido**, recursivo en los tres
  niveles. Consecuencia: `ordernum` **no ordena nada** en el menú real, sólo desempata.
- **`MenuManager` lee el usuario de `Session::user()` en el constructor**, y cachea. Para armar el menú
  de otro usuario hay que fijar la sesión y limpiar la caché; no hay parámetro.

## Puntos de extensión de las vistas

Cinco huecos `getIncludeViews()` por permiso, con el contrato `hueco = {pagina, nick, tiene}` (tabla en el
README). **Este plugin no nombra a ningún consumidor**: el contrato es genérico, y el plugin se publica
sin depender de nadie.

- **La posición no puede llevar `_`**: el core parte el nombre del fichero por ese carácter, y un tercer
  trozo es el orden (`Core/Html.php`, `getIncludeViews`).
- **`getIncludeViews` se llama una vez por vista, no una vez por línea.** Recorre de forma recursiva el
  `Extension/View` de todos los plugins activos en cada llamada, y la vista del menú pinta todas las
  páginas, también las denegadas. La lista resuelta se pasa a las macros `entrada` y `especial`, que no
  heredan el contexto, igual que el `nick`.
- **`hueco` se pasa con `include … with`**, y no se usan las variables del bucle como contrato. Esas
  variables son internas y distintas en cada vista, y renombrarlas rompería a un consumidor sin aviso.
- **Los huecos van con `{%~` o en línea**, para que sin extensiones la salida sea la misma que antes.
  Medido al añadirlos: el HTML de las tres vistas contra `develop` sólo difiere en el token CSRF, la
  barra de depuración y el tiempo de ejecución.

## Tests

Un escenario, `Test/main/`. `FamiliaTest` prueba la familia —el `submenu` por el `loadPage()` real del
deploy, también al redesplegar, la clave larga, la agrupación del índice y la hoja— con diez mutaciones
que caen todas. Además: `PermisosEfectivosTest` prueba la **aritmética** que
decide una poda, `TieneAccesoTest` la fila por usuario × página —acceso sin «modificar» fuera del menú,
la página sin acceso, el admin, y que la Vista del menú no cuente lo que no tiene—, `VistaDelMenuTest`
el **orden** del árbol y el destino del botón, `PermisosEspecialesTest` los permisos especiales
—`update` sí/no, admin, no instalado, sin sesión, fuera de todo menú, la puerta de la hoja, el mensaje,
efectivos e impacto, el nodo de la Vista del menú, `existe()`, las cuatro formas de nombrar un permiso
y que todo vale sin `Dinamic`—, y `AdminPermisosTest` el **panel**: sus siete entradas activas y en
orden, el filtro «Tiene acceso» que arranca en «sí», la búsqueda y los filtros dentro de una pestaña y el
destino de las filas.

- **`PuntosDeExtensionTest` prueba los huecos con un plugin sonda.** Twig no deja redefinir
  `getIncludeViews` (`StagingExtension::addFunction` lanza «already registered»), así que un hueco sólo
  se ve si el core encuentra un fichero de verdad. La sonda se crea en `FS_FOLDER/Plugins/OkoPermisosSonda`
  del test-env, que es una carpeta real e ignorada y no el enlace al repo de un plugin. Se da por activa
  añadiéndola por reflexión a `Plugins::$plugins`, y se retira en un `finally`. Con `FS_DEBUG`, Twig lee
  las vistas de la carpeta del plugin, así que cambiar una vista no exige re-provisionar. Se comprobó con
  siete mutaciones: quitar cada uno de los seis `include` y no pasar el `nick` a la macro. Cayeron todas.

- **Lo que lee `Join\PermisoEfectivo` por usuario tiene que filtrar** `e.tiene_acceso = 1` (o
  `e.cuantos_roles > 0` si evalúa sólo los roles): sin eso trae todas las páginas, con y sin acceso.
- **El runner web se cuelga con muchos tests en rojo.** PHPUnit se queda en `pipe_write`: el runner no
  lee su salida mientras espera, y el volcado de `LogErrorsTrait` llena el búfer. `curl` no vuelve.
  Medido: 612 s parado con la base ociosa. Rodeo: lanzar por fichero (`file=…`) y `curl --max-time`;
  si ya está colgado, matar el `phpunit` del contenedor de test. Es del arnés, no de este plugin.

- **El permiso de prueba es una clase dentro del propio fichero de test** (`PermisoOkoPermisosPrueba`),
  y su página se siembra en `pages` como la dejaría el deploy: el escenario no trae ningún plugin que
  declare uno. Lleva `OkoPermisosPrueba` en el nombre por el barrido del `tearDown`.
- **Clases nuevas y Dinamic del test-env**: si la lista de plugins del escenario no cambia, el runner
  no hace `Plugins::deploy()` y dice «Entorno ya sincronizado». Al añadir `Lib/PermisoEspecial` hizo
  falta **re-provisionar** (receta en el `CLAUDE.md` del proyecto anfitrión): la provisión deja los
  plugins desactivados y el runner, al reactivarlos, despliega. Y la provisión arranca **todos** los
  plugins del proyecto, así que un consumidor roto —un `Init` que llama a una clase retirada— la tumba
  aunque se pruebe sólo OkoPermisos.
- **El formateador del editor ordena los métodos alfabéticamente**: no recortes un fichero de test
  por posiciones pensando en el orden en que lo escribiste.
- **«Sin `Dinamic`» se prueba en otro proceso** (`testFuncionanSinDinamic`): arranca el kernel con el
  autoload de composer sustituido por uno que no resuelve nada de `FacturaScripts\Dinamic\`, y repite lo
  mismo sin cortar como control. Sólo hace `Kernel::init()`, no `Plugins::init()`: los `Init` de todos los
  plugins del entorno, con `Dinamic` cortado, probarían otra cosa. La llamada desde un `Init` real se midió
  aparte, con un plugin sonda. En el proceso de PHPUnit no se puede: las clases de `Dinamic` ya están
  cargadas y no se descargan. Las variantes de nombrar un permiso del README se ejecutan en
  `testLasCuatroFormasDeNombrarUnPermiso`, la de alias con `use FacturaScripts\Test\Plugins as Prueba`.
- **Los nicks de prueba llevan un dígito**: `User::setPassword()` lo exige y devuelve `false` sin él,
  dejando la contraseña nula y el `save()` fallando por la columna NOT NULL, con un mensaje que señala
  `password` y no el porqué. Se comprueba el retorno.
- **Las páginas sembradas se llaman `…OkoPermisosPrueba…`, nunca `ListOko…`.** El barrido del
  `tearDown` casaba `ListOko%`/`EditOko%`, que son páginas **reales** de los demás plugins propios
  registradas en la base de pruebas. Mismo error que ya se cometió con los roles.
- **`pages.menu` es `varchar(20)`**, y una clave de menú más larga hace fallar el `INSERT` sin que el
  modelo diga por qué.
- **El orden se contrasta contra `MenuManager`**, no contra el propio criterio, que pasaría siempre. Y
  el test de la traducción usa un par elegido para que los dos criterios discrepen: por clave `users` va
  antes que `warehouse`, por traducción «Almacén» antes que «Usuarios».
- Los dos tests nuevos se comprobaron **saboteando el código**: sin ordenación, cae el contraste con
  `MenuManager`; leyendo el filtro en vez de la petición, cae el del botón.

## Frontera

**Destino:** que la poda de permisos se pueda hacer con datos delante. Terminada cuando los
administradores sean sólo uno y ningún acceso siga concedido «por si acaso».

### Pendiente de decidir

- ¿**Hueco por permiso en las listas XMLView** (`ListImpactoDeQuitar`, rol × página)? — exigiría que una
  extensión de XMLView dé un valor calculado a un `JoinModel`, y no está medido. El recuento por rol lo
  pinta el consumidor en la ficha del rol del core, sin pasar por aquí.
- ¿Un permiso especial **sin usuario en sesión** (cron, worker, API) niega o pasa? — hoy niega, por
  ser el lado seguro y porque el consumidor previsto comprueba la transición y no el estado. Elección
  del codificador, no de Alexis; cambiarla es `PermisoEspecial::sinSesion()` y su test. Siguió igual al
  pasar los permisos a páginas.
- ¿Se **publica el árbol de un admin** en verde, o se sigue avisando de que no describe lo que ve? Hoy
  avisa y muestra lo que le quedaría **sin** el flag, que es lo útil para la poda, pero se lee raro.
- niebla: si esta pantalla debería poder **proponer** la poda —listar los accesos redundantes de un rol
  como una sola sugerencia— sin llegar a escribirla. Roza el «no escribe permisos» de arriba, que es
  deliberado; la línea exacta no está enunciada.

### Fuera de alcance

- **Que marcar un menú entero en las Reglas del rol no conceda los permisos especiales.** Marcar la fila
  del menú `permisos-especiales` —o la general— en las Reglas de un rol los concede todos a la vez, igual
  que marcar el menú de Contabilidad concede todo Contabilidad. Es el comportamiento esperado de
  FacturaScripts y no se cambia (Alexis, 15-oct-2026).
- **Escribir permisos.** Decidido: la limpieza se hace en el panel de roles del core.
- **Un botón «Conceder» en OkoPermisos.** Un permiso especial se concede en la ficha del rol, como
  cualquier página; esta pantalla no escribe permisos.
- **La matriz usuarios × permisos especiales.** Con decenas de permisos no se puede usar; «quién tiene
  X» lo dicen la hoja del permiso y el filtro de «Permisos efectivos».
- **Un menú «sólo admin» por `level` o por un flag.** El core no lo soporta: una fila de `roles_access`
  ES el acceso y el menú la cuenta sin mirar flags. Y tampoco hace falta: el índice es una pestaña
  del panel, y quién entra en él lo decide el admin con los roles.
- **Restringir el panel a administradores por construcción.** Decidido por Alexis: el acceso se
  concede por rol, como en cualquier página. El porqué, arriba.
- **Decir «esto no se usa».** No se puede saber: `logs` registra escrituras, logins y errores, no
  navegación, así que un permiso de lectura ejercido con normalidad sale como sin usar. Además el canal
  arranca en una fecha de corte, así que tampoco cubre la historia. Esta herramienta responde por
  **redundancia**, que es exacta.
