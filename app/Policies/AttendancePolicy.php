<?php

namespace App\Policies;

use App\Models\Attendance;
use App\Models\Project;
use App\Models\User;

class AttendancePolicy
{
    /**
     * LIST ATTENDANCE (INDEX)
     * User bisa lihat attendance yang relevant
     */
    public function viewAny(User $user, ?Project $project = null): bool
    {
        // DEV bisa lihat semua attendance
        if ($user->role === 'dev') {
            return true;
        }

        // HO hanya attendance dalam organization miliknya
        if ($user->role === 'ho') {
            return true; // HO bisa lihat semua dalam organization
        }

        // Admin project hanya attendance di project miliknya
        if ($user->role === 'admin_project') {
            return true; // Admin project bisa lihat semua di project
        }

        // Komandan regu dan anggota hanya lihat milik sendiri
        if (in_array($user->role, ['komandan_regu', 'anggota'])) {
            return true; // Bisa lihat milik sendiri
        }

        return false;
    }

    /**
     * VIEW SPECIFIC ATTENDANCE
     */
    public function view(User $user, Attendance $attendance): bool
    {
        // DEV bisa lihat semua
        if ($user->role === 'dev') {
            return true;
        }

        // HO bisa lihat attendance dalam organization miliknya
        if ($user->role === 'ho') {
            return $user->organization_id === $attendance->project->organization_id;
        }

        // Admin project bisa lihat attendance di project miliknya
        if ($user->role === 'admin_project') {
            return $user->project_id === $attendance->project_id;
        }

        // Komandan regu bisa lihat attendance dalam project
        if ($user->role === 'komandan_regu') {
            return $user->project_id === $attendance->project_id;
        }

        // Anggota hanya bisa lihat attendance milik sendiri
        if ($user->role === 'anggota') {
            return $user->id === $attendance->user_id;
        }

        return false;
    }

    /**
     * CREATE ATTENDANCE (CHECK-IN)
     * Hanya user yang punya schedule bisa check-in
     */
    public function create(User $user): bool
    {
        // Komandan regu, anggota, dan admin_project bisa check-in
        return in_array($user->role, ['komandan_regu', 'anggota', 'admin_project'], true);
    }

    /**
     * CHECK-OUT
     */
    public function checkout(User $user, Attendance $attendance): bool
    {
        // User hanya bisa check-out attendance miliknya sendiri
        if ($user->id !== $attendance->user_id) {
            return false;
        }

        // Hanya yang sudah check-in bisa check-out
        if (! $attendance->check_in_at) {
            return false;
        }

        return true;
    }

    /**
     * PATROL SCAN (untuk mobile posts)
     */
    public function patrolScan(User $user, Attendance $attendance): bool
    {
        // User hanya bisa scan attendance miliknya
        if ($user->id !== $attendance->user_id) {
            return false;
        }

        // Hanya bisa scan kalau sudah check-in
        if (! $attendance->check_in_at) {
            return false;
        }

        return true;
    }

    /**
     * VIEW PROGRESS (aggregate) for project
     * Dipakai untuk endpoint progress per assignment aktif.
     */
    public function progress(User $user): bool
    {
        // DEV bisa lihat semua
        if ($user->role === 'dev') {
            return true;
        }

        // Admin project & komandan regu (danru) bisa lihat progress di project miliknya
        if (in_array($user->role, ['admin_project', 'komandan_regu'], true)) {
            return (bool) $user->project_id;
        }

        // HO boleh melihat progress lintas project.
        if ($user->role === 'ho') {
            return (bool) $user->organization_id;
        }

        return false;
    }
}
