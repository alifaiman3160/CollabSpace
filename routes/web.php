<?php

use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Account\AccountController;
use App\Http\Controllers\Auth\ForcePasswordChangeController;
use App\Http\Controllers\Lecturer\ClassListController;
use App\Http\Controllers\Lecturer\DashboardController as LecturerDashboardController;
use App\Http\Controllers\Lecturer\AnnouncementController;
use App\Http\Controllers\Lecturer\BalancedFormationController;
use App\Http\Controllers\Lecturer\DocumentController as LecturerDocumentController;
use App\Http\Controllers\Lecturer\FormationController;
use App\Http\Controllers\Lecturer\GroupController;
use App\Http\Controllers\Lecturer\ImportFormationController;
use App\Http\Controllers\Lecturer\MarkingController;
use App\Http\Controllers\Lecturer\RubricController;
use App\Http\Controllers\Lecturer\ProgressController;
use App\Http\Controllers\Lecturer\ReportController;
use App\Http\Controllers\Lecturer\StudentController as LecturerStudentController;
use App\Http\Controllers\Lecturer\SubjectController;
use App\Http\Controllers\DocumentImageController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Student\AnnouncementController as StudentAnnouncementController;
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
    // A picture inside a document. Shared by every role that can read documents; the controller decides who.
    Route::get('documents/images/{image}', [DocumentImageController::class, 'show'])->name('documents.images.show');

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

        Route::post('announcements/read', [StudentAnnouncementController::class, 'read'])->name('announcements.read');

        Route::get('documents', [DocumentController::class, 'index'])->name('documents');
        Route::get('documents/g/{group}', [DocumentController::class, 'group'])->name('documents.group');
        Route::post('documents/g/{group}', [DocumentController::class, 'store'])->name('documents.store');
        Route::get('documents/d/{document}', [DocumentController::class, 'edit'])->name('documents.edit');
        Route::put('documents/d/{document}', [DocumentController::class, 'update'])->name('documents.update');
        Route::delete('documents/d/{document}', [DocumentController::class, 'destroy'])->name('documents.destroy');
        Route::get('documents/d/{document}/pdf', [DocumentController::class, 'pdf'])->name('documents.pdf');
        Route::post('documents/d/{document}/images', [DocumentController::class, 'storeImage'])->name('documents.images.store');
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
        Route::get('students/suggestions', [LecturerStudentController::class, 'suggestions'])->middleware('throttle:60,1')->name('students.suggestions');

        // Monitor progress — read-only per-group detail
        Route::get('progress', [ProgressController::class, 'index'])->name('progress');
        Route::get('progress/{group}', [ProgressController::class, 'show'])->name('progress.show');

        // The group's documents, read-only. No write verbs here on purpose —
        // a lecturer reads the group's writing, they do not edit it.
        // They are sub-pages of Monitor progress (so the sidebar stays on it, and the address says so).
        Route::get('progress/{group}/documents', [LecturerDocumentController::class, 'index'])->name('progress.documents');
        Route::get('progress/{group}/documents/{document}', [LecturerDocumentController::class, 'show'])->withTrashed()->whereNumber('document')->name('progress.documents.show');
        Route::get('progress/{group}/documents/{document}/v/{version}', [LecturerDocumentController::class, 'version'])->withTrashed()->whereNumber(['document', 'version'])->name('progress.documents.version');

        // The old addresses still work: they send the lecturer (who must own the group) to the new ones.
        Route::get('groups/{group}/documents', [LecturerDocumentController::class, 'legacyIndex'])->name('documents');
        Route::get('documents/{document}', [LecturerDocumentController::class, 'legacyShow'])->withTrashed()->name('documents.show');
        Route::get('documents/{document}/v/{version}', [LecturerDocumentController::class, 'legacyVersion'])->withTrashed()->name('documents.version');

        // My Subjects
        Route::get('subjects', [SubjectController::class, 'index'])->name('subjects');
        Route::get('subjects/create', [SubjectController::class, 'create'])->name('subjects.create');
        Route::post('subjects', [SubjectController::class, 'store'])->name('subjects.store');
        Route::get('subjects/{subject}/edit', [SubjectController::class, 'edit'])->name('subjects.edit');
        Route::put('subjects/{subject}', [SubjectController::class, 'update'])->name('subjects.update');
        Route::delete('subjects/{subject}', [SubjectController::class, 'destroy'])->name('subjects.destroy');

        // Announcements for a subject
        Route::get('subjects/{subject}/announcements', [AnnouncementController::class, 'index'])->name('announcements');
        Route::post('subjects/{subject}/announcements', [AnnouncementController::class, 'store'])->name('announcements.store');
        Route::get('subjects/{subject}/announcements/{announcement}/edit', [AnnouncementController::class, 'edit'])->whereNumber('announcement')->name('announcements.edit');
        Route::put('subjects/{subject}/announcements/{announcement}', [AnnouncementController::class, 'update'])->whereNumber('announcement')->name('announcements.update');
        Route::delete('subjects/{subject}/announcements/{announcement}', [AnnouncementController::class, 'destroy'])->whereNumber('announcement')->name('announcements.destroy');

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

        /*
        | Group formation. Two ways of forming groups for a subject, and a screen
        | to choose between them. Nothing here writes a group until the one
        | confirm route at the end of each flow, and every route resolves the
        | subject through the signed-in lecturer's own subjects.
        */
        // Read-only class list of a subject: enrolled students by section (enrolment itself is the administrator's).
        Route::get('subjects/{subject}/class-list', [ClassListController::class, 'show'])->name('classlist');

        Route::get('subjects/{subject}/formation', [FormationController::class, 'index'])->name('formation');

        // Mode 1: balanced allocation by CGPA
        Route::post('subjects/{subject}/formation/balanced', [BalancedFormationController::class, 'preview'])->name('formation.balanced.preview');
        Route::get('subjects/{subject}/formation/balanced/{token}', [BalancedFormationController::class, 'show'])->whereAlphaNumeric('token')->name('formation.balanced.show');
        Route::post('subjects/{subject}/formation/balanced/{token}/regenerate', [BalancedFormationController::class, 'regenerate'])->whereAlphaNumeric('token')->name('formation.balanced.regenerate');
        Route::post('subjects/{subject}/formation/balanced/{token}/confirm', [BalancedFormationController::class, 'confirm'])->whereAlphaNumeric('token')->name('formation.balanced.confirm');

        // Mode 2: import self-selected groups from a Google Form CSV
        Route::get('subjects/{subject}/formation/import', [ImportFormationController::class, 'create'])->name('formation.import');
        Route::get('subjects/{subject}/formation/import/template', [ImportFormationController::class, 'template'])->name('formation.import.template');
        Route::post('subjects/{subject}/formation/import', [ImportFormationController::class, 'store'])->name('formation.import.store');
        Route::get('subjects/{subject}/formation/import/{token}/columns', [ImportFormationController::class, 'columns'])->whereAlphaNumeric('token')->name('formation.import.columns');
        Route::post('subjects/{subject}/formation/import/{token}/columns', [ImportFormationController::class, 'mapColumns'])->whereAlphaNumeric('token')->name('formation.import.map');
        Route::get('subjects/{subject}/formation/import/{token}', [ImportFormationController::class, 'review'])->whereAlphaNumeric('token')->name('formation.import.review');
        Route::post('subjects/{subject}/formation/import/{token}', [ImportFormationController::class, 'decide'])->whereAlphaNumeric('token')->name('formation.import.decide');
        Route::post('subjects/{subject}/formation/import/{token}/confirm', [ImportFormationController::class, 'confirm'])->whereAlphaNumeric('token')->name('formation.import.confirm');
        Route::delete('subjects/{subject}/formation/import/{token}', [ImportFormationController::class, 'discard'])->whereAlphaNumeric('token')->name('formation.import.discard');

        /*
        | Marking rubrics. Upload an .xlsx, review what was read, then save it as
        | the next version. Nothing is written until the review is confirmed.
        */
        Route::get('subjects/{subject}/rubrics', [RubricController::class, 'index'])->name('rubrics');
        Route::post('subjects/{subject}/rubrics', [RubricController::class, 'store'])->name('rubrics.store');
        Route::get('subjects/{subject}/rubrics/import/{token}', [RubricController::class, 'review'])->whereAlphaNumeric('token')->name('rubrics.review');
        Route::post('subjects/{subject}/rubrics/import/{token}', [RubricController::class, 'confirm'])->whereAlphaNumeric('token')->name('rubrics.confirm');
        Route::delete('subjects/{subject}/rubrics/import/{token}', [RubricController::class, 'discard'])->whereAlphaNumeric('token')->name('rubrics.discard');
        Route::get('subjects/{subject}/rubrics/{rubric}', [RubricController::class, 'show'])->whereNumber('rubric')->name('rubrics.show');
        /*
        | Marking. Suggestions beside the lecturer's own choices, and an approval that
        | freezes a student's marks. Nothing here is ever shown to a student.
        */
        Route::get('marking', [MarkingController::class, 'index'])->name('marking');
        Route::get('marking/{rubric}/groups/{group}', [MarkingController::class, 'group'])->whereNumber(['rubric', 'group'])->name('marking.group');
        Route::get('marking/{rubric}/groups/{group}/students/{student}', [MarkingController::class, 'student'])->whereNumber(['rubric', 'group', 'student'])->name('marking.student');
        Route::post('marking/{rubric}/groups/{group}/students/{student}', [MarkingController::class, 'save'])->whereNumber(['rubric', 'group', 'student'])->name('marking.save');
        Route::post('marking/{rubric}/groups/{group}/students/{student}/accept', [MarkingController::class, 'accept'])->whereNumber(['rubric', 'group', 'student'])->name('marking.accept');
        Route::post('marking/{rubric}/groups/{group}/accept', [MarkingController::class, 'acceptAll'])->whereNumber(['rubric', 'group'])->name('marking.accept_all');
        Route::post('marking/{rubric}/groups/{group}/students/{student}/approval', [MarkingController::class, 'approve'])->whereNumber(['rubric', 'group', 'student'])->name('marking.approve');
        Route::delete('marking/{rubric}/groups/{group}/students/{student}/approval', [MarkingController::class, 'revoke'])->whereNumber(['rubric', 'group', 'student'])->name('marking.revoke');
        // One student per request, so no request carries a whole group's provider calls.
        Route::post('marking/{rubric}/groups/{group}/students/{student}/suggestion', [MarkingController::class, 'suggestOne'])->whereNumber(['rubric', 'group', 'student'])->name('marking.suggest');
        Route::get('marking/{rubric}/groups/{group}/export', [MarkingController::class, 'export'])->whereNumber(['rubric', 'group'])->name('marking.export');

        Route::put('subjects/{subject}/rubrics/{rubric}/evidence', [RubricController::class, 'updateEvidence'])->whereNumber('rubric')->name('rubrics.evidence');
        Route::delete('subjects/{subject}/rubrics/{rubric}', [RubricController::class, 'destroy'])->whereNumber('rubric')->name('rubrics.destroy');
    });

require __DIR__.'/auth.php';
