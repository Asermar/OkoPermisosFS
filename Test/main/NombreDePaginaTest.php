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
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\Join\PermisoEfectivo;
use FacturaScripts\Dinamic\Model\Page;
use FacturaScripts\Dinamic\Model\User;
use FacturaScripts\Plugins\OkoPermisos\Lib\NombreDePagina;
use FacturaScripts\Test\Traits\LogErrorsTrait;
use PHPUnit\Framework\TestCase;

/**
 * **La columna «Página» lleva el prefijo de acción al final y el controlador real entre paréntesis.**
 *
 * Se prueba por la consulta de verdad —el campo `pagina_nombre` de `PermisoEfectivo`—, que es lo que se
 * pinta y por lo que se ordena; un admin sirve porque tiene una fila por cada página.
 */
final class NombreDePaginaTest extends TestCase
{
    use LogErrorsTrait;

    /** **La lista es de la instalación**: con otra lista, gira lo que ella diga y deja de girar lo demás. */
    public function testConLaListaCambiada(): void
    {
        Tools::settingsSet(NombreDePagina::AJUSTES, NombreDePagina::CLAVE, 'Panel, x-no-vale, Edit');

        $this->assertSame('OkoPermisosPruebaPanel (PanelOkoPermisosPrueba)', $this->fila('PanelOkoPermisosPrueba')->pagina_nombre);
        $this->assertSame('ListOkoPermisosPruebaNombre', $this->fila('ListOkoPermisosPruebaNombre')->pagina_nombre,
            'List ya no está en la lista');
        $this->assertSame(['Panel', 'Edit'], NombreDePagina::prefijos(), 'lo que no son letras se descarta');

        // y se avisa, no se traga: el aviso nombra el prefijo descartado
        $avisos = array_column(Tools::log()::read('', ['warning']), 'message');
        $esperado = Tools::trans('okopermisos-prefijo-no-valido', ['%prefijo%' => 'x-no-vale']);
        $this->assertNotSame('okopermisos-prefijo-no-valido', $esperado, 'control positivo: la clave tiene traducción');
        $this->assertContains($esperado, $avisos);
        Tools::log()::clear();
    }

    /** Con prefijo de la lista: gira, y el nombre real queda entre paréntesis y en `pagina`. */
    public function testConPrefijoGira(): void
    {
        $fila = $this->fila('ListOkoPermisosPruebaNombre');
        $this->assertSame('OkoPermisosPruebaNombreList (ListOkoPermisosPruebaNombre)', $fila->pagina_nombre);
        $this->assertSame('ListOkoPermisosPruebaNombre', $fila->pagina, 'el campo pagina sigue siendo el real');
    }

    /**
     * @description
     * **El ajuste no puede pisar el grupo `okopermisos`**, que lo leen otros plugins: `EditSettings` toma el
     * grupo del nombre del fichero (`Settings` + sufijo, en minúsculas), y el de este plugin es otro.
     */
    public function testElGrupoDeAjustesEsPropio(): void
    {
        $fichero = 'SettingsOkoPermisosPrefijos';
        $this->assertFileExists(Tools::folder('Dinamic', 'XMLView', $fichero . '.xml'), 'el deploy no copió la pestaña de ajustes');
        $this->assertSame(NombreDePagina::AJUSTES, strtolower(substr($fichero, strlen('Settings'))));
        $this->assertNotSame('okopermisos', NombreDePagina::AJUSTES);
    }

    /**
     * @description
     * **Sin prefijo de la lista queda tal cual**, y un prefijo sólo cuenta si detrás viene una mayúscula:
     * `NewsletterOkoPermisosPrueba` no es «New» + «sletter».
     */
    public function testSinPrefijoQuedaTalCual(): void
    {
        $this->assertSame('PanelOkoPermisosPrueba', $this->fila('PanelOkoPermisosPrueba')->pagina_nombre);
        $this->assertSame('NewsletterOkoPermisosPrueba', $this->fila('NewsletterOkoPermisosPrueba')->pagina_nombre);
    }

    private function fila(string $pagina): PermisoEfectivo
    {
        $filas = (new PermisoEfectivo())->all([Where::eq('e.nick', 'okonombre1'), Where::eq('e.pagename', $pagina)], [], 0, 0);
        $this->assertCount(1, $filas, 'no hay fila para ' . $pagina);
        return $filas[0];
    }

    protected function setUp(): void
    {
        foreach (['ListOkoPermisosPruebaNombre', 'PanelOkoPermisosPrueba', 'NewsletterOkoPermisosPrueba'] as $nombre) {
            $pagina = new Page();
            $pagina->name = $nombre;
            $pagina->title = $nombre;
            $pagina->menu = 'okopermprueba';
            $this->assertTrue($pagina->save(), 'no se pudo sembrar la página ' . $nombre);
        }

        $admin = new User();
        $admin->nick = 'okonombre1';
        $admin->email = 'okonombre1@okopermisos.test';
        $this->assertTrue($admin->setPassword('okopermisos1-okonombre1'));
        $admin->admin = true;
        $this->assertTrue($admin->save());
    }

    protected function tearDown(): void
    {
        // el ajuste sólo se tocó en memoria: se descarta sin guardarlo
        Tools::settingsClear();

        $db = new DataBase();
        $db->connect();
        $db->exec('DELETE FROM users WHERE nick LIKE \'okonombre%\';');
        $db->exec('DELETE FROM pages WHERE name LIKE \'%OkoPermisosPrueba%\';');

        $this->logErrors();
    }
}
