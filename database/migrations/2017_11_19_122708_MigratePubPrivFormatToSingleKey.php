<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Contracts\Encryption\DecryptException;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::transaction(function () {
            DB::table('api_keys')->get()->each(function ($item) {
                try {
                    $decrypted = Crypt::decrypt($item->secret);
                } catch (DecryptException $exception) {
                    $decrypted = str_random(32);
                } finally {
                    DB::table('api_keys')->where('id', $item->id)->update([
                        'secret' => $decrypted,
                    ]);
                }
            });
        });

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            Schema::table('api_keys', function (Blueprint $table) {
                $table->dropColumn('public');
                $table->char('secret', 32)->change();
                $table->renameColumn('secret', 'token');
                $table->string('token', 32)->unique()->change();
            });
        } else {
            DB::statement('ALTER TABLE `api_keys` CHANGE `secret` `token` CHAR(32) NOT NULL, ADD UNIQUE INDEX `api_keys_token_unique` (`token`(32))');
        }

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE `api_keys` CHANGE `token` `secret` TEXT, DROP INDEX `api_keys_token_unique`');

        Schema::table('api_keys', function (Blueprint $table) {
            $table->char('public', 16)->after('user_id');
        });

        DB::transaction(function () {
            DB::table('api_keys')->get()->each(function ($item) {
                DB::table('api_keys')->where('id', $item->id)->update([
                    'public' => str_random(16),
                    'secret' => Crypt::encrypt($item->secret),
                ]);
            });
        });
    }
};
