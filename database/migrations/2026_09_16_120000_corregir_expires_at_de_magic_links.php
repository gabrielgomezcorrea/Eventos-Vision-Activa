<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * MySQL le pone `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` a la
 * primera columna TIMESTAMP no nula de una tabla. En `magic_links` eso reescribía
 * `expires_at` a "ahora" cada vez que se tocaba la fila, así que el enlace del
 * cliente nacía vencido. SQLite no hace eso: el bug solo aparecía en producción.
 *
 * DATETIME no tiene esa magia y guarda el mismo valor.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE magic_links MODIFY expires_at DATETIME NOT NULL');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE magic_links MODIFY expires_at TIMESTAMP NOT NULL');
        }
    }
};
