<?php

namespace App\Enums;

enum BookingStatus: string
{
    /** Just booked: the company still has to check the availability. */
    case Unconfirmed = 'unconfirmed';
    /** Available: the company confirmed it and the client got a second email. */
    case Confirmed = 'confirmed';
    /** Not possible, or cancelled. */
    case Cancelled = 'cancelled';
}
