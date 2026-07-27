<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Carbon\Carbon;

class AttendanceUpdated implements ShouldBroadcast, ShouldQueue
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    protected int $userId;
    protected ?int $assignmentId;
    protected string $status;
    protected Carbon $timestamp;
    protected array $extra;

    /**
     * Create a new event instance.
     */
    public function __construct(
        int $userId,
        string $status,
        Carbon $timestamp,
        ?int $assignmentId = null,
        array $extra = []
    ) {
        $this->userId = $userId;
        $this->assignmentId = $assignmentId;
        $this->status = $status;
        $this->timestamp = $timestamp;
        $this->extra = $extra;
    }

    /**
     * Channel tujuan broadcast
     */
    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('user.' . $this->userId)
        ];

        if (!is_null($this->assignmentId)) {
            $channels[] = new PrivateChannel('assignment.' . $this->assignmentId);
        }

        return $channels;
    }

    /**
     * Data yang dikirim ke client
     */
    public function broadcastWith(): array
    {
        return [
            'type' => $this->status,
            'userId' => $this->userId,
            'assignmentId' => $this->assignmentId,
            'timestamp' => $this->timestamp->toISOString(),
            'extra' => $this->extra,
        ];
    }

    /**
     * Nama event di frontend
     */
    public function broadcastAs(): string
    {
        return 'AttendanceUpdated';
    }

    /**
     * Queue khusus broadcast (biar tidak ganggu job lain)
     */
    public function broadcastQueue(): string
    {
        return 'broadcast';
    }
}