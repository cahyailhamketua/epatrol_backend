<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    /**
     * EDIT USER
     */
    public function update(User $auth, User $target): bool
    {

        if ($auth->role === 'dev') {
            return true;
        }

        if ($auth->role === 'ho') {
            return in_array($target->role, [
                'ho',
                'admin_project',
                'komandan_regu',
                'anggota'
            ]);
        }

        if ($auth->role === 'admin_project') {
            return in_array($target->role, [
                'admin_project',
                'komandan_regu',
                'anggota'
            ]);
        }

        return false;
    }

    public function viewAny(User $auth): bool
    {
        return in_array($auth->role, [
            'dev',
            'ho',
            'admin_project',
            'komandan_regu',
        ]);
    }

    public function view(User $auth, User $target): bool
    {
        //return true;
        return in_array($auth->role, [
            'dev',
            'ho',
            'admin_project',
            'komandan_regu',
        ]);
    }

    /**
     * NONAKTIFKAN USER (BUKAN DELETE)
     */
    public function deactivate(User $auth, User $target): bool
    {
        // ❌ Tidak boleh menonaktifkan diri sendiri
        if ($auth->id === $target->id) {
            return false;
        }

        // ❌ Sudah nonaktif
        if ($target->active === false) {
            return false;
        }

        if ($auth->role === 'dev') {
            return true;
        }

        if ($auth->role === 'ho') {
            return in_array($target->role, [
                'admin_project',
                'komandan_regu',
                'anggota'
            ]);
        }

        if ($auth->role === 'admin_project') {
            return in_array($target->role, [
                'komandan_regu',
                'anggota'
            ]);
        }

        return false;
    }

    /**
     * AKTIFKAN KEMBALI USER
     */
    public function activate(User $auth, User $target): bool
    {
        return in_array($auth->role, ['dev', 'ho']);
    }
}
