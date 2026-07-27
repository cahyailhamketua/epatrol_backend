<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('user.{userId}', function ($user, $userId) {
    // Only allow the user to listen to their own channel
    return (int) $user->id === (int) $userId;
});

Broadcast::channel('assignment.{assignmentId}', function ($user, $assignmentId) {
    // You can add more complex logic here if needed (e.g., check assignment membership)
    return true; // Or implement assignment access check
});
