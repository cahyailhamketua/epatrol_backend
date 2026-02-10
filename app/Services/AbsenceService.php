<?php

namespace App\Services;

use App\Models\Absence;
use App\Models\Attendance;
use App\Models\Schedule;
use Illuminate\Support\Facades\DB;
use Exception;

class AbsenceService
{
    /**
     * Create absence with validation
     *
     * @param array $data
     * @return Absence
     * @throws Exception
     */
    public function createAbsence(array $data): Absence
    {
        // Check if user already has attendance on this date
        $existingAttendance = Attendance::where('project_id', $data['project_id'])
            ->where('user_id', $data['user_id'])
            ->where('date', $data['date'])
            ->exists();

        if ($existingAttendance) {
            throw new Exception('User sudah check-in hari ini. Tidak bisa membuat absence.');
        }

        // Check if absence already exists
        $existingAbsence = Absence::where('project_id', $data['project_id'])
            ->where('user_id', $data['user_id'])
            ->where('date', $data['date'])
            ->exists();

        if ($existingAbsence) {
            throw new Exception('Absence sudah ada untuk hari ini.');
        }

        return Absence::create($data);
    }

    /**
     * Approve absence
     *
     * @param Absence $absence
     * @param int $approvedById
     * @param string|null $notes
     * @return Absence
     */
    public function approveAbsence(Absence $absence, int $approvedById, ?string $notes = null): Absence
    {
        $absence->update([
            'status' => 'APPROVED',
            'approved_by' => $approvedById,
            'approved_at' => now(),
        ]);

        return $absence->refresh();
    }

    /**
     * Reject absence with reason
     *
     * @param Absence $absence
     * @param string $rejectionReason
     * @return Absence
     */
    public function rejectAbsence(Absence $absence, string $rejectionReason): Absence
    {
        $absence->update([
            'status' => 'REJECTED',
            'rejection_reason' => $rejectionReason,
        ]);

        return $absence->refresh();
    }

    /**
     * Check if user has approved absence on a specific date
     *
     * @param int $userId
     * @param int $projectId
     * @param string $date
     * @return bool
     */
    public function hasApprovedAbsence(int $userId, int $projectId, string $date): bool
    {
        return Absence::where('user_id', $userId)
            ->where('project_id', $projectId)
            ->where('date', $date)
            ->where('status', 'APPROVED')
            ->exists();
    }

    /**
     * Get absence by date
     *
     * @param int $userId
     * @param int $projectId
     * @param string $date
     * @return Absence|null
     */
    public function getAbsenceByDate(int $userId, int $projectId, string $date): ?Absence
    {
        return Absence::where('user_id', $userId)
            ->where('project_id', $projectId)
            ->where('date', $date)
            ->first();
    }

    /**
     * Get pending absences for approval
     *
     * @param int $projectId
     * @param string|null $date
     * @return mixed
     */
    public function getPendingAbsences(int $projectId, ?string $date = null)
    {
        $query = Absence::where('project_id', $projectId)
            ->where('status', 'PENDING')
            ->with(['user', 'assignment']);

        if ($date) {
            $query->where('date', $date);
        }

        return $query->orderBy('created_at', 'desc')->get();
    }
}
