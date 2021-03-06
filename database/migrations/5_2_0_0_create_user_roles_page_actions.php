<?php

use Illuminate\Support\Facades\Schema;

class CreateUserRolesPageActions
{

    /**
     * Make changes to the database.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('user_roles_page_actions', function ($table) {
            $table->create();
            $table->increments('id');
            $table->integer('role_id');
            $table->integer('page_id');
            $table->integer('action_id');
            $table->enum('access', array('allow', 'deny'));
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