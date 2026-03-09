<?php

namespace App\Policies;

use App\Models\User;
use App\Models\PatrolScan;
use App\Models\Attendance;

class PatrolScanPolicy
{
    /**
     * Can user view all patrol scans
     */
    public function viewAny(User $user, ?Attendance $attendance = null): bool
    {
        // DEV dapat akses penuh
        if ($user->role === 'dev') {
            return true;
        }

        // Admin project dapat lihat scan di projectnya
        if ($user->role === 'admin_project') {
            return true;
        }

        // HO dapat lihat scan dalam organization
        if ($user->role === 'ho') {
            return true;
        }

        return false;
    }

    /**
     * Can user view specific patrol scan
     */
    public function view(User $user, PatrolScan $patrolScan): bool
    {
        $attendance = $patrolScan->attendance;

        // DEV dapat akses penuh
        if ($user->role === 'dev') {
            return true;
        }

        // HO dapat lihat scan dalam organization miliknya
        if ($user->role === 'ho') {
            return $user->organization_id === $attendance->project->organization_id;
        }

        // Admin project dapat lihat scan di project miliknya
        if ($user->role === 'admin_project') {
            return $user->project_id === $attendance->project_id;
        }

        // Komandan regu dapat lihat scan dalam project
        if ($user->role === 'komandan_regu') {
            return $user->project_id === $attendance->project_id;
        }

        // Anggota hanya dapat lihat scan milik sendiri
        if ($user->role === 'anggota') {
            return $user->id === $attendance->user_id;
        }

        return false;
    }

    /**
     * Can user create patrol scan (perform scan)
     * Only member atau komandan regu dapat scan
     */
    public function create(User $user): bool
    {
        return in_array($user->role, ['anggota', 'komandan_regu']);
    }

    /**
     * Can user perform scan for specific attendance
     */
    public function scanForAttendance(User $user, Attendance $attendance): bool
    {
        // DEV dapat scan untuk siapa saja
        if ($user->role === 'dev') {
            return true;
        }

        // Admin dapat scan untuk orang dalam project
        if ($user->role === 'admin_project') {
            return $user->project_id === $attendance->project_id;
        }

        // User hanya dapat scan milik sendiri
        return $user->id === $attendance->user_id;
    }

    /**
     * Can user add photo to patrol scan
     */
    public function addPhoto(User $user, PatrolScan $patrolScan): bool
    {
        $attendance = $patrolScan->attendance;

        // DEV dapat tambah foto
        if ($user->role === 'dev') {
            return true;
        }

        // Admin dapat tambah foto untuk attendance di project
        if ($user->role === 'admin_project') {
            return $user->project_id === $attendance->project_id;
        }

        // User hanya dapat tambah foto untuk scan milik sendiri
        if (in_array($user->role, ['anggota', 'komandan_regu'])) {
            return $user->id === $attendance->user_id;
        }

        return false;
    }

    /**
     * Can user delete photo from patrol scan
     */
    public function deletePhoto(User $user, PatrolScan $patrolScan): bool
    {
        $attendance = $patrolScan->attendance;

        // DEV dapat delete foto
        if ($user->role === 'dev') {
            return true;
        }

        // Admin dapat delete foto yang di-upload di project
        if ($user->role === 'admin_project') {
            return $user->project_id === $attendance->project_id;
        }

        // Hanya user yang melakukan scan dapat delete fotonya
        return $user->id === $attendance->user_id;
    }

    /**
     * Can user view scan details dan history
     */
    public function viewDetails(User $user, PatrolScan $patrolScan): bool
    {
        return $this->view($user, $patrolScan);
    }

    /**
     * Can user export scan report
     */
    public function export(User $user): bool
    {
        // Only admin roles can export
        return in_array($user->role, ['dev', 'ho', 'admin_project']);
    }
}
