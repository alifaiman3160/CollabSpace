<?php

use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Account\AccountController;
use App\Http\Controllers\Auth\ForcePasswordChangeController;
use App\Http\Controllers\Lecturer\DashboardController as LecturerDashboardController;
use App\Http\Controllers\Lecturer\GroupController;
use App\Http\Controllers\Lecturer\ProgressController;
use App\Http\Controllers\Lecturer\ReportController;
use App\Http\Controllers\Lecturer\StudentController as LecturerStudentController;
use App\Http\Controllers\Lecturer\SubjectController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Student\BoardController;
use App\Http\Controllers\Student\ChatController;
use App\Http\Controllers\Student\DashboardController as StudentDashboardController;
use App\Http\Controllers\Student\DocumentController;
use App\Http\Controllers\Student\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
});

/*
| Forced password change — sits outside the 'password.changed' middleware so a
| user carrying must_change_password can actually reach it.
*/
Route::middleware('auth')->group(function () {
    Route::get('password/change', [ForcePasswordChangeController::class, 'edit'])->name('password.change');
    Route::put('password/change', [ForcePasswordChangeController::class, 'update'])->name('password.change.update');
});

Route::middleware(['auth', 'password.changed'])->group(function () {

    /*
    | One /dashboard entry point that dispatches on role. Breeze and the auth
    | flow all redirect here, so each role lands on its own screen without
    | every redirect having to know the role.
    */
    Route::get('/dashboard', function () {
        return match (auth()->user()->role) {
            'admin' => redirect()->route('admin.dashboard'),
            'lecturer' => redirect()->route('lecturer.dashboard'),
            default => redirect()->route('student.dashboard'),
        };
    })->name('dashboard');

    /*
    | Student area. Every route resolves through the student's own memberships
    | — see StudentController::ownedGroup(), which 404s on any other group.
    */
    Route::middleware('role:student')->prefix('student')->name('student.')->group(function () {
        Route::get('dashboard', [StudentDashboardController::class, 'index'])->name('dashboard');

        Route::get('workspace', [WorkspaceController::class, 'index'])->name('workspace');
        Route::get('workspace/{group}', [WorkspaceController::class, 'show'])->name('workspace.show');

        Route::get('board', [BoardController::class, 'index'])->name('board');
        Route::get('board/{group}', [BoardController::class, 'show'])->name('board.show');

        Route::get('chat', [ChatController::class, 'index'])->name('chat');
        Route::get('chat/g/{group}', [ChatController::class, 'show'])->name('chat.show');
        Route::get('chat/f/{message}/{token}', [ChatController::class, 'file'])->name('chat.file');

        Route::get('notifications', [NotificationController::class, 'index'])->name('notifications');

        Route::get('documents', [DocumentController::class, 'index'])->name('documents');
        Route::get('documents/g/{group}', [DocumentController::class, 'group'])->name('documents.group');
        Route::post('documents/g/{group}', [DocumentController::class, 'store'])->name('documents.store');
        Route::get('documents/d/{document}', [DocumentController::class, 'edit'])->name('documents.edit');
        Route::put('documents/d/{document}', [DocumentController::class, 'update'])->name('documents.update');
        Route::delete('documents/d/{document}', [DocumentController::class, 'destroy'])->name('documents.destroy');
        Route::get('documents/d/{document}/v/{version}', [DocumentController::class, 'version'])->name('documents.version');
        Route::post('documents/d/{document}/v/{version}/restore', [DocumentController::class, 'restore'])->name('documents.restore');
    });

    /*
    | My account, shared by all three roles. Replaces Breeze's profile
    | screens, which have been removed.
    */
    Route::get('/account', [AccountController::class, 'edit'])->name('account');
    Route::patch('/account', [AccountController::class, 'update'])->name('account.update');
    Route::put('/account/password', [AccountController::class, 'password'])->name('account.password');
});

/*
| Administrator area. Only the admin creates accounts — there is no
| self-registration anywhere in CollabSpace.
*/
Route::middleware(['auth', 'password.changed', 'role:admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('dashboard', [AdminDashboardController::class, 'index'])->name('dashboard');

        Route::get('notifications', [NotificationController::class, 'index'])->name('notifications');

        Route::get('users', [AdminUserController::class, 'index'])->name('users');
        Route::get('users/create', [AdminUserController::class, 'create'])->name('users.create');
        Route::post('users', [AdminUserController::class, 'store'])->name('users.store');
        Route::get('users/{user}/edit', [AdminUserController::class, 'edit'])->name('users.edit');
        Route::put('users/{user}', [AdminUserController::class, 'update'])->name('users.update');
        Route::put('users/{user}/status', [AdminUserController::class, 'toggleStatus'])->name('users.status');
        Route::put('users/{user}/password', [AdminUserController::class, 'resetPassword'])->name('users.password');
    });

/*
| Lecturer area. Every route here resolves through the signed-in lecturer's own
| subjects — see LecturerController::ownedSubject()/ownedGroup(), which 404 on
| anything belonging to another lecturer.
*/
Route::middleware(['auth', 'password.changed', 'role:lecturer'])
    ->prefix('lecturer')
    ->name('lecturer.')
    ->group(function () {
        Route::get('dashboard', [LecturerDashboardController::class, 'index'])->name('dashboard');

        Route::get('notifications', [NotificationController::class, 'index'])->name('notifications');

        // Students across the lecturer's own subjects
        Route::get('students', [LecturerStudentController::class, 'index'])->name('students');

        // Monitor progress — read-only per-group detail
        Route::get('progress', [ProgressController::class, 'index'])->name('progress');
        Route::get('progress/{group}', [ProgressController::class, 'show'])->name('progress.show');

        // My Subjects
        Route::get('subjects', [SubjectController::class, 'index'])->name('subjects');
        Route::get('subjects/create', [SubjectController::class, 'create'])->name('subjects.create');
        Route::post('subjects', [SubjectController::class, 'store'])->name('subjects.store');
        Route::get('subjects/{subject}/edit', [SubjectController::class, 'edit'])->name('subjects.edit');
        Route::put('subjects/{subject}', [SubjectController::class, 'update'])->name('subjects.update');
        Route::delete('subjects/{subject}', [SubjectController::class, 'destroy'])->name('subjects.destroy');

        // Contribution reports
        Route::get('reports', [ReportController::class, 'index'])->name('reports');
        Route::get('reports/{group}', [ReportController::class, 'show'])->name('reports.show');
        Route::get('reports/{group}/export', [ReportController::class, 'export'])->name('reports.export');

        // Groups
        Route::get('groups', [GroupController::class, 'index'])->name('groups');
        Route::get('groups/create', [GroupController::class, 'create'])->name('groups.create');
        Route::post('groups', [GroupController::class, 'store'])->name('groups.store');
        Route::get('groups/{group}', [GroupController::class, 'show'])->name('groups.show');
        Route::delete('groups/{group}', [GroupController::class, 'destroy'])->name('groups.destroy');
        Route::post('groups/{group}/members', [GroupController::class, 'addMember'])->name('groups.members.store');
        Route::delete('groups/{group}/members/{user}', [GroupController::class, 'removeMember'])->name('groups.members.destroy');
    });

require __DIR__.'/auth.php';
