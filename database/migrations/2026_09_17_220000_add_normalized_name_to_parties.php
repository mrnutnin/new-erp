<?php

use App\Support\PartyNameNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void
    {
        Schema::table('parties',function(Blueprint $table):void{$table->string('normalized_name')->nullable()->after('name')->index();});
        DB::table('parties')->select(['id','name'])->orderBy('id')->chunkById(500,function($parties):void{foreach($parties as $party)DB::table('parties')->where('id',$party->id)->update(['normalized_name'=>PartyNameNormalizer::normalize($party->name)]);});
    }

    public function down():void
    {
        Schema::table('parties',function(Blueprint $table):void{$table->dropIndex(['normalized_name']);$table->dropColumn('normalized_name');});
    }
};
