<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Flag kooperatif untuk "kill proses": worker scan/import mengecek
     * flag ini tiap checkpoint dan berhenti aman bila diminta, sehingga
     * tidak ada ghost proses yang membalik status setelah di-kill.
     */
    public function up(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            $table->boolean('cancel_requested')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            $table->dropColumn('cancel_requested');
        });
    }
};
