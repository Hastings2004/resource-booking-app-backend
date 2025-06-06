<?php

namespace App\Jobs;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendBookingNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $booking;
    protected $type;

    public function __construct(Booking $booking, string $type)
    {
        $this->booking = $booking;
        $this->type = $type;
    }

    public function handle()
    {
        try {
            // Send email notification
            // Mail::to($this->booking->user->email)->send(new BookingNotificationMail($this->booking, $this->type));

            // Send push notification
            // $this->sendPushNotification();

            // Create in-app notification
            $this->createInAppNotification();

        } catch (\Exception $e) {
            Log::error('Failed to send booking notification', [
                'booking_id' => $this->booking->id,
                'type' => $this->type,
                'error' => $e->getMessage()
            ]);
        }
    }

    private function createInAppNotification()
    {
        $messages = [
            'created' => 'Your booking request has been submitted and is awaiting approval.',
            'approved' => 'Your booking has been approved!',
            'rejected' => 'Your booking request has been rejected.',
            'cancelled' => 'Your booking has been cancelled.',
        ];

        $this->booking->user->notifications()->create([
            'type' => 'booking_status',
            'title' => 'Booking ' . ucfirst($this->type),
            'message' => $messages[$this->type] ?? 'Booking status updated.',
            'data' => [
                'booking_id' => $this->booking->id,
                'resource_name' => $this->booking->resource->name,
                'start_time' => $this->booking->start_time->toISOString(),
                'end_time' => $this->booking->end_time->toISOString(),
            ],
            'read_at' => null,
        ]);
    }
}