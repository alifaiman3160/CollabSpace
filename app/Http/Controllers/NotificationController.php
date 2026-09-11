<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

/**
 * The notifications screen is identical for all three roles — the rows are
 * already scoped to the signed-in user — so one controller serves every role's
 * route and the shell supplies the role-appropriate nav.
 */
class NotificationController extends Controller
{
    public function index(): View
    {
        return view('notifications.index');
    }
}
