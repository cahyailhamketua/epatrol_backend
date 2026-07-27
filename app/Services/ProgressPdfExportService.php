<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\AttendanceProgressSnapshot;
use App\Models\PatrolScan;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class ProgressPdfExportService
{
    /**
     * Generate PDF untuk progress attendance/assignment
     * Includes: post names, patrol points, scan photos, timestamps, scan user, notes
     */
    public function generateProgressPdf(Attendance $attendance, ?AttendanceProgressSnapshot $snapshot = null): string
    {
        try {
            if (!$snapshot) {
                $snapshot = AttendanceProgressSnapshot::where('attendance_id', $attendance->id)
                    ->latest('created_at')
                    ->first();
            }

            // Load attendance dengan relations
            $attendance->load('user', 'project.organization', 'assignment');

            // Get all scans untuk attendance ini
            $patrolScans = PatrolScan::where('attendance_id', $attendance->id)
                ->with(['qrCode.patrolPoint.post', 'photos', 'attendance.user'])
                ->orderBy('scan_time')
                ->get();

            // Group by post
            $scansByPost = $patrolScans->groupBy(function ($scan) {
                return $scan->qrCode?->patrolPoint?->post_id;
            });

            // Get project & organization info
            $project = $attendance->project;

            if (!$project) {
                Log::error('Project not found for attendance', ['attendance_id' => $attendance->id]);
                throw new \Exception('Project not found');
            }

            $organization = $project->organization;

            if (!$organization) {
                Log::error('Organization not found for project', ['project_id' => $project->id]);
                throw new \Exception('Organization not found');
            }

            // Prepare data untuk PDF
            $pdfData = [
                'attendance' => $attendance,
                'project' => $project,
                'project_name' => $project->name,
                'organization' => $organization,
                'snapshot' => $snapshot,
                'scans_by_post' => collect(),
                'generated_at' => now()

            ];

            // Process scans grouped by post
            foreach ($scansByPost as $postId => $scans) {
                $post = $scans->first()?->qrCode?->patrolPoint?->post;
                
                if (!$post) continue;

                $postData = [
                    'post_id' => $post->id,
                    'post_name' => $post->name ?? 'Unknown Post',
                    'post_type' => $post->type,
                    'patrol_points' => collect(),
                ];

                // Group scans by patrol point
                $scansByPoint = $scans->groupBy(function ($scan) {
                    return $scan->qrCode?->patrolPoint?->id;
                });

                foreach ($scansByPoint as $pointId => $pointScans) {
                    $patrolPoint = $pointScans->first()?->qrCode?->patrolPoint;
                    
                    if (!$patrolPoint) continue;

                    $pointData = [
                        'point_id' => $patrolPoint->id,
                        'point_name' => $patrolPoint->name ?? 'Unknown Point',
                        'sequence_order' => $patrolPoint->sequence_order,
                        'latitude' => (string) ($patrolPoint->latitude ?? ''),
                        'longitude' => (string) ($patrolPoint->longitude ?? ''),
                        'scans' => collect(),
                    ];

                    // Get all scans untuk point ini dengan photos
                    foreach ($pointScans as $scan) {
                        $scanData = [
                            'scan_id' => $scan->id,
                            'scan_time' => $scan->scan_time ? $scan->scan_time->setTimezone($project->timezone ?? 'Asia/Jakarta') : null,
                            'scan_user' => $scan->attendance->user?->full_name ?? 'Unknown User',
                            'project_name' => $project->name,
                            'note' => $scan->note ?? '-',
                            'photos' => collect(),
                        ];

                        // Get photo URLs
                        if ($scan->photos && $scan->photos->count() > 0) {
                            foreach ($scan->photos as $photo) {
                                $scanData['photos']->push([
                                    'id' => $photo->id,
                                    'url' => $this->getPhotoUrl($photo),
                                    'filename' => $photo->filename ?? 'photo_' . $photo->id . '.jpg',
                                ]);
                            }
                        }

                        $pointData['scans']->push($scanData);
                    }

                    $postData['patrol_points']->push($pointData);
                }

                $pdfData['scans_by_post']->push($postData);
            }

            // Generate PDF
            return $this->renderPdf($pdfData);
        } catch (\Exception $e) {
            Log::error('Generate progress PDF failed', [
                'attendance_id' => $attendance->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Generate PDF untuk danru progress (all static posts)
     */
 


/**
 * Generate PDF Danru - semua anggota + static + mobile
 */
// public function generateDanruProgressPdf(
//     Attendance $danruAttendance,
//     ?AttendanceProgressSnapshot $snapshot = null
// ): string {

//     $project = $danruAttendance->project;

//     // Ambil semua attendance dalam assignment / tanggal yang sama
//     $attendanceIds = Attendance::where('assignment_id', $danruAttendance->assignment_id)
//         ->where('project_id', $danruAttendance->project_id)
//         ->where('date', $danruAttendance->date)
//         ->pluck('id');

//     // Ambil semua scan semua anggota
//     $patrolScans = PatrolScan::whereIn('attendance_id', $attendanceIds)
//         ->with([
//             'qrCode.patrolPoint.post',
//             'photos',
//             'attendance.user',
//         ])
//         ->orderBy('scan_time')
//         ->get();

//     // Group by post
//     $scansByPost = $patrolScans->groupBy(function ($scan) {
//         return $scan->qrCode?->patrolPoint?->post_id;
//     });

//     $organization = $project->organization;

//     $pdfData = [
//         'attendance' => $danruAttendance,
//         'project' => $project,
//         'project_name' => $project->name,
//         'organization' => $organization,
//         'snapshot' => $snapshot,
//         'is_danru' => true,
//         'scans_by_post' => collect(),
//         'generated_at' => now(),
//     ];

//     // Process semua post (static + mobile)
//     foreach ($scansByPost as $postId => $scans) {

//         $post = $scans->first()?->qrCode?->patrolPoint?->post;

//         if (!$post) {
//             continue;
//         }

//         $postData = [
//             'post_id' => $post->id,
//             'post_name' => $post->name ?? 'Unknown Post',
//             'post_type' => $post->type ?? '-',
//             'patrol_points' => collect(),
//         ];

//         // Group by patrol point
//         $scansByPoint = $scans->groupBy(function ($scan) {
//             return $scan->qrCode?->patrolPoint?->id;
//         });

//         foreach ($scansByPoint as $pointId => $pointScans) {

//             $patrolPoint = $pointScans->first()?->qrCode?->patrolPoint;

//             if (!$patrolPoint) {
//                 continue;
//             }

//             $pointData = [
//                 'point_id' => $patrolPoint->id,
//                 'point_name' => $patrolPoint->name ?? 'Unknown Point',
//                 'sequence_order' => $patrolPoint->sequence_order,
//                 'latitude' => $patrolPoint->latitude,
//                 'longitude' => $patrolPoint->longitude,
//                 'scans' => collect(),
//             ];

//             foreach ($pointScans as $scan) {

//                 $scanData = [
//                     'scan_id' => $scan->id,

//                     'scan_time' => $scan->scan_time
//                         ? $scan->scan_time->setTimezone($project->timezone ?? 'Asia/Jakarta')
//                         : null,

//                     // PETUGAS YANG SCAN
//                     'scan_user' => $scan->attendance->user?->full_name ?? 'Unknown User',

//                     'note' => $scan->note ?? '-',

//                     'photos' => collect(),
//                 ];

//                 // FOTO
//                 if ($scan->photos && $scan->photos->count() > 0) {

//                     foreach ($scan->photos as $photo) {

//                         $scanData['photos']->push([
//                             'id' => $photo->id,
//                             'url' => $this->getPhotoUrl($photo),
//                             'filename' => $photo->filename ?? 'photo.jpg',
//                         ]);
//                     }
//                 }

//                 $pointData['scans']->push($scanData);
//             }

//             $postData['patrol_points']->push($pointData);
//         }

//         $pdfData['scans_by_post']->push($postData);
//     }

//     return $this->renderPdf($pdfData);
// }



/**
 * Convert photo ke base64 agar muncul di DomPDF
 */
private function getPhotoUrl($photo): ?string
{
    try {

        if (!$photo->photo) {
            return null;
        }

        $path = storage_path('app/public/' . $photo->photo);

        if (!file_exists($path)) {

            Log::warning('Photo file not found', [
                'path' => $path,
                'photo_id' => $photo->id,
            ]);

            return null;
        }

        $type = mime_content_type($path);

        $data = file_get_contents($path);

        return 'data:' . $type . ';base64,' . base64_encode($data);

    } catch (\Exception $e) {

        Log::error('Failed load photo for PDF', [
            'photo_id' => $photo->id,
            'error' => $e->getMessage(),
        ]);

        return null;
    }
}

    /**
     * Render PDF menggunakan DomPDF
     */
    private function renderPdf(array $data): string
    {
        $html = view('pdf.patrol-progress', $data)->render();
        
        $pdf = Pdf::loadHTML($html)
            ->setPaper('a4')
            ->setOption('margin-top', 10)
            ->setOption('margin-bottom', 10)
            ->setOption('margin-left', 10)
            ->setOption('margin-right', 10)
            ->setOption('dpi', 96)
            ->setOption('font-size', 10);

        return $pdf->stream(
            'progress-' . $data['attendance']->id . '-' . now()->format('Y-m-d-H-i-s') . '.pdf'
        );
    }

    /**
     * Get PDF untuk assignment session (antara dua timestamps)
     */
    public function generateSessionProgressPdf(
        Attendance $attendance,
        Carbon $sessionStart,
        Carbon $sessionEnd
    ): string {
        // Get scans dalam range ini
        $patrolScans = PatrolScan::where('attendance_id', $attendance->id)
            ->whereBetween('scan_time', [$sessionStart, $sessionEnd])
            ->with(['qrCode.patrolPoint.post', 'photos', 'attendance.user'])
            ->orderBy('scan_time')
            ->get();

        // Group by post
        $scansByPost = $patrolScans->groupBy(function ($scan) {
            return $scan->qrCode?->patrolPoint?->post_id;
        });

        $project = $attendance->project;

        $pdfData = [
            'attendance' => $attendance,
            'project' => $project,
            'project_name' => $project->name,
            'organization' => $project->organization,
            'session_start' => $sessionStart->setTimezone($project->timezone ?? 'Asia/Jakarta'),
            'session_end' => $sessionEnd->setTimezone($project->timezone ?? 'Asia/Jakarta'),
            'scans_by_post' => collect(),
            'generated_at' => now(),
        ];

        // Process scans
        foreach ($scansByPost as $postId => $scans) {
            $post = $scans->first()?->qrCode?->patrolPoint?->post;
            
            if (!$post) continue;

            $postData = [
                'post_id' => $post->id,
                'post_name' => $post->name,
                'post_type' => $post->type,
                'patrol_points' => collect(),
            ];

            $scansByPoint = $scans->groupBy(function ($scan) {
                return $scan->qrCode?->patrolPoint?->id;
            });

            foreach ($scansByPoint as $pointId => $pointScans) {
                $patrolPoint = $pointScans->first()?->qrCode?->patrolPoint;
                
                if (!$patrolPoint) continue;

                $pointData = [
                    'point_id' => $patrolPoint->id,
                    'point_name' => $patrolPoint->name,
                    'sequence_order' => $patrolPoint->sequence_order,
                    'latitude' => $patrolPoint->latitude,
                    'longitude' => $patrolPoint->longitude,
                    'scans' => collect(),
                ];

                foreach ($pointScans as $scan) {
                    $scanData = [
                        'scan_id' => $scan->id,
                        'scan_time' => $scan->scan_time->setTimezone($project->timezone ?? 'Asia/Jakarta'),
                        'scan_user' => $scan->attendance->user?->full_name,
                        'project_name' => $project->name,
                        'note' => $scan->note ?? '-',
                        'photos' => collect(),
                    ];

                    foreach ($scan->photos as $photo) {
                        $scanData['photos']->push([
                            'id' => $photo->id,
                            'url' => $this->getPhotoUrl($photo),
                        ]);
                    }

                    $pointData['scans']->push($scanData);
                }

                $postData['patrol_points']->push($pointData);
            }

            $pdfData['scans_by_post']->push($postData);
         }

        return $this->renderPdf($pdfData);
    }
}
