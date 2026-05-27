<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class CreatePlansTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up() 
    {
        Schema::create('type_plans', function(Blueprint $table) {
            $table->id(); // PRIMARY KEY añadida
            $table->unsignedBigInteger('plan_id')->unique(); // Cambiado de 'id' a 'plan_id'
            $table->string('name')->unique();
            $table->unsignedBigInteger('qty_docs_invoice')->default(0);
            $table->unsignedBigInteger('qty_docs_payroll')->default(0);
            $table->unsignedBigInteger('qty_docs_radian')->default(0);
            $table->unsignedBigInteger('qty_docs_ds')->default(0);
            $table->unsignedBigInteger('period')->default(0);
            $table->boolean('state')->default(true);
            $table->string('observations')->nullable();
            $table->timestamps();
        });

        // Insertar datos manualmente
        $data = [
            [
                'plan_id' => 0,  // Cambiado de 'id' a 'plan_id'
                'name' => 'Default - No Plan - Unlimited',
                'qty_docs_invoice' => 0,
                'qty_docs_payroll' => 0,
                'qty_docs_radian' => 0,
                'qty_docs_ds' => 0,
                'period' => 0,
                'state' => true,
                'observations' => 'Default - No Plan - Unlimited',
                'created_at' => now(),
                'updated_at' => now()
            ]
        ];

        foreach ($data as $row) {
            DB::table('type_plans')->insert($row);
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down() 
    {
        Schema::dropIfExists('type_plans');
    }
}