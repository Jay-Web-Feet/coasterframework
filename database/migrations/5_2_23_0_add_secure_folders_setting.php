<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class AddSecureFoldersSetting extends Migration
{

    /**
     * Make changes to the database.
     *
     * @return void
     */
    public function up()
    {

        $date = new \DateTime;

        DB::table('settings')->insert(
            array(
                array(
                    'label' => 'Secure Upload Folders',
                    'name' => 'site.secure_folders',
                    'value' => 'secure',
                    'editable' => 1,
                    'hidden' => 0,
                    'created_at' => $date,
                    'updated_at' => $date
                )
            )
        );

    }

    /**
     * Revert the changes to the database.
     *
     * @return void
     */
    public function down()
    {

    }

}