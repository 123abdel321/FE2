<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class RegularizeDataToPayrollTypeDocumentIdentifications extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $table_name = 'payroll_type_document_identifications';
        
        $exist_records = DB::table($table_name)->count();

        if($exist_records > 0) {
            DB::table($table_name)->delete();
        }

        // Insertar datos manualmente según el archivo CSV
        $data = [
            ['id' => 1, 'name' => 'Cédula de ciudadanía', 'code' => '01'],
            ['id' => 2, 'name' => 'Tarjeta de identidad', 'code' => '02'],
            ['id' => 3, 'name' => 'Cédula de extranjería', 'code' => '03'],
            ['id' => 4, 'name' => 'Nit', 'code' => '04'],
            ['id' => 5, 'name' => 'Pasaporte', 'code' => '05'],
            ['id' => 6, 'name' => 'Tipo de documento extranjero', 'code' => '06'],
            ['id' => 7, 'name' => 'Sin identificación del exterior', 'code' => '07'],
            ['id' => 8, 'name' => 'Permiso especial de permanencia', 'code' => '08'],
            ['id' => 9, 'name' => 'Permiso por protección temporal', 'code' => '09'],
            ['id' => 10, 'name' => 'Número único personal - NUP', 'code' => '10'],
            ['id' => 11, 'name' => 'Número único de identificación personal - NUIP', 'code' => '11'],
            ['id' => 12, 'name' => 'Documento de identificación válido', 'code' => '12'],
        ];

        foreach ($data as $row) {
            DB::table($table_name)->insert([
                'id' => $row['id'],
                'name' => $row['name'],
                'code' => $row['code'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // Opcional: limpiar los datos si se revierte la migración
        DB::table('payroll_type_document_identifications')->truncate();
    }
}