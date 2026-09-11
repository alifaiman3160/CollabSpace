<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        // One pass over users for the whole stat row, rather than seven counts.
        // CASE rather than MySQL's boolean-sum shorthand, so it also runs on SQLite.
        $counts = User::selectRaw("
            COUNT(*) AS total,
            SUM(CASE WHEN role = 'student'  THEN 1 ELSE 0 END) AS students,
            SUM(CASE WHEN role = 'lecturer' THEN 1 ELSE 0 END) AS lecturers,
            SUM(CASE WHEN role = 'admin'    THEN 1 ELSE 0 END) AS admins,
            SUM(CASE WHEN is_active = 1     THEN 1 ELSE 0 END) AS active,
            SUM(CASE WHEN is_active = 0     THEN 1 ELSE 0 END) AS inactive,
            SUM(CASE WHEN must_change_password = 1 THEN 1 ELSE 0 END) AS pending
        ")->first();

        return view('admin.dashboard', [
            'counts' => $counts,
            'recentUsers' => User::latest()->take(8)->get(),
        ]);
    }
}
