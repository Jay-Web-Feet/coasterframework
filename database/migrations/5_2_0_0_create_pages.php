<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreatePages
{

    /**
     * Make changes to the database.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('pages', function ($table) {
            $table->create();
            $table->increments('id');
            $table->integer('template')->default(0);
            $table->integer('parent')->default(0);
            $table->integer('child_template')->default(0);
            $table->integer('order')->default(0);
            $table->integer('group_container')->default(0);
            $table->integer('in_group')->default(0);
            $table->integer('link')->default(0);
            $table->integer('live')->default(1);
            $table->timestamp('live_start')->nullable();
            $table->timestamp('live_end')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Revert the changes to the database.
     *
     * @return void
     */
    public function down()
    {
        //
    }

}