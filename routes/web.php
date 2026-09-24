<?php

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\JenisTarifController;
use App\Http\Controllers\OrganizationTypeController;
use App\Http\Controllers\OrganizationUnitController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\ProviderController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\ServiceClassController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\TarifController;
use App\Http\Controllers\TarifImportController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will be
| assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return redirect('/login');
});

// Authentication routes
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register']);
});

// Protected routes
Route::middleware('auth')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    // Profile routes
    Route::get('/profile', [App\Http\Controllers\ProfileController::class, 'index'])->name('profile.index');

    // User Management routes
    Route::middleware('permission:manage_users')->group(function () {
        Route::resource('users', UserController::class);
    });

    // Audit Log routes
    Route::middleware('permission:view_activity_logs')->group(function () {
        Route::get('activity-logs', [ActivityLogController::class, 'index'])->name('activity-logs.index');
        Route::get('activity-logs/{activityLog}', [ActivityLogController::class, 'show'])->name('activity-logs.show');
        Route::get('activity-logs/subject/{type}/{id}', [ActivityLogController::class, 'forSubject'])->name('activity-logs.subject');
    });

    // Role Management routes
    Route::middleware('permission:manage_roles')->group(function () {
        Route::resource('roles', RoleController::class);
    });

    // Permission Management routes
    Route::middleware('permission:manage_permissions')->group(function () {
        Route::resource('permissions', PermissionController::class);
    });

    // Organization Type Management routes
    Route::middleware('permission:manage_organization_types')->group(function () {
        Route::resource('organization-types', OrganizationTypeController::class);
    });

    // Organization Unit Management routes
    Route::middleware('permission:manage_organization_units')->group(function () {
        Route::resource('organization-units', OrganizationUnitController::class);

        // Member management routes
        Route::post('organization-units/{organization_unit}/members', [OrganizationUnitController::class, 'addMember'])
            ->name('organization-units.add-member');
        Route::delete('organization-units/{organization_unit}/members/{user}', [OrganizationUnitController::class, 'removeMember'])
            ->name('organization-units.remove-member');
        Route::patch('organization-units/{organization_unit}/head', [OrganizationUnitController::class, 'updateHead'])
            ->name('organization-units.update-head');
    });

    // Tarif Master Data routes (Jenis Tarif, Provider, Service, Kelas)
    Route::middleware('permission:manage_tarif_masters')->group(function () {
        Route::resource('jenis-tarifs', JenisTarifController::class);
        Route::resource('providers', ProviderController::class);
        Route::resource('services', ServiceController::class);
        Route::resource('classes', ServiceClassController::class)->parameters(['classes' => 'serviceClass']);
    });

    // Tarif Management routes
    Route::middleware('permission:manage_tarifs')->group(function () {
        Route::get('tarifs/import', [TarifImportController::class, 'index'])->name('tarif-import.index');
        Route::post('tarifs/import/scan', [TarifImportController::class, 'scan'])->name('tarif-import.scan');
        Route::post('tarifs/import/commit', [TarifImportController::class, 'commit'])->name('tarif-import.commit');
        Route::get('tarifs/import/batches', [TarifImportController::class, 'batches'])->name('tarif-import.batches');
        Route::get('tarifs/import/batches/status', [TarifImportController::class, 'batchStatus'])->name('tarif-import.batches.status');
        Route::get('tarifs/import/batches/{batch}/preview', [TarifImportController::class, 'preview'])->name('tarif-import.batches.preview')->whereNumber('batch');
        Route::get('tarifs/import/batches/{batch}', [TarifImportController::class, 'show'])->name('tarif-import.batches.show')->whereNumber('batch');
        Route::post('tarifs/import/batches/{batch}/retry-scan', [TarifImportController::class, 'retryScan'])->name('tarif-import.batches.retry-scan')->whereNumber('batch');
        Route::post('tarifs/import/batches/{batch}/retry', [TarifImportController::class, 'retryBatch'])->name('tarif-import.batches.retry')->whereNumber('batch');
        Route::delete('tarifs/import/batches/{batch}', [TarifImportController::class, 'destroyBatch'])->name('tarif-import.batches.destroy')->whereNumber('batch');
        Route::get('tarifs/import/template', [TarifImportController::class, 'template'])->name('tarif-import.template');
        Route::get('tarifs/export-xlsx', [TarifImportController::class, 'exportXlsx'])->name('tarif-import.export-xlsx');
        Route::get('tarifs/export', [TarifController::class, 'export'])->name('tarifs.export');
        Route::get('bridge', [\App\Http\Controllers\BridgeTarifController::class, 'index'])->name('bridge.index');
        Route::post('bridge/scan', [\App\Http\Controllers\BridgeTarifController::class, 'scan'])->name('bridge.scan');
        Route::post('bridge/resolve', [\App\Http\Controllers\BridgeTarifController::class, 'resolveMapping'])->name('bridge.resolve');
        Route::post('bridge/resolve-batch', [\App\Http\Controllers\BridgeTarifController::class, 'resolveBatch'])->name('bridge.resolve-batch');
        Route::get('bridge/result/{token}', [\App\Http\Controllers\BridgeTarifController::class, 'showResult'])->name('bridge.result')->where('token', '[A-Za-z0-9]{16,64}');
        Route::post('bridge/generate', [\App\Http\Controllers\BridgeTarifController::class, 'generate'])->name('bridge.generate');
        Route::get('bridge/search-services', [\App\Http\Controllers\BridgeTarifController::class, 'searchServices'])->name('bridge.search-services');
        Route::get('bridge/download/{token}', [\App\Http\Controllers\BridgeTarifController::class, 'download'])->name('bridge.download');
        Route::resource('tarifs', TarifController::class);
    });

});
