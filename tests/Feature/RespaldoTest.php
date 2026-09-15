<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Un respaldo solo vale si se puede restaurar: se respalda, se pierden datos y
 * archivos, y se recuperan. Corre sobre una base y un storage temporales.
 */
class RespaldoTest extends TestCase
{
    private string $temporal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporal = sys_get_temp_dir().'/respaldo-test-'.uniqid();
        File::ensureDirectoryExists($this->temporal.'/storage/app/private/documentos');
        $this->app->useStoragePath($this->temporal.'/storage');

        touch($this->temporal.'/base.sqlite');
        config([
            'database.connections.sqlite.database' => $this->temporal.'/base.sqlite',
            'respaldos.carpeta' => $this->temporal.'/respaldos',
        ]);
        DB::purge('sqlite');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->temporal);

        parent::tearDown();
    }

    public function test_se_recuperan_la_base_y_los_archivos_perdidos(): void
    {
        Schema::create('orders', fn ($tabla) => $tabla->string('number'));
        DB::table('orders')->insert(['number' => 'ORD-0001']);
        $comprobante = storage_path('app/private/documentos/transferencia.pdf');
        File::put($comprobante, 'contenido');

        $this->artisan('respaldo:crear')->assertSuccessful();
        $respaldo = File::glob($this->temporal.'/respaldos/respaldo-*.tar.gz')[0];

        DB::table('orders')->delete();
        File::delete($comprobante);

        $this->artisan('respaldo:restaurar', ['archivo' => $respaldo, '--force' => true])->assertSuccessful();

        $this->assertSame(['ORD-0001'], DB::table('orders')->pluck('number')->all());
        $this->assertSame('contenido', File::get($comprobante));
    }

    public function test_conserva_solo_los_mas_recientes(): void
    {
        config(['respaldos.conservar' => 2]);
        File::ensureDirectoryExists($this->temporal.'/respaldos');
        File::put($this->temporal.'/respaldos/respaldo-20260101-030000.tar.gz', '');
        File::put($this->temporal.'/respaldos/respaldo-20260102-030000.tar.gz', '');

        $this->artisan('respaldo:crear')->assertSuccessful();

        $restantes = array_map('basename', File::glob($this->temporal.'/respaldos/respaldo-*.tar.gz'));
        $this->assertCount(2, $restantes);
        $this->assertNotContains('respaldo-20260101-030000.tar.gz', $restantes);
    }
}
